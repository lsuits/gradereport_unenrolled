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
 * The gradebook unenrolled report
 *
 * @package   gradereport_unenrolled
 * @copyright 2007 Moodle Pty Ltd (http://moodle.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/user/renderer.php');
require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/report/unenrolled/lib.php');

$courseid      = required_param('id', PARAM_INT);        // course id
$page          = optional_param('page', 0, PARAM_INT);   // active page
$sortitemid    = optional_param('sortitemid', 0, PARAM_ALPHANUM); // sort by which grade item
$action        = optional_param('action', 0, PARAM_ALPHAEXT);
$move          = optional_param('move', 0, PARAM_INT);
$type          = optional_param('type', 0, PARAM_ALPHA);
$target        = optional_param('target', 0, PARAM_ALPHANUM);
$toggle        = optional_param('toggle', null, PARAM_INT);
$toggle_type   = optional_param('toggle_type', 0, PARAM_ALPHANUM);

$PAGE->set_url(new moodle_url('/grade/report/unenrolled/index.php', array('id'=>$courseid)));

// basic access checks
if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('nocourseid');
}
require_login($course);
$context = context_course::instance($course->id);

require_capability('gradereport/unenrolled:view', $context);
require_capability('moodle/grade:viewall', $context);

// return tracking object
$gpr = new grade_plugin_return(array('type'=>'report', 'plugin'=>'unenrolled', 'courseid'=>$courseid, 'page'=>$page));

// last selected report session tracking
if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}
$USER->grade_last_report[$course->id] = 'unenrolled';

$USER->gradeediting = array();
$USER->gradeediting[$course->id] = 0;
$buttons = '';

$gradeserror = array();

// Handle toggle change request
if (!is_null($toggle) && !empty($toggle_type)) {
    set_user_preferences(array('grade_report_show'.$toggle_type => $toggle));
}

//first make sure we have proper final grades - this must be done before constructing of the grade tree
grade_regrade_final_grades($courseid);

// Perform actions
if (!empty($target) && !empty($action) && confirm_sesskey()) {
    grade_report_unenrolled::do_process_action($target, $action);
}

$reportname = get_string('pluginname', 'gradereport_unenrolled');

// Print header
print_grade_page_head($COURSE->id, 'report', 'unenrolled', $reportname, false, $buttons);

//Initialise the unenrolled report object that produces the table
//the class grade_report_grader_ajax was removed as part of MDL-21562
$report = new grade_report_unenrolled($courseid, $gpr, $context, $page, $sortitemid);

// processing posted grades & feedback here
$warnings = array();

// final grades MUST be loaded after the processing
$report->load_users();
$numusers = count($report->numusers);
$report->load_final_grades();

//show warnings if any
foreach ($warnings as $warning) {
    echo $OUTPUT->notification($warning);
}

$studentsperpage = $report->get_students_per_page();
// Don't use paging if studentsperpage is empty or 0 at course AND site levels
if (!empty($studentsperpage)) {
    echo $OUTPUT->paging_bar($numusers, $report->page, $studentsperpage, $report->pbarurl);
}

$displayaverages = true;
if ($numusers == 0) {
    $displayaverages = false;
}

$reporthtml = $report->get_grade_table($displayaverages);

echo $reporthtml;

// prints paging bar at bottom for large pages
if (!empty($studentsperpage)) {
    echo $OUTPUT->paging_bar($numusers, $report->page, $studentsperpage, $report->pbarurl);
}
echo $OUTPUT->footer();
