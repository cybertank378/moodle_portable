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
 * External services definitions for mod_cbt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_cbt_get_exam_manifest' => [
        'classname'     => 'mod_cbt\\external\\get_exam_manifest',
        'methodname'    => 'execute',
        'description'   => 'Authenticate student attempt, bind device, and return stripped JSON exam snapshot.',
        'type'          => 'read',
        'capabilities'  => 'mod/cbt:attempt',
    ],
    'mod_cbt_sync_batch_answers' => [
        'classname'     => 'mod_cbt\\external\\sync_batch_answers',
        'methodname'    => 'execute',
        'description'   => 'Atomic optimistic batch upsert of answers from client IndexedDB queue.',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:attempt',
    ],
    'mod_cbt_report_violation' => [
        'classname'     => 'mod_cbt\\external\\report_violation',
        'methodname'    => 'execute',
        'description'   => 'Report anti-cheat violation event (tab blur, fullscreen exit, devtools).',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:attempt',
    ],
    'mod_cbt_submit_exam' => [
        'classname'     => 'mod_cbt\\external\\submit_exam',
        'methodname'    => 'execute',
        'description'   => 'Finalize and submit exam attempt, compute score, and queue gradebook update.',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:attempt',
    ],
    'mod_cbt_proctor_control' => [
        'classname'     => 'mod_cbt\\external\\proctor_control',
        'methodname'    => 'execute',
        'description'   => 'Execute proctor intervention commands (lock, unlock, extend time, force submit).',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:proctor',
    ],
    'mod_cbt_manage_schedule' => [
        'classname'     => 'mod_cbt\\external\\manage_schedule',
        'methodname'    => 'execute',
        'description'   => 'Fetch, create, or update CBT exam schedules from Next.js.',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:manage',
    ],
    'mod_cbt_manage_roster' => [
        'classname'     => 'mod_cbt\\external\\manage_roster',
        'methodname'    => 'execute',
        'description'   => 'Fetch or assign participants and seat numbers for an exam.',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:manage',
    ],
    'mod_cbt_get_proctor_stream' => [
        'classname'     => 'mod_cbt\\external\\get_proctor_stream',
        'methodname'    => 'execute',
        'description'   => 'Live candidates matrix and real-time anti-cheat violation alerts.',
        'type'          => 'read',
        'capabilities'  => 'mod/cbt:proctor',
    ],
    'mod_cbt_get_recapitulation' => [
        'classname'     => 'mod_cbt\\external\\get_recapitulation',
        'methodname'    => 'execute',
        'description'   => 'Complete exam results recapitulation, scores, and integrity logs.',
        'type'          => 'read',
        'capabilities'  => 'mod/cbt:grade',
    ],
    'mod_cbt_manage_groups' => [
        'classname'     => 'mod_cbt\\external\\manage_groups',
        'methodname'    => 'execute',
        'description'   => 'Create and manage student accounts and classrooms/cohorts.',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:manage',
    ],
    'mod_cbt_assign_group_to_exam' => [
        'classname'     => 'mod_cbt\\external\\assign_group_to_exam',
        'methodname'    => 'execute',
        'description'   => 'Assign student groups/classes to an exam with auto seat numbers.',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:manage',
    ],
    'mod_cbt_import_export' => [
        'classname'     => 'mod_cbt\\external\\import_export',
        'methodname'    => 'execute',
        'description'   => 'Import/Export students, questions (8 types), and exam results with Excel/CSV support.',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:manage',
    ],
];

$services = [
    'CBT Examination Service' => [
        'functions' => [
            'mod_cbt_get_exam_manifest',
            'mod_cbt_sync_batch_answers',
            'mod_cbt_report_violation',
            'mod_cbt_submit_exam',
            'mod_cbt_proctor_control',
            'mod_cbt_manage_schedule',
            'mod_cbt_manage_roster',
            'mod_cbt_get_proctor_stream',
            'mod_cbt_get_recapitulation',
            'mod_cbt_manage_groups',
            'mod_cbt_assign_group_to_exam',
            'mod_cbt_import_export',
            'core_webservice_get_site_info',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'mod_cbt_service',
    ],
];

