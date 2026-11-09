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
 * Post-installation callback for mod_cbt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Auto-configure web services, protocols, and defaults upon plugin installation.
 *
 * @return bool
 */
function xmldb_cbt_install() {
    global $DB;

    // 1. Enable Web Services & REST protocol automatically
    set_config('enablewebservices', 1);
    set_config('enablewsdocumentation', 1);
    set_config('webserviceprotocols', 'rest');

    // 2. Set default configuration
    set_config('nextjs_url', 'http://localhost:3000/exam', 'mod_cbt');
    set_config('portal_url', 'http://localhost:3000', 'mod_cbt');
    set_config('default_timelimit', 7200, 'mod_cbt');
    set_config('default_maxstrikes', 3, 'mod_cbt');

    // 3. Register external service
    $service = $DB->get_record('external_services', ['shortname' => 'mod_cbt_service']);
    if (!$service) {
        $service = new stdClass();
        $service->name = 'CBT Examination Service';
        $service->shortname = 'mod_cbt_service';
        $service->restrictedusers = 0;
        $service->enabled = 1;
        $service->timecreated = time();
        $service->timemodified = time();
        $service->id = $DB->insert_record('external_services', $service);
    } else {
        $service->enabled = 1;
        $service->restrictedusers = 0;
        $service->timemodified = time();
        $DB->update_record('external_services', $service);
    }

    // 4. Attach all 12 functions
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

    return true;
}

