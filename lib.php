<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Definition of the unenrolled report class
 *
 * @package   gradereport_unenrolled
 * @copyright 2007 Nicolas Connault
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->libdir.'/tablelib.php');

/**
 * Class providing an API for the unenrolled report building and displaying.
 * @uses grade_report
 * @copyright 2007 Nicolas Connault
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_report_unenrolled extends grade_report {
    /**
     * The final grades.
     * @var array $grades
     */
    public $grades;

    /**
     * Array of errors for bulk grades updating.
     * @var array $gradeserror
     */
    public $gradeserror = array();

    // SQL-RELATED

    /**
     * The id of the grade_item by which this report will be sorted.
     * @var int $sortitemid
     */
    public $sortitemid;

    /**
     * Sortorder used in the SQL selections.
     * @var int $sortorder
     */
    public $sortorder;

    /**
     * An SQL fragment affecting the search for users.
     * @var string $userselect
     */
    // public $userselect;

    /**
     * The bound params for $userselect
     * @var array $userselectparams
     */
   // public $userselectparams = array();

    /**
     * List of collapsed categories from user preference
     * @var array $collapsed
     */
    public $collapsed;

    /**
     * A count of the rows, used for css classes.
     * @var int $rowcount
     */
    public $rowcount = 0;

    /**
     * Capability check caching
     * @var boolean $canviewhidden
     */
    public $canviewhidden;

    /**
     * Length at which feedback will be truncated (to the nearest word) and an ellipsis be added.
     * TODO replace this by a report preference
     * @var int $feedback_trunc_length
     */
    protected $feedback_trunc_length = 50;

    protected $weightedtotals = array();

    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $courseid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     * @param int $page The current page being viewed (when report is paged)
     * @param int $sortitemid The id of the grade_item by which to sort the table
     */
    public function __construct($courseid, $gpr, $context, $page=null, $sortitemid=null) {
        global $CFG;
        parent::__construct($courseid, $gpr, $context, $page);

        $this->canviewhidden = has_capability('moodle/grade:viewhidden', context_course::instance($this->course->id));

        // load collapsed settings for this report
        if ($collapsed = get_user_preferences('grade_report_unenrolled_collapsed_categories')) {
            $this->collapsed = unserialize($collapsed);
        } else {
            $this->collapsed = array('aggregatesonly' => array(), 'gradesonly' => array());
        }

        if (empty($CFG->enableoutcomes)) {
            $nooutcomes = false;
        } else {
            $nooutcomes = get_user_preferences('grade_report_shownooutcomes');
        }

        // if user report preference set or site report setting set use it, otherwise use course or site setting
        $switch = $this->get_pref('aggregationposition');
        if ($switch == '') {
            $switch = grade_get_setting($this->courseid, 'aggregationposition', $CFG->grade_aggregationposition);
        }

        // Grab the grade_tree for this course
        $this->gtree = new grade_tree($this->courseid, true, $switch, $this->collapsed, $nooutcomes);

        $this->sortitemid = $sortitemid;

        // base url for sorting by first/last name

        $this->baseurl = new moodle_url('index.php', array('id' => $this->courseid));

        $this->pbarurl = new moodle_url('/grade/report/unenrolled/index.php', array('id' => $this->courseid));

        $this->setup_users();
        $this->setup_sortitemid();

        $this->overridecat = (bool)get_config('moodle', 'grade_overridecat');

    }

    /**
     * Processes the data sent by the form (grades and feedbacks).
     * Caller is responsible for all access control checks
     * @param array $data form submission (with magic quotes)
     * @return array empty array if success, array of warnings if something fails.
     */
    public function process_data($data) {
        global $DB;
        $warnings = array();

        // always initialize all arrays
        $queue = array();

        $this->load_users();
        $this->load_final_grades();

        // Were any changes made?
        $changedgrades = false;

        foreach ($data as $varname => $students) {

            $needsupdate = false;

            // skip, not a grade nor feedback
            if (strpos($varname, 'grade') === 0) {
                $datatype = 'grade';
            } else if (strpos($varname, 'feedback') === 0) {
                $datatype = 'feedback';
            } else {
                continue;
            }

            foreach ($students as $userid => $items) {
                $userid = clean_param($userid, PARAM_INT);
                foreach ($items as $itemid => $postedvalue) {
                    $itemid = clean_param($itemid, PARAM_INT);

                    // Was change requested?
                    $oldvalue = $this->grades[$userid][$itemid];
                    if ($datatype === 'grade') {
                        // If there was no grade and there still isn't
                        if (is_null($oldvalue->finalgrade) && $postedvalue == -1) {
                            // -1 means no grade
                            continue;
                        }

                        // If the grade item uses a custom scale
                        if (!empty($oldvalue->grade_item->scaleid)) {

                            if ((int)$oldvalue->finalgrade === (int)$postedvalue) {
                                continue;
                            }
                        } else {
                            // The grade item uses a numeric scale

                            // Format the finalgrade from the DB so that it matches the grade from the client
                            if ($postedvalue === format_float($oldvalue->finalgrade, $oldvalue->grade_item->get_decimals())) {
                                continue;
                            }
                        }

                        $changedgrades = true;

                    } else if ($datatype === 'feedback') {
                        // If quick grading is on, feedback needs to be compared without line breaks.
                        if ($this->get_pref('quickgrading')) {
                            $oldvalue->feedback = preg_replace("/\r\n|\r|\n/", "", $oldvalue->feedback);
                        }
                        if (($oldvalue->feedback === $postedvalue) or ($oldvalue->feedback === null and empty($postedvalue))) {
                            continue;
                        }
                    }

                    if (!$gradeitem = grade_item::fetch(array('id'=>$itemid, 'courseid'=>$this->courseid))) {
                        print_error('invalidgradeitemid');
                    }

                    // Pre-process grade
                    if ($datatype == 'grade') {
                        $feedback = false;
                        $feedbackformat = false;
                        if ($gradeitem->gradetype == GRADE_TYPE_SCALE) {
                            if ($postedvalue == -1) { // -1 means no grade
                                $finalgrade = null;
                            } else {
                                $finalgrade = $postedvalue;
                            }
                        } else {
                            $finalgrade = unformat_float($postedvalue);
                        }

                        $errorstr = '';
                        // Warn if the grade is out of bounds.
                        if (!is_null($finalgrade)) {
                            $bounded = $gradeitem->bounded_grade($finalgrade);
                            if ($bounded > $finalgrade) {
                                $errorstr = 'lessthanmin';
                            } else if ($bounded < $finalgrade) {
                                $errorstr = 'morethanmax';
                            }
                        }
                        if ($errorstr) {
                            $userfields = 'id, ' . get_all_user_name_fields(true);
                            $user = $DB->get_record('user', array('id' => $userid), $userfields);
                            $gradestr = new stdClass();
                            $gradestr->username = fullname($user);
                            $gradestr->itemname = $gradeitem->get_name();
                            $warnings[] = get_string($errorstr, 'grades', $gradestr);
                        }

                    } else if ($datatype == 'feedback') {
                        $finalgrade = false;
                        $trimmed = trim($postedvalue);
                        if (empty($trimmed)) {
                             $feedback = null;
                        } else {
                             $feedback = $postedvalue;
                        }
                    }

                    $oldgradegrade = new grade_grade(array('userid' => $userid, 'itemid' => $gradeitem->id), true);

                    $gradeitem->update_final_grade($userid, $finalgrade, 'gradebook', $feedback, FORMAT_MOODLE);

                    $gradegrade = new grade_grade(array('userid' => $userid, 'itemid' => $gradeitem->id), true);

                    if ($oldgradegrade->finalgrade != $gradegrade->finalgrade
                        or empty($oldgradegrade->overridden) != empty($gradegrade->overridden)
                    ) {
                        $gradegrade->grade_item = $gradeitem;
                        \core\event\user_graded::create_from_grade($gradegrade)->trigger();
                    }

                    // We can update feedback without reloading the grade item as it doesn't affect grade calculations
                    if ($datatype === 'feedback') {
                        $this->grades[$userid][$itemid]->feedback = $feedback;
                    }
                }
            }
        }

        if ($changedgrades) {
            // If a final grade was overriden reload grades so dependent grades like course total will be correct
            $this->grades = null;
        }

        return $warnings;
    }


    /**
     * Setting the sort order, this depends on last state
     * all this should be in the new table class that we might need to use
     * for displaying grades.
     */
    private function setup_sortitemid() {

        global $SESSION;

        if (!isset($SESSION->gradeuserreport)) {
            $SESSION->gradeuserreport = new stdClass();
        }

        if ($this->sortitemid) {
            if (!isset($SESSION->gradeuserreport->sort)) {
                if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                    $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                } else {
                    $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                }
            } else {
                // this is the first sort, i.e. by last name
                if (!isset($SESSION->gradeuserreport->sortitemid)) {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                    }
                } else if ($SESSION->gradeuserreport->sortitemid == $this->sortitemid) {
                    // same as last sort
                    if ($SESSION->gradeuserreport->sort == 'ASC') {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                    } else {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                    }
                } else {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                    }
                }
            }
            $SESSION->gradeuserreport->sortitemid = $this->sortitemid;
        } else {
            // not requesting sort, use last setting (for paging)

            if (isset($SESSION->gradeuserreport->sortitemid)) {
                $this->sortitemid = $SESSION->gradeuserreport->sortitemid;
            } else {
                $this->sortitemid = 'lastname';
            }

            if (isset($SESSION->gradeuserreport->sort)) {
                $this->sortorder = $SESSION->gradeuserreport->sort;
            } else {
                $this->sortorder = 'ASC';
            }
        }
    }

    /**
     * pulls out the userids of the users to be display, and sorts them
     */
    public function load_users() {
        global $CFG, $DB;

        if (!empty($this->users)) {
            return;
        }
        $this->setup_users();

        // Fields we need from the user table.
        $userfields = user_picture::fields('u', get_extra_user_fields($this->context));

        // We want to query both the current context and parent contexts.
        list($relatedctxsql, $relatedctxparams) = $DB->get_in_or_equal($this->context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx');

        // If the user has clicked one of the sort asc/desc arrows.
        if (is_numeric($this->sortitemid)) {
            $params = array_merge(array('gitemid' => $this->sortitemid), $this->userwheresql_params,
                    $relatedctxparams);

            $sort = "u.lastname $this->sortorder, u.firstname $this->sortorder";
        } else {
            $sortjoin = '';
            switch($this->sortitemid) {
                case 'lastname':
                    $sort = "u.lastname $this->sortorder, u.firstname $this->sortorder";
                    break;
                case 'firstname':
                    $sort = "u.firstname $this->sortorder, u.lastname $this->sortorder";
                    break;
                case 'email':
                    $sort = "u.email $this->sortorder";
                    break;
                case 'idnumber':
                default:
                    $sort = "u.idnumber $this->sortorder";
                    break;
            }

            $params = array_merge($this->userwheresql_params, $relatedctxparams);
        }

        // get currently enrolled userids
        $sql = "SELECT DISTINCT(ue.userid)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = $this->courseid
                    AND ue.timestart < NOW()
                    AND (ue.timeend = 0 OR ue.timeend > NOW())";
        $enrolled_user_ids = $DB->get_records_sql($sql);

        // get all users who match user criteria
        $sql = "SELECT DISTINCT(u.id) AS distinctusers, $userfields
                  FROM {user} u
                  INNER JOIN {grade_grades_history} g ON u.id = g.userid
                  INNER JOIN {grade_items} gi ON gi.id = g.itemid AND gi.courseid = $this->courseid
                  $this->userwheresql
                ORDER BY $sort";
        $all_users = $DB->get_records_sql($sql, $params);

        // separate enrolled users from selected users
        $unerolled_users = array_diff_key($all_users, $enrolled_user_ids);

        $this->numusers = $unerolled_users;
        $this->users = $unerolled_users; // @TODO - consider pagination preferences - $DB->get_records_sql($sql, $params);
        if (empty($this->users)) {
            $this->userselect = '';
            $this->users = array();
            $this->userselect_params = array();
        } else {
            list($usql, $uparams) = $DB->get_in_or_equal(array_keys($this->users), SQL_PARAMS_NAMED, 'usid0');
            $this->userselect = "AND g.userid $usql";
            $this->userselect_params = $uparams;

            $coursecontext = $this->context->get_course_context(true);
            $time = time();
            $params = array_merge($uparams, array('courseid' => $coursecontext->instanceid, 'now1' => $time, 'now2' => $time));
        }
        return $this->users;
    }

    /**
     * we supply the userids in this query, and get all the grades
     * pulls out all the grades, this does not need to worry about paging
     */
    public function load_final_grades() {
        global $CFG, $DB;

        if (!empty($this->grades)) {
            return;
        }

        if (empty($this->users)) {
            return;
        }

        // please note that we must fetch all grade_grades fields if we want to construct grade_grade object from it!
        $params = array_merge(array('courseid'=>$this->courseid), $this->userselect_params);
        $sql = "SELECT g.*
                  FROM {grade_items} gi
                       INNER JOIN {grade_grades_history} g ON gi.id = g.itemid
                 WHERE gi.courseid = :courseid {$this->userselect}";

        $userids = array_keys($this->users);

        if ($grades = $DB->get_records_sql($sql, $params)) {
            foreach ($grades as $graderec) {
                if (in_array($graderec->userid, $userids) and array_key_exists($graderec->itemid, $this->gtree->get_items())) { // some items may not be present!!
                    $this->grades[$graderec->userid][$graderec->itemid] = new grade_grade($graderec, false);
                    $this->grades[$graderec->userid][$graderec->itemid]->grade_item = $this->gtree->get_item($graderec->itemid); // db caching
                }
            }
        }

        // prefil grades that do not exist yet
        foreach ($userids as $userid) {
            foreach ($this->gtree->get_items() as $itemid => $unused) {
                if (!isset($this->grades[$userid][$itemid])) {
                    $this->grades[$userid][$itemid] = new grade_grade();
                    $this->grades[$userid][$itemid]->itemid = $itemid;
                    $this->grades[$userid][$itemid]->userid = $userid;
                    $this->grades[$userid][$itemid]->grade_item = $this->gtree->get_item($itemid); // db caching
                }
            }
        }
    }

    /**
     * Gets html toggle
     * @deprecated since Moodle 2.4 as it appears not to be used any more.
     */
    public function get_toggles_html() {
        throw new coding_exception('get_toggles_html() can not be used any more');
    }

    /**
     * Prints html toggle
     * @deprecated since 2.4 as it appears not to be used any more.
     * @param unknown $type
     */
    public function print_toggle($type) {
        throw new coding_exception('print_toggle() can not be used any more');
    }

    /**
     * Builds and returns the rows that will make up the left part of the unenrolled report
     * This consists of student names and icons, links to user reports and id numbers, as well
     * as header cells for these columns. It also includes the fillers required for the
     * categories displayed on the right side of the report.
     * @param boolean $displayaverages whether to display average rows in the table
     * @return array Array of html_table_row objects
     */
    public function get_left_rows($displayaverages) {
        global $CFG, $USER, $OUTPUT;

        $rows = array();

        $showuserimage = $this->get_pref('showuserimage');

        $strfeedback  = $this->get_lang_string("feedback");
        $strgrade     = $this->get_lang_string('grade');

        $extrafields = get_extra_user_fields($this->context);

        $arrows = $this->get_sort_arrows($extrafields);

        $colspan = 1;
        $colspan += count($extrafields);

        $levels = count($this->gtree->levels) - 1;

        for ($i = 0; $i < $levels; $i++) {
            $fillercell = new html_table_cell();
            $fillercell->attributes['class'] = 'fixedcolumn cell topleft';
            $fillercell->text = ' ';
            $fillercell->colspan = $colspan;
            $row = new html_table_row(array($fillercell));
            $rows[] = $row;
        }

        $headerrow = new html_table_row();
        $headerrow->attributes['class'] = 'heading';

        $studentheader = new html_table_cell();
        $studentheader->attributes['class'] = 'header';
        $studentheader->scope = 'col';
        $studentheader->header = true;
        $studentheader->id = 'studentheader';
        $studentheader->text = $arrows['studentname'];

        $headerrow->cells[] = $studentheader;

        foreach ($extrafields as $field) {
            $fieldheader = new html_table_cell();
            $fieldheader->attributes['class'] = 'header userfield user' . $field;
            $fieldheader->scope = 'col';
            $fieldheader->header = true;
            $fieldheader->text = $arrows[$field];

            $headerrow->cells[] = $fieldheader;
        }

        $rows[] = $headerrow;

        $rows = $this->get_left_icons_row($rows, $colspan);

        $rowclasses = array('even', 'odd');

        if($this->get_pref('repeatheaders') > 0) {
            $repeat = $this->get_pref('repeatheaders');
        } else {
            $repeat = $this->get_pref('repeatheaders') + 100000;
        }

        // Repeat filler
        $repeatentries = unserialize(serialize($rows));
        array_shift($repeatentries);

        $suspendedstring = null;
        foreach ($this->users as $userid => $user) {
            if ($this->rowcount > 0 and $this->rowcount % $repeat == 0) {
                $rows = array_merge($rows, unserialize(serialize($repeatentries)));
            }

            $this->rowcount++;

            $userrow = new html_table_row();
            $userrow->id = 'fixed_user_'.$userid;
            $userrow->attributes['class'] = $rowclasses[$this->rowcount % 2];


            $usercell = new html_table_cell();
            $usercell->attributes['class'] = 'user';

            $usercell->header = true;
            $usercell->scope = 'row';

            if ($showuserimage) {
                $usercell->text = $OUTPUT->user_picture($user);
            }

            if (isset($CFG->grade_report_nameswap) && $CFG->grade_report_nameswap && !empty($user->alternatename)) {
                $usercell->text .= html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id)), $user->alternatename . ' (' . $user->firstname . ') ' . $user->lastname);
            } else if (!empty($user->alternatename)) {
                $usercell->text .= html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id)), $user->firstname . ' (' . $user->alternatename . ') ' . $user->lastname);
            } else {
                $usercell->text .= html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id)), fullname($user));
            }

            $userrow->cells[] = $usercell;

            foreach ($extrafields as $field) {
                $fieldcell = new html_table_cell();
                $fieldcell->attributes['class'] = 'header userfield user' . $field;
                $fieldcell->header = true;
                $fieldcell->scope = 'row';
                $fieldcell->text = $user->{$field};
                $userrow->cells[] = $fieldcell;
            }

            $rows[] = $userrow;
        }

        return $rows;
    }

    /**
     * Builds and returns the rows that will make up the right part of the unenrolled report
     * @param boolean $displayaverages whether to display average rows in the table
     * @return array Array of html_table_row objects
     */
    public function get_right_rows($displayaverages) {
        global $CFG, $COURSE, $USER, $OUTPUT, $DB, $PAGE;

        $rows = array();
        $this->rowcount = 0;
        $numrows = count($this->gtree->get_levels());
        $numusers = count($this->users);
        $gradetabindex = 1;
        $columnstounset = array();
        $strgrade = $this->get_lang_string('grade');
        $strfeedback  = $this->get_lang_string("feedback");

        $jsarguments = array(
            'id'        => '#fixed_column',
            'cfg'       => array('ajaxenabled'=>false),
            'items'     => array(),
            'users'     => array(),
            'feedback'  => array()
        );
        $jsscales = array();

        $render_percents = $this->get_pref('showweightedpercents');

        foreach ($this->gtree->get_levels() as $key => $row) {
            $headingrow = new html_table_row();
            $headingrow->attributes['class'] = 'heading_name_row';

            foreach ($row as $columnkey => $element) {
                $sortlink = clone($this->baseurl);
                if (isset($element['object']->id)) {
                    $sortlink->param('sortitemid', $element['object']->id);
                }

                $eid    = $element['eid'];
                $object = $element['object'];
                $type   = $element['type'];
                $categorystate = @$element['categorystate'];

                if (!empty($element['colspan'])) {
                    $colspan = $element['colspan'];
                } else {
                    $colspan = 1;
                }

                if (!empty($element['depth'])) {
                    $catlevel = 'catlevel'.$element['depth'];
                } else {
                    $catlevel = '';
                }

                // Element is a filler
                if ($type == 'filler' or $type == 'fillerfirst' or $type == 'fillerlast') {
                    $fillercell = new html_table_cell();
                    $fillercell->attributes['class'] = $type . ' ' . $catlevel;
                    $fillercell->colspan = $colspan;
                    $fillercell->text = '&nbsp;';
                    $fillercell->header = true;
                    $fillercell->scope = 'col';
                    $headingrow->cells[] = $fillercell;
                } else if ($type == 'category') {
                    // Element is a category
                    $categorycell = new html_table_cell();
                    $categorycell->attributes['class'] = 'category ' . $catlevel;
                    $categorycell->colspan = $colspan;
                    $categorycell->text = shorten_text($element['object']->get_name());
                    $categorycell->text .= $this->get_collapsing_icon($element);
                    $categorycell->header = true;
                    $categorycell->scope = 'col';

                    $headingrow->cells[] = $categorycell;
                } else {
                    // Element is a grade_item

                    $is_category_item = (
                        $element['object']->itemtype == 'course' or
                        $element['object']->itemtype == 'category'
                    );

                   $headerlink = $this->gtree->get_element_header($element, true, $this->get_pref('showactivityicons'), false);

                    $itemcell = new html_table_cell();
                    $itemcell->attributes['class'] = $type . ' ' . $catlevel . ' highlightable'. ' i'. $element['object']->id;

                    $percents = $render_percents ?
                        $this->get_weighted_percents($element['object']) . '<br />': '';

                    if ($element['object']->is_hidden()) {
                        $itemcell->attributes['class'] .= ' dimmed_text';
                    }

                    $itemcell->colspan = $colspan;
                    $itemcell->text = $percents;
                    $itemcell->text .= shorten_text($headerlink);
                    $itemcell->header = true;
                    $itemcell->scope = 'col';

                    $headingrow->cells[] = $itemcell;
                }
            }
            $rows[] = $headingrow;
        }

        $rows = $this->get_right_icons_row($rows);

        // Preload scale objects for items with a scaleid and initialize tab indices
        $scaleslist = array();
        $tabindices = array();

        foreach ($this->gtree->get_items() as $itemid => $item) {
            $scale = null;
            if (!empty($item->scaleid)) {
                $scaleslist[] = $item->scaleid;
                $jsarguments['items'][$itemid] = array('id'=>$itemid, 'name'=>$item->get_name(true), 'type'=>'scale', 'scale'=>$item->scaleid, 'decimals'=>$item->get_decimals());
            } else {
                $jsarguments['items'][$itemid] = array('id'=>$itemid, 'name'=>$item->get_name(true), 'type'=>'value', 'scale'=>false, 'decimals'=>$item->get_decimals());
            }
            $tabindices[$item->id]['grade'] = $gradetabindex;
            $tabindices[$item->id]['feedback'] = $gradetabindex + $numusers;
            $gradetabindex += $numusers * 2;
        }
        $scalesarray = array();

        if (!empty($scaleslist)) {
            $scalesarray = $DB->get_records_list('scale', 'id', $scaleslist);
        }
        $jsscales = $scalesarray;

        $rowclasses = array('even', 'odd');

        if($this->get_pref('repeatheaders') > 0) {
            $repeat = $this->get_pref('repeatheaders');
        } else {
            $repeat = $this->get_pref('repeatheaders') + 100000;
        }

        // Headers to repeat
        $repeatentries = unserialize(serialize($rows));
        array_shift($repeatentries);


        foreach ($this->users as $userid => $user) {

            if ($this->rowcount > 0 and $this->rowcount % $repeat == 0) {
                $rows = array_merge($rows, $repeatentries);
            }

            if ($this->canviewhidden) {
                $altered = array();
                $unknown = array();
            } else {
                $hidingaffected = grade_grade::get_hiding_affected($this->grades[$userid], $this->gtree->get_items());
                $altered = $hidingaffected['altered'];
                $unknown = $hidingaffected['unknown'];
                unset($hidingaffected);
            }

            $this->rowcount++;
            $itemrow = new html_table_row();
            $itemrow->id = 'user_'.$userid;
            $itemrow->attributes['class'] = $rowclasses[$this->rowcount % 2];

            $jsarguments['users'][$userid] = fullname($user);

            foreach ($this->gtree->items as $itemid => $unused) {
                $item =& $this->gtree->items[$itemid];
                $grade = $this->grades[$userid][$item->id];

                $itemcell = new html_table_cell();

                $itemcell->id = 'u'.$userid.'i'.$itemid;

                // Get the decimal points preference for this item
                $decimalpoints = $item->get_decimals();

                if (in_array($itemid, $unknown)) {
                    $gradeval = null;
                } else if (array_key_exists($itemid, $altered)) {
                    $gradeval = $altered[$itemid];
                } else {
                    $gradeval = $grade->finalgrade;
                }
                if (!empty($grade->finalgrade)) {
                    $gradevalforjs = null;
                    if ($item->scaleid && !empty($scalesarray[$item->scaleid])) {
                        $gradevalforjs = (int)$gradeval;
                    } else {
                        $gradevalforjs = format_float($gradeval, $decimalpoints);
                    }
                    $jsarguments['grades'][] = array('user'=>$userid, 'item'=>$itemid, 'grade'=>$gradevalforjs);
                }

                // MDL-11274
                // Hide grades in the unenrolled report if the current unenrolled doesn't have 'moodle/grade:viewhidden'
                if (!$this->canviewhidden and $grade->is_hidden()) {
                    if (!empty($CFG->grade_hiddenasdate) and $grade->get_datesubmitted() and !$item->is_category_item() and !$item->is_course_item()) {
                        // the problem here is that we do not have the time when grade value was modified, 'timemodified' is general modification date for grade_grades records
                        $itemcell->text = html_writer::tag('span', userdate($grade->get_datesubmitted(), get_string('strftimedatetimeshort')), array('class'=>'datesubmitted'));
                    } else {
                        $itemcell->text = '-';
                    }
                    $itemrow->cells[] = $itemcell;
                    continue;
                }

                // emulate grade element
                $eid = $this->gtree->get_grade_eid($grade);
                $element = array('eid'=>$eid, 'object'=>$grade, 'type'=>'grade');

                $itemcell->attributes['class'] .= ' grade i'.$itemid;
                if ($item->is_category_item()) {
                    $itemcell->attributes['class'] .= ' cat';
                }
                if ($item->is_course_item()) {
                    $itemcell->attributes['class'] .= ' course';
                }
                if ($grade->is_overridden()) {
                    $itemcell->attributes['class'] .= ' overridden';
                }


                if ($grade->is_excluded()) {
                    $itemcell->attributes['class'] .= ' excluded';
                }

                if (!empty($grade->feedback)) {
                    //should we be truncating feedback? ie $short_feedback = shorten_text($feedback, $this->feedback_trunc_length);
                    $jsarguments['feedback'][] = array('user'=>$userid, 'item'=>$itemid, 'content'=>wordwrap(trim(format_string($grade->feedback, $grade->feedbackformat)),
                            34, '<br/ >'));
                }

                if ($grade->is_excluded()) {
                    $itemcell->text .= html_writer::tag('span', get_string('excluded', 'grades'), array('class'=>'excludedfloater'));
                }

                if ($grade->is_overridden() && !$grade->is_excluded()) {
                    $itemcell->text .= html_writer::tag('span', get_string('overridden', 'grades'), array('class'=>'excludedfloater'));
                    $itemcell->text .= '<br />';
                }

                $hidden = '';
                if ($grade->is_hidden()) {
                    $hidden = ' hidden ';
                }

                $gradepass = ' gradefail ';
                if ($grade->is_passed($item)) {
                    $gradepass = ' gradepass ';
                } else if (is_null($grade->is_passed($item))) {
                    $gradepass = '';
                }

                // Not editing
                $gradedisplaytype = $item->get_displaytype();

                if ($item->scaleid && !empty($scalesarray[$item->scaleid])) {
                    $itemcell->attributes['class'] .= ' grade_type_scale';
                } else if ($item->gradetype != GRADE_TYPE_TEXT) {
                    $itemcell->attributes['class'] .= ' grade_type_text';
                }

                if ($item->needsupdate) {
                    $itemcell->text .= html_writer::tag('span', get_string('error'), array('class'=>"gradingerror$hidden$gradepass"));
                } else {
                    $itemcell->text .= html_writer::tag('span', grade_format_gradevalue($gradeval, $item, true, $gradedisplaytype, null),
                            array('class'=>"gradevalue$hidden$gradepass"));
                    if ($this->get_pref('showanalysisicon')) {
                        $itemcell->text .= $this->gtree->get_grade_analysis_icon($grade);
                    }
                }

                if (!empty($this->gradeserror[$item->id][$userid])) {
                    $itemcell->text .= $this->gradeserror[$item->id][$userid];
                }

                $itemrow->cells[] = $itemcell;
            }
            $rows[] = $itemrow;
        }

        $jsarguments['cfg']['courseid'] =  $this->courseid;
        $jsarguments['cfg']['showquickfeedback'] =  (bool)$this->get_pref('showquickfeedback');

        $module = array(
            'name'      => 'gradereport_unenrolled',
            'fullpath'  => '/grade/report/unenrolled/module.js',
            'requires'  => array('base', 'dom', 'event', 'event-mouseenter', 'event-key', 'io-queue', 'json-parse', 'overlay')
        );
        $PAGE->requires->js_init_call('M.gradereport_unenrolled.init_report', $jsarguments, false, $module);
        $PAGE->requires->strings_for_js(array('addfeedback', 'feedback', 'grade'), 'grades');
        $PAGE->requires->strings_for_js(array('ajaxchoosescale', 'ajaxclicktoclose', 'ajaxerror', 'ajaxfailedupdate', 'ajaxfieldchanged'), 'gradereport_unenrolled');

        return $rows;
    }

    /**
     * Arranges the rows of data in one or two tables, and returns the output of
     * these tables in HTML
     * @param boolean $displayaverages whether to display average rows in the table
     * @return string HTML
     */
    public function get_grade_table($displayaverages = false) {
        global $OUTPUT;

        $leftrows = $this->get_left_rows($displayaverages);
        $rightrows = $this->get_right_rows($displayaverages);

        $html = '';
            $fulltable = new html_table();
            $fulltable->attributes['class'] = 'gradestable flexible boxaligncenter generaltable';
            $fulltable->id = 'user-grades';

            // Extract rows from each side (left and right) and collate them into one row each
            foreach ($leftrows as $key => $row) {
                $row->cells = array_merge($row->cells, $rightrows[$key]->cells);
                $fulltable->data[] = $row;
            }
            $html .= html_writer::table($fulltable);
        return $OUTPUT->container($html, 'gradeparent');
    }

    /**
     * Builds and return the row of icons for the left side of the report.
     * It only has one cell that says "Controls"
     * @param array $rows The Array of rows for the left part of the report
     * @param int $colspan The number of columns this cell has to span
     * @return array Array of rows for the left part of the report
     */
    public function get_left_icons_row($rows=array(), $colspan=1) {
        global $USER;
        return $rows;
    }

    /**
     * Builds and return the row of icons when editing is on, for the right part of the unenrolled report.
     * @param array $rows The Array of rows for the right part of the report
     * @return array Array of rows for the right part of the report
     */
    public function get_right_icons_row($rows=array()) {
        global $USER;
        return $rows;
    }

    /**
     * Given a grade_category, grade_item or grade_grade, this function
     * figures out the state of the object and builds then returns a div
     * with the icons needed for the unenrolled report.
     *
     * @param array $element
     * @return string HTML
     */
    protected function get_icons($element) {
        global $CFG, $USER, $OUTPUT;

        if (!$USER->gradeediting[$this->courseid]) {
            return '<div class="grade_icons" />';
        }

        // Init all icons
        $editicon = '';
        $editable = false;
        if ($element['type'] == 'grade') {
            $item = $element['object']->grade_item;
        }

        if ($element['type'] != 'categoryitem' && $element['type'] != 'courseitem' &&$editable) {
            $editicon = $this->gtree->get_edit_icon($element, $this->gpr);
        }

        $editcalculationicon = '';
        $showhideicon        = '';
        $lockunlockicon      = '';

        $gradeanalysisicon   = '';
        if ($this->get_pref('showanalysisicon') && $element['type'] == 'grade') {
            $gradeanalysisicon .= $this->gtree->get_grade_analysis_icon($element['object']);
        }

        return $OUTPUT->container($editicon.$editcalculationicon.$showhideicon.$lockunlockicon.$gradeanalysisicon, 'grade_icons');
    }

    /**
     * Given a category element returns collapsing +/- icon if available
     * @param object $element
     * @return string HTML
     */
    protected function get_collapsing_icon($element) {
        global $OUTPUT;

        $icon = '';
        // If object is a category, display expand/contract icon
        if ($element['type'] == 'category') {
            // Load language strings
            $strswitchminus = $this->get_lang_string('aggregatesonly', 'grades');
            $strswitchplus  = $this->get_lang_string('gradesonly', 'grades');
            $strswitchwhole = $this->get_lang_string('fullmode', 'grades');

            $url = new moodle_url($this->gpr->get_return_url(null, array('target'=>$element['eid'], 'sesskey'=>sesskey())));

            if (in_array($element['object']->id, $this->collapsed['aggregatesonly'])) {
                $url->param('action', 'switch_plus');
                $icon = $OUTPUT->action_icon($url, new pix_icon('t/switch_plus', $strswitchplus));

            } else if (in_array($element['object']->id, $this->collapsed['gradesonly'])) {
                $url->param('action', 'switch_whole');
                $icon = $OUTPUT->action_icon($url, new pix_icon('t/switch_whole', $strswitchwhole));

            } else {
                $url->param('action', 'switch_minus');
                $icon = $OUTPUT->action_icon($url, new pix_icon('t/switch_minus', $strswitchminus));
            }
        }
        return $icon;
    }

    /**
     * Processes a single action against a category, grade_item or grade.
     * @param string $target eid ({type}{id}, e.g. c4 for category4)
     * @param string $action Which action to take (edit, delete etc...)
     * @return
     */
    public function process_action($target, $action) {
        return self::do_process_action($target, $action);
    }

    /**
     * Processes a single action against a category, grade_item or grade.
     * @param string $target eid ({type}{id}, e.g. c4 for category4)
     * @param string $action Which action to take (edit, delete etc...)
     * @return
     */
    public static function do_process_action($target, $action) {
        // TODO: this code should be in some grade_tree static method
        $targettype = substr($target, 0, 1);
        $targetid = substr($target, 1);
        // TODO: end

        if ($collapsed = get_user_preferences('grade_report_unenrolled_collapsed_categories')) {
            $collapsed = unserialize($collapsed);
        } else {
            $collapsed = array('aggregatesonly' => array(), 'gradesonly' => array());
        }

        switch ($action) {
            case 'switch_minus': // Add category to array of aggregatesonly
                if (!in_array($targetid, $collapsed['aggregatesonly'])) {
                    $collapsed['aggregatesonly'][] = $targetid;
                    set_user_preference('grade_report_unenrolled_collapsed_categories', serialize($collapsed));
                }
                break;

            case 'switch_plus': // Remove category from array of aggregatesonly, and add it to array of gradesonly
                $key = array_search($targetid, $collapsed['aggregatesonly']);
                if ($key !== false) {
                    unset($collapsed['aggregatesonly'][$key]);
                }
                if (!in_array($targetid, $collapsed['gradesonly'])) {
                    $collapsed['gradesonly'][] = $targetid;
                }
                set_user_preference('grade_report_unenrolled_collapsed_categories', serialize($collapsed));
                break;
            case 'switch_whole': // Remove the category from the array of collapsed cats
                $key = array_search($targetid, $collapsed['gradesonly']);
                if ($key !== false) {
                    unset($collapsed['gradesonly'][$key]);
                    set_user_preference('grade_report_unenrolled_collapsed_categories', serialize($collapsed));
                }

                break;
            default:
                break;
        }

        return true;
    }

    /**
     * Refactored function for generating HTML of sorting links with matching arrows.
     * Returns an array with 'studentname' and 'idnumber' as keys, with HTML ready
     * to inject into a table header cell.
     * @param array $extrafields Array of extra fields being displayed, such as
     *   user idnumber
     * @return array An associative array of HTML sorting links+arrows
     */
    public function get_sort_arrows(array $extrafields = array()) {
        global $OUTPUT;
        $arrows = array();

        $strsortasc   = $this->get_lang_string('sortasc', 'grades');
        $strsortdesc  = $this->get_lang_string('sortdesc', 'grades');
        $strfirstname = $this->get_lang_string('firstname');
        $strlastname  = $this->get_lang_string('lastname');
        $iconasc = $OUTPUT->pix_icon('t/sort_asc', $strsortasc, '', array('class' => 'iconsmall sorticon'));
        $icondesc = $OUTPUT->pix_icon('t/sort_desc', $strsortdesc, '', array('class' => 'iconsmall sorticon'));

        $firstlink = html_writer::link(new moodle_url($this->baseurl, array('sortitemid'=>'firstname')), $strfirstname);
        $lastlink = html_writer::link(new moodle_url($this->baseurl, array('sortitemid'=>'lastname')), $strlastname);

        $arrows['studentname'] = $firstlink;

        if ($this->sortitemid === 'firstname') {
            if ($this->sortorder == 'ASC') {
                $arrows['studentname'] .= $iconasc;
            } else {
                $arrows['studentname'] .= $icondesc;
            }
        }

        $arrows['studentname'] .= ' ' . $lastlink;

        if ($this->sortitemid === 'lastname') {
            if ($this->sortorder == 'ASC') {
                $arrows['studentname'] .= $iconasc;
            } else {
                $arrows['studentname'] .= $icondesc;
            }
        }

        foreach ($extrafields as $field) {
            $fieldlink = html_writer::link(new moodle_url($this->baseurl,
                    array('sortitemid'=>$field)), get_user_field_name($field));
            $arrows[$field] = $fieldlink;

            if ($field == $this->sortitemid) {
                if ($this->sortorder == 'ASC') {
                    $arrows[$field] .= $iconasc;
                } else {
                    $arrows[$field] .= $icondesc;
                }
            }
        }

        return $arrows;
    }

    public function get_weighted_percents($item) {
        $parent = $item->get_parent_category();

        if (!$parent or $item->is_course_item()) {
            return '';
        }

        if ($item->is_category_item()) {
            $parent = $parent->get_parent_category();
        }

        if (!$parent) {
            return '';
        }

        $determine_weight = function($item) use ($parent) {
            if ($parent->is_extracredit_used()) {
                $discard_weight = (
                    ($parent->aggregation != GRADE_AGGREGATE_WEIGHTED_MEAN &&
                    $item->aggregationcoef > 0) or $item->aggregationcoef < 0 or $item->gradetype == 0 or $item->gradetype == 3
                );

                if ($discard_weight) return 0;
            }

            switch ($parent->aggregation) {
                case GRADE_AGGREGATE_WEIGHTED_MEAN:
                    return $item->aggregationcoef;
                case GRADE_AGGREGATE_WEIGHTED_MEAN2:
                    return $item->grademax - $item->grademin;
                case GRADE_AGGREGATE_SUM:
                    return $item->grademax;
                default: return false;
            }
        };

        $evaluated = $determine_weight($item);

        if (empty($evaluated)) {
            return '';
        }

        if (!isset($this->weightedtotals[$parent->id])) {
            $total_weight = 0;

            $grade_items = $parent->get_children();
            foreach ($grade_items as $gid => $grade_item) {
                if ($grade_item['type'] == 'category') {
                    $item = $grade_item['object']->get_grade_item();
                } else {
                    $item = $grade_item['object'];
                }

                $total_weight += $determine_weight($item);
            }

            $this->weightedtotals[$parent->id] = $total_weight;
        }

        $decimals = $parent->get_grade_item()->get_decimals();

        //if all weights are zero, we get div by 0 warnings...
        $computed = $this->weightedtotals[$parent->id] == 0 ? 0 : $evaluated / $this->weightedtotals[$parent->id];

        return ' (' . format_float($computed * 100, $decimals) . '%) ';
    }

}

