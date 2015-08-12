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
 * Defines site config settings for the unenrolled report
 *
 * @package    gradereport_unenrolled
 * @copyright  2007 Moodle Pty Ltd (http://moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {

    $strinherit             = get_string('inherit', 'grades');
    $strpercentage          = get_string('percentage', 'grades');
    $strreal                = get_string('real', 'grades');
    $strletter              = get_string('letter', 'grades');

    /// Add settings for this module to the $settings object (it's already defined)
    $settings->add(new admin_setting_configtext('grade_report_repeatheaders', get_string('repeatheaders', 'grades'),
                                            get_string('repeatheaders_help', 'grades'), 10));

    $settings->add(new admin_setting_configcheckbox('grade_report_showweightedpercents', get_string('showweightedpercents', 'grades'),
        get_string('showweightedpercents_help', 'grades'), 0));

    $settings->add(new admin_setting_configcheckbox('grade_report_showuserimage', get_string('showuserimage', 'grades'),
                                                get_string('showuserimage_help', 'grades'), 1));

    $settings->add(new admin_setting_configcheckbox('grade_report_showactivityicons', get_string('showactivityicons', 'grades'),
                                                get_string('showactivityicons_help', 'grades'), 1));

    $settings->add(new admin_setting_configcheckbox('grade_report_nameswap', get_string('nameswap', 'grades'),
                                                get_string('nameswap_help', 'grades'), 0));
}
