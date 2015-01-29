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
 * Form for unenrolled report preferences
 *
 * @package    gradereport_unenrolled
 * @copyright  2009 Nicolas Connault
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/formslib.php');

/**
 * First implementation of the preferences in the form of a moodleform.
 * TODO add "reset to site defaults" button
 */
class unenrolled_report_preferences_form extends moodleform {

    function definition() {
        global $USER, $CFG;

        $mform    =& $this->_form;
        $course   = $this->_customdata['course'];

        $context = context_course::instance($course->id);

        $canviewhidden = has_capability('moodle/grade:viewhidden', $context);

        $checkbox_default = array(GRADE_REPORT_PREFERENCE_DEFAULT => '*default*', 0 => get_string('no'), 1 => get_string('yes'));

        $advanced = array();
/// form definition with preferences defaults
//--------------------------------------------------------------------------------
        $preferences = array();

        // View capability is the lowest permission. Users with grade:manage or grade:edit must also have unenrolled:view
        if (has_capability('gradereport/unenrolled:view', $context)) {
            $preferences['prefgeneral']['studentsperpage'] = 'text';
            $preferences['prefgeneral']['repeatheaders'] = 'text';
            $preferences['prefgeneral']['aggregationposition'] = array(GRADE_REPORT_PREFERENCE_DEFAULT => '*default*',
                                                                       GRADE_REPORT_AGGREGATION_POSITION_FIRST => get_string('positionfirst', 'grades'),
                                                                       GRADE_REPORT_AGGREGATION_POSITION_LAST => get_string('positionlast', 'grades'));
            $preferences['prefshow']['showuserimage'] = $checkbox_default;
            $preferences['prefshow']['showactivityicons'] = $checkbox_default;
            $preferences['prefshow']['showweightedpercents'] = $checkbox_default;

            $advanced = array_merge($advanced, array('aggregationposition'));
        }


        foreach ($preferences as $group => $prefs) {
            $mform->addElement('header', $group, get_string($group, 'grades'));
            $mform->setExpanded($group);

            foreach ($prefs as $pref => $type) {
                // Detect and process dynamically numbered preferences
                if (preg_match('/([^[0-9]+)([0-9]+)/', $pref, $matches)) {
                    $lang_string = $matches[1];
                    $number = ' ' . $matches[2];
                } else {
                    $lang_string = $pref;
                    $number = null;
                }

                $full_pref  = 'grade_report_' . $pref;

                $pref_value = get_user_preferences($full_pref);

                $options = null;
                if (is_array($type)) {
                    $options = $type;
                    $type = 'select';
                    // MDL-11478
                    // get default aggregationposition from grade_settings
                    $course_value = null;
                    if (!empty($CFG->{$full_pref})) {
                        $course_value = grade_get_setting($course->id, $pref, $CFG->{$full_pref});
                    }

                    if ($pref == 'aggregationposition') {
                        if (!empty($options[$course_value])) {
                            $default = $options[$course_value];
                        } else {
                            $default = $options[$CFG->grade_aggregationposition];
                        }
                    } elseif (isset($options[$CFG->{$full_pref}])) {
                        $default = $options[$CFG->{$full_pref}];
                    } else {
                        $default = '';
                    }
                } else {
                    $default = $CFG->$full_pref;
                }

                // Replace the '*default*' value with the site default language string - 'default' might collide with custom language packs
                if (!is_null($options) AND isset($options[GRADE_REPORT_PREFERENCE_DEFAULT]) && $options[GRADE_REPORT_PREFERENCE_DEFAULT] == '*default*') {
                    $options[GRADE_REPORT_PREFERENCE_DEFAULT] = get_string('reportdefault', 'grades', $default);
                }

                $label = get_string($lang_string, 'grades') . $number;

                $mform->addElement($type, $full_pref, $label, $options);
                if ($lang_string != 'showuserimage' and $lang_string != 'integrate_quick_edit') {
                    $mform->addHelpButton($full_pref, $lang_string, 'grades');
                }
                $mform->setDefault($full_pref, $pref_value);
                $mform->setType($full_pref, PARAM_ALPHANUM);
            }
        }

        foreach($advanced as $name) {
            $mform->setAdvanced('grade_report_'.$name);
        }

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->setDefault('id', $course->id);

        $this->add_action_buttons();
    }

/// perform some extra moodle validation
    function validation($data, $files) {
        return parent::validation($data, $files);
    }
}

