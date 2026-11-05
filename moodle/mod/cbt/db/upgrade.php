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
 * Upgrade steps for mod_cbt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute mod_cbt upgrade steps between versions.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_cbt_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026110502) {
        // 1. Add room_code and seat_number to cbt_attempts if not present
        $table_attempts = new xmldb_table('cbt_attempts');
        $field_room = new xmldb_field('room_code', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'sync_version');
        if (!$dbman->field_exists($table_attempts, $field_room)) {
            $dbman->add_field($table_attempts, $field_room);
        }

        $field_seat = new xmldb_field('seat_number', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'room_code');
        if (!$dbman->field_exists($table_attempts, $field_seat)) {
            $dbman->add_field($table_attempts, $field_seat);
        }

        // 2. Create cbt_roster table if not exists
        $table_roster = new xmldb_table('cbt_roster');
        $table_roster->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table_roster->add_field('cbtid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table_roster->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table_roster->add_field('room_code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
        $table_roster->add_field('seat_number', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
        $table_roster->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table_roster->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table_roster->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table_roster->add_key('cbtid', XMLDB_KEY_FOREIGN, ['cbtid'], 'cbt', ['id']);
        $table_roster->add_index('cbtid_userid', XMLDB_INDEX_UNIQUE, ['cbtid', 'userid']);
        $table_roster->add_index('cbtid_room', XMLDB_INDEX_NOTUNIQUE, ['cbtid', 'room_code']);

        if (!$dbman->table_exists($table_roster)) {
            $dbman->create_table($table_roster);
        }

        upgrade_mod_savepoint(true, 2026110502, 'cbt');
    }

    return true;
}

