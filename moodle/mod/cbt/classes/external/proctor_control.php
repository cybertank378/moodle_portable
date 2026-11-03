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

namespace mod_cbt\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_cbt\application\usecase\execute_proctor_command;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service for proctor commands (unlock, time extension, device reset, force submit).
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proctor_control extends external_api {

    /**
     * Parameter definitions.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The ID of the attempt'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action command: unlock, extend_time, reset_device, force_submit'),
            'payload' => new external_value(PARAM_RAW, 'Optional JSON payload (e.g. {"seconds": 600, "reason": "Power outage"})', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute proctor intervention command.
     *
     * @param int $attemptid
     * @param string $action
     * @param string $payload
     * @return array
     */
    public static function execute(int $attemptid, string $action, string $payload = ''): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'action' => $action,
            'payload' => $payload,
        ]);

        $attempt_rec = $DB->get_record('cbt_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cbt = $DB->get_record('cbt', ['id' => $attempt_rec->cbtid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('cbt', $cbt->id, $cbt->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/cbt:proctor', $context);

        $payload_array = json_decode($params['payload'], true) ?: [];
        $usecase = new execute_proctor_command();
        $result = $usecase->execute($params['attemptid'], $params['action'], $payload_array);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'attempt' => [
                'id' => $result['attempt']['id'],
                'status' => $result['attempt']['status'],
                'strike_count' => $result['attempt']['strike_count'],
                'extra_time' => $result['attempt']['extra_time'],
                'sumgrades' => isset($result['attempt']['sumgrades']) ? (float)$result['attempt']['sumgrades'] : 0.0,
            ],
        ];
    }

    /**
     * Return structure description.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Status of proctor command'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
            'attempt' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Attempt ID'),
                'status' => new external_value(PARAM_ALPHANUMEXT, 'Attempt status'),
                'strike_count' => new external_value(PARAM_INT, 'Current strikes'),
                'extra_time' => new external_value(PARAM_INT, 'Extra time in seconds'),
                'sumgrades' => new external_value(PARAM_FLOAT, 'Grade if submitted', VALUE_DEFAULT, 0.0),
            ]),
        ]);
    }
}
