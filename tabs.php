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
 * Outputs navigation tabs for the unenrolled report
 *
 * @package   gradereport_unenrolled
 * @copyright 2007 2009 Nicolas Connault
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    $row = $tabs = array();
    $tabcontext = context_course::instance($COURSE->id);
    $row[] = new tabobject('unenrolledreport',
                           $CFG->wwwroot.'/grade/report/unenrolled/index.php?id='.$courseid,
                           get_string('pluginname', 'gradereport_unenrolled'));
    if (has_capability('moodle/grade:manage',$tabcontext ) ||
        has_capability('moodle/grade:edit', $tabcontext) ||
        has_capability('gradereport/unenrolled:view', $tabcontext)) {
        $row[] = new tabobject('preferences',
                               $CFG->wwwroot.'/grade/report/unenrolled/preferences.php?id='.$courseid,
                               get_string('myreportpreferences', 'grades'));
    }

    $tabs[] = $row;
    echo '<div class="gradedisplay">';
    print_tabs($tabs, $currenttab);
    echo '</div>';

