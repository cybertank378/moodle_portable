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
use mod_cbt\application\usecase\get_proctor_monitor;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service for real-time proctor monitoring and cheating detection stream.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_proctor_stream extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cbtid' => new external_value(PARAM_INT, 'The CBT instance ID'),
            'room_code' => new external_value(PARAM_ALPHANUMEXT, 'Optional room filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $cbtid, string $room_code = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cbtid' => $cbtid,
            'room_code' => $room_code,
        ]);

        $usecase = new get_proctor_monitor();
        $data = $usecase->execute($params['cbtid'], $params['room_code']);

        return [
            'success' => true,
            'exam' => $data['exam'],
            'candidates' => $data['candidates'],
            'recent_alerts' => array_map(function ($al) {
                return [
                    'id' => $al['id'],
                    'attempt_id' => $al['attempt_id'],
                    'student_name' => $al['student_name'],
                    'seat_number' => $al['seat_number'],
                    'event_type' => $al['event_type'],
                    'details' => $al['details'] ? json_encode($al['details']) : '',
                    'timestamp' => $al['timestamp'],
                ];
            }, $data['recent_alerts']),
            'server_time' => $data['server_time'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'exam' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Exam ID'),
                'title' => new external_value(PARAM_TEXT, 'Exam Title'),
                'active_token' => new external_value(PARAM_TEXT, 'Active Token'),
                'max_strikes' => new external_value(PARAM_INT, 'Max strikes'),
                'duration_seconds' => new external_value(PARAM_INT, 'Duration in seconds'),
            ]),
            'candidates' => new external_multiple_structure(
                new external_single_structure([
                    'user_id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'full_name' => new external_value(PARAM_TEXT, 'Full Name'),
                    'room_code' => new external_value(PARAM_TEXT, 'Room'),
                    'seat_number' => new external_value(PARAM_TEXT, 'Seat Number'),
                    'attempt_id' => new external_value(PARAM_INT, 'Attempt ID', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_TEXT, 'Status (inprogress, locked, submitted)'),
                    'is_locked' => new external_value(PARAM_BOOL, 'Is candidate locked'),
                    'strike_count' => new external_value(PARAM_INT, 'Current strikes'),
                    'extra_time' => new external_value(PARAM_INT, 'Extra time seconds'),
                    'ip_address' => new external_value(PARAM_TEXT, 'IP Address'),
                    'last_active' => new external_value(PARAM_INT, 'Last Active Timestamp', VALUE_OPTIONAL),
                ])
            ),
            'recent_alerts' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Alert ID'),
                    'attempt_id' => new external_value(PARAM_INT, 'Attempt ID'),
                    'student_name' => new external_value(PARAM_TEXT, 'Student Name'),
                    'seat_number' => new external_value(PARAM_TEXT, 'Seat Number'),
                    'event_type' => new external_value(PARAM_TEXT, 'Violation Event'),
                    'details' => new external_value(PARAM_RAW, 'Details JSON'),
                    'timestamp' => new external_value(PARAM_INT, 'Timestamp'),
                ])
            ),
            'server_time' => new external_value(PARAM_INT, 'Server time'),
        ]);
    }
}
