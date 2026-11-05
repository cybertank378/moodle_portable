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
        'description'   => 'Execute proctor intervention commands (unlock, extend time, force submit, reset device).',
        'type'          => 'write',
        'capabilities'  => 'mod/cbt:proctor',
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
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'mod_cbt_service',
    ],
];

