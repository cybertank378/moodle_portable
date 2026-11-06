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
use mod_cbt\application\usecase\assign_exam_by_group;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to assign student groups/classes to an exam with auto desk numbers.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_group_to_exam extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cbtid' => new external_value(PARAM_INT, 'The CBT instance ID'),
            'class_ids' => new external_value(PARAM_RAW, 'JSON array of class IDs e.g. [1, 2]'),
            'room_code' => new external_value(PARAM_ALPHANUMEXT, 'Target room code e.g. LAB-01', VALUE_DEFAULT, 'LAB-01'),
            'seat_prefix' => new external_value(PARAM_ALPHANUMEXT, 'Seat prefix e.g. M-', VALUE_DEFAULT, 'M-'),
            'start_seat' => new external_value(PARAM_INT, 'Starting desk number e.g. 1', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute(
        int $cbtid,
        string $class_ids,
        string $room_code = 'LAB-01',
        string $seat_prefix = 'M-',
        int $start_seat = 1
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cbtid' => $cbtid,
            'class_ids' => $class_ids,
            'room_code' => $room_code,
            'seat_prefix' => $seat_prefix,
            'start_seat' => $start_seat,
        ]);

        $classes_array = json_decode($params['class_ids'], true) ?: [];
        $usecase = new assign_exam_by_group();

        $res = $usecase->execute(
            $params['cbtid'],
            $classes_array,
            $params['room_code'],
            $params['seat_prefix'],
            $params['start_seat']
        );

        return [
            'success' => $res['success'],
            'assigned_count' => $res['assigned_count'],
            'message' => $res['message'],
            'assigned_students' => $res['assigned_students'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Status'),
            'assigned_count' => new external_value(PARAM_INT, 'Total students assigned'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
            'assigned_students' => new external_multiple_structure(
                new external_single_structure([
                    'user_id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username / NIS'),
                    'full_name' => new external_value(PARAM_TEXT, 'Full name'),
                    'room_code' => new external_value(PARAM_TEXT, 'Room code'),
                    'seat_number' => new external_value(PARAM_TEXT, 'Assigned Seat Number'),
                ])
            ),
        ]);
    }
}

