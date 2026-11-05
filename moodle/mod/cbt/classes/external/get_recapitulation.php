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
use mod_cbt\application\usecase\get_exam_recapitulation;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to fetch full exam results recapitulation for teachers.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_recapitulation extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cbtid' => new external_value(PARAM_INT, 'The CBT instance ID'),
        ]);
    }

    public static function execute(int $cbtid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cbtid' => $cbtid,
        ]);

        $usecase = new get_exam_recapitulation();
        $data = $usecase->execute($params['cbtid']);

        return [
            'success' => true,
            'exam' => $data['exam'],
            'statistics' => $data['statistics'],
            'results' => array_map(function ($r) {
                return [
                    'user_id' => $r['user_id'],
                    'username' => $r['username'],
                    'full_name' => $r['full_name'],
                    'email' => $r['email'],
                    'room_code' => $r['room_code'],
                    'seat_number' => $r['seat_number'],
                    'attempt_id' => $r['attempt_id'],
                    'status' => $r['status'],
                    'strike_count' => $r['strike_count'],
                    'time_start' => $r['time_start'],
                    'time_finish' => $r['time_finish'],
                    'duration_taken_seconds' => $r['duration_taken_seconds'],
                    'final_score' => $r['final_score'],
                    'max_score' => $r['max_score'],
                ];
            }, $data['results']),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'exam' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Exam ID'),
                'title' => new external_value(PARAM_TEXT, 'Exam Title'),
                'course_name' => new external_value(PARAM_TEXT, 'Course Name'),
                'time_open' => new external_value(PARAM_INT, 'Open timestamp'),
                'time_close' => new external_value(PARAM_INT, 'Close timestamp'),
                'timelimit' => new external_value(PARAM_INT, 'Duration'),
                'max_grade' => new external_value(PARAM_FLOAT, 'Max grade'),
            ]),
            'statistics' => new external_single_structure([
                'total_participants' => new external_value(PARAM_INT, 'Total Candidates'),
                'submitted' => new external_value(PARAM_INT, 'Submitted count'),
                'locked' => new external_value(PARAM_INT, 'Locked count'),
                'in_progress' => new external_value(PARAM_INT, 'In progress count'),
                'not_started' => new external_value(PARAM_INT, 'Not started count'),
                'average_score' => new external_value(PARAM_FLOAT, 'Average Score'),
                'highest_score' => new external_value(PARAM_FLOAT, 'Highest Score'),
                'lowest_score' => new external_value(PARAM_FLOAT, 'Lowest Score'),
            ]),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'user_id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'full_name' => new external_value(PARAM_TEXT, 'Full Name'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                    'room_code' => new external_value(PARAM_TEXT, 'Room'),
                    'seat_number' => new external_value(PARAM_TEXT, 'Seat Number'),
                    'attempt_id' => new external_value(PARAM_INT, 'Attempt ID', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_TEXT, 'Status'),
                    'strike_count' => new external_value(PARAM_INT, 'Strikes'),
                    'time_start' => new external_value(PARAM_INT, 'Start Time', VALUE_OPTIONAL),
                    'time_finish' => new external_value(PARAM_INT, 'Finish Time', VALUE_OPTIONAL),
                    'duration_taken_seconds' => new external_value(PARAM_INT, 'Duration taken in seconds', VALUE_OPTIONAL),
                    'final_score' => new external_value(PARAM_FLOAT, 'Final Score', VALUE_OPTIONAL),
                    'max_score' => new external_value(PARAM_FLOAT, 'Max Score'),
                ])
            ),
        ]);
    }
}
