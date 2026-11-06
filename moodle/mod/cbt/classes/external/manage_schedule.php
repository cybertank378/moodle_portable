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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_cbt\application\usecase\manage_exam_schedule;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to fetch and manage exam schedules from Next.js.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_schedule extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHA, 'Action: list or save'),
            'data' => new external_value(PARAM_RAW, 'JSON payload for saving schedule', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function execute(string $action, string $data = '{}'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action,
            'data' => $data,
        ]);

        $usecase = new manage_exam_schedule();

        if ($params['action'] === 'save') {
            $payload = json_decode($params['data'], true) ?: [];
            $res = $usecase->save_schedule($payload);
            return [
                'success' => $res['success'],
                'message' => $res['message'],
                'schedules' => [],
            ];
        }

        $schedules = $usecase->list_schedules();
        return [
            'success' => true,
            'message' => 'Schedules retrieved successfully',
            'schedules' => $schedules,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
            'schedules' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'CBT ID'),
                    'course_id' => new external_value(PARAM_INT, 'Course ID'),
                    'course_name' => new external_value(PARAM_TEXT, 'Course name'),
                    'title' => new external_value(PARAM_TEXT, 'Exam title'),
                    'intro' => new external_value(PARAM_RAW, 'Exam intro'),
                    'time_open' => new external_value(PARAM_INT, 'Open timestamp'),
                    'time_close' => new external_value(PARAM_INT, 'Close timestamp'),
                    'duration_seconds' => new external_value(PARAM_INT, 'Duration'),
                    'max_strikes' => new external_value(PARAM_INT, 'Max strikes'),
                    'active_token' => new external_value(PARAM_TEXT, 'Token'),
                    'token_expiry' => new external_value(PARAM_INT, 'Expiry'),
                    'max_grade' => new external_value(PARAM_FLOAT, 'Max grade'),
                ])
            ),
        ]);
    }
}

