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

    if ($oldversion < 2026110901) {
        // Auto-configure Web Services & REST protocol
        set_config('enablewebservices', 1);
        set_config('enablewsdocumentation', 1);
        set_config('webserviceprotocols', 'rest');

        // Set default configuration
        set_config('nextjs_url', 'http://localhost:3000/exam', 'mod_cbt');
        set_config('portal_url', 'http://localhost:3000', 'mod_cbt');
        set_config('default_timelimit', 7200, 'mod_cbt');
        set_config('default_maxstrikes', 3, 'mod_cbt');

        // Register / enable external service (MUST set component = 'mod_cbt' for external_update_descriptions)
        $service = $DB->get_record('external_services', ['shortname' => 'mod_cbt_service']);
        if (!$service) {
            $service = $DB->get_record('external_services', ['name' => 'CBT Examination Service']);
        }
        if (!$service) {
            $service = new stdClass();
            $service->name = 'CBT Examination Service';
            $service->shortname = 'mod_cbt_service';
            $service->component = 'mod_cbt';
            $service->restrictedusers = 0;
            $service->enabled = 1;
            $service->timecreated = time();
            $service->timemodified = time();
            $service->id = $DB->insert_record('external_services', $service);
        } else {
            $service->name = 'CBT Examination Service';
            $service->shortname = 'mod_cbt_service';
            $service->component = 'mod_cbt';
            $service->enabled = 1;
            $service->restrictedusers = 0;
            $service->timemodified = time();
            $DB->update_record('external_services', $service);
        }

        // Attach functions
        $functions = [
            'mod_cbt_get_exam_manifest' => 'mod_cbt\external\get_exam_manifest',
            'mod_cbt_sync_batch_answers' => 'mod_cbt\external\sync_batch_answers',
            'mod_cbt_report_violation' => 'mod_cbt\external\report_violation',
            'mod_cbt_submit_exam' => 'mod_cbt\external\submit_exam',
            'mod_cbt_proctor_control' => 'mod_cbt\external\proctor_control',
            'mod_cbt_manage_schedule' => 'mod_cbt\external\manage_schedule',
            'mod_cbt_manage_roster' => 'mod_cbt\external\manage_roster',
            'mod_cbt_get_proctor_stream' => 'mod_cbt\external\get_proctor_stream',
            'mod_cbt_get_recapitulation' => 'mod_cbt\external\get_recapitulation',
            'mod_cbt_manage_groups' => 'mod_cbt\external\manage_groups',
            'mod_cbt_assign_group_to_exam' => 'mod_cbt\external\assign_group_to_exam',
            'mod_cbt_import_export' => 'mod_cbt\external\import_export',
        ];

        foreach ($functions as $fname => $cname) {
            $f_rec = $DB->get_record('external_functions', ['name' => $fname]);
            if (!$f_rec) {
                $f_rec = new stdClass();
                $f_rec->name = $fname;
                $f_rec->classname = $cname;
                $f_rec->methodname = 'execute';
                $f_rec->classpath = '';
                $f_rec->component = 'mod_cbt';
                $f_rec->capabilities = '';
                $f_rec->services = 'mod_cbt_service';
                $f_rec->id = $DB->insert_record('external_functions', $f_rec);
            }

            $link = $DB->get_record('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fname,
            ]);
            if (!$link) {
                $link = new stdClass();
                $link->externalserviceid = $service->id;
                $link->functionname = $fname;
                $DB->insert_record('external_services_functions', $link);
            }
        }

        upgrade_mod_savepoint(true, 2026110901, 'cbt');
    }

    if ($oldversion < 2026111701) {
        if (get_config('mod_cbt', 'webhook_secret') === false) {
            set_config('webhook_secret', 'aksaventra_sync_secret_2026', 'mod_cbt');
        }

        $service = $DB->get_record('external_services', ['shortname' => 'mod_cbt_service']);
        if ($service) {
            $fname = 'mod_cbt_sync_master';
            $frec = $DB->get_record('external_functions', ['name' => $fname]);
            if (!$frec) {
                $frec = new stdClass();
                $frec->name = $fname;
                $frec->classname = 'mod_cbt\\external\\sync_master';
                $frec->methodname = 'execute';
                $frec->classpath = '';
                $frec->component = 'mod_cbt';
                $frec->capabilities = 'mod/cbt:manage';
                $frec->services = 'mod_cbt_service';
                $DB->insert_record('external_functions', $frec);
            }

            $link = $DB->get_record('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fname,
            ]);
            if (!$link) {
                $link = new stdClass();
                $link->externalserviceid = $service->id;
                $link->functionname = $fname;
                $DB->insert_record('external_services_functions', $link);
            }
        }

        upgrade_mod_savepoint(true, 2026111701, 'cbt');
    }

    if ($oldversion < 2026112101) {
        if (class_exists('\mod_cbt\observer')) {
            \mod_cbt\observer::dispatch('user', 'updated', 2);
        }
        upgrade_mod_savepoint(true, 2026112101, 'cbt');
    }

    if ($oldversion < 2026112701) {
        // Repair legacy mdl_external_services rows created without component = 'mod_cbt'.
        // Without component = 'mod_cbt', Moodle's external_update_descriptions() fails at
        // lib/upgradelib.php:1378 with installserviceshortnameerror because it only matches
        // built-in services by component and then collides on shortname = 'mod_cbt_service'.
        $candidates = $DB->get_records_select(
            'external_services',
            "shortname = :shortname OR name = :name",
            [
                'shortname' => 'mod_cbt_service',
                'name' => 'CBT Examination Service',
            ],
            'id ASC'
        );

        if (!empty($candidates)) {
            $primary = null;
            foreach ($candidates as $cand) {
                if ($cand->shortname === 'mod_cbt_service') {
                    $primary = $cand;
                    break;
                }
            }
            if (!$primary) {
                $primary = reset($candidates);
            }

            foreach ($candidates as $dup) {
                if ((int)$dup->id === (int)$primary->id) {
                    continue;
                }

                // Preserve any active API tokens pointing to the duplicate service row.
                $DB->set_field('external_tokens', 'externalserviceid', $primary->id, ['externalserviceid' => $dup->id]);

                // Migrate authorized service users without creating duplicates.
                $dupusers = $DB->get_records('external_services_users', ['externalserviceid' => $dup->id]);
                foreach ($dupusers as $du) {
                    if (!$DB->record_exists('external_services_users', [
                        'externalserviceid' => $primary->id,
                        'userid' => $du->userid,
                    ])) {
                        $du->externalserviceid = $primary->id;
                        $DB->update_record('external_services_users', $du);
                    } else {
                        $DB->delete_records('external_services_users', ['id' => $du->id]);
                    }
                }

                $DB->delete_records('external_services_functions', ['externalserviceid' => $dup->id]);
                $DB->delete_records('external_services', ['id' => $dup->id]);
            }

            $primary->name = 'CBT Examination Service';
            $primary->shortname = 'mod_cbt_service';
            $primary->component = 'mod_cbt';
            $primary->enabled = 1;
            $primary->restrictedusers = 0;
            $primary->timemodified = time();
            $DB->update_record('external_services', $primary);
        }

        upgrade_mod_savepoint(true, 2026112701, 'cbt');
    }

    return true;
}

