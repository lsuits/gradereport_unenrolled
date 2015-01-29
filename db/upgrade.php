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
 * Upgrade code for gradebook grader report.
 *
 * @package   gradereport_grader
 * @copyright 2013 Moodle Pty Ltd (http://moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_gradereport_grader_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    // Create tables to support anonymous grading.
    if (!$dbman->table_exists('grade_anon_items')) {
        // Define table grade_anonymous_items to be created.
        $table = new xmldb_table('grade_anon_items');

        // Adding fields to table grade_anonymous_items.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('complete', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');

        // Adding keys to table grade_anonymous_items.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('fk_gradeitemid', XMLDB_KEY_FOREIGN_UNIQUE, array('itemid'), 'grade_items', array('id'));

        // Conditionally launch create table for grade_anonymous_items.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table grade_anon_items_history to be created.
        $table = new xmldb_table('grade_anon_items_history');

        // Adding fields to table grade_anon_items_history.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('action', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('oldid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);
        $table->add_field('loggeduser', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('complete', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');

        // Adding keys to table grade_anon_items_history.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Adding indexes to table grade_anon_items_history.
        $table->add_index('gradeanonhist_act_ix', XMLDB_INDEX_NOTUNIQUE, array('action'));
        $table->add_index('gradeanonhist_old_ix', XMLDB_INDEX_NOTUNIQUE, array('oldid'));
        $table->add_index('gradeanonhist_log_ix', XMLDB_INDEX_NOTUNIQUE, array('loggeduser'));
        $table->add_index('gradeanonhist_ite_ix', XMLDB_INDEX_NOTUNIQUE, array('itemid'));

        // Conditionally launch create table for grade_anon_items_history.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

         // Define table grade_anonymous_grades to be created.
        $table = new xmldb_table('grade_anon_grades');

        // Adding fields to table grade_anonymous_grades.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('anonymous_itemid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('finalgrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('adjust_value', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, '0.00000');

        // Adding keys to table grade_anonymous_grades.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('fk_gradeitemid', XMLDB_KEY_FOREIGN, array('anonymous_itemid'), 'grade_anonymous_items', array('id'));
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));

        // Conditionally launch create table for grade_anonymous_grades.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

         // Define table grade_anon_grades_history to be created.
        $table = new xmldb_table('grade_anon_grades_history');

        // Adding fields to table grade_anon_grades_history.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('action', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('oldid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);
        $table->add_field('loggeduser', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);
        $table->add_field('anonymous_itemid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_field('finalgrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('adjust_value', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0.00000');

        // Adding keys to table grade_anon_grades_history.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Adding indexes to table grade_anon_grades_history.
        $table->add_index('gradeanongrahist_act_ix', XMLDB_INDEX_NOTUNIQUE, array('action'));
        $table->add_index('gradeanongrahist_old_ix', XMLDB_INDEX_NOTUNIQUE, array('oldid'));
        $table->add_index('gradeanongrahist_log_ix', XMLDB_INDEX_NOTUNIQUE, array('loggeduser'));
        $table->add_index('gradeanongrahist_ait_ix', XMLDB_INDEX_NOTUNIQUE, array('anonymous_itemid'));

        // Conditionally launch create table for grade_anon_grades_history.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2014060400, 'gradereport', 'grader');
    }

    return true;
}
