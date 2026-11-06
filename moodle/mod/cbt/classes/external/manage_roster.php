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
use mod_cbt\application\usecase\manage_exam_participants;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to fetch and assign exam participants with desk/seat numbers.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_roster extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cbtid' => new external_value(PARAM_INT, 'The CBT instance ID'),
            'action' => new external_value(PARAM_ALPHA, 'Action: get or assign'),
            'participants' => new external_value(PARAM_RAW, 'JSON array of participants for assign action', VALUE_DEFAULT, '[]'),
        ]);
    }

    public static function execute(int $cbtid, string $action, string $participants = '[]'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cbtid' => $cbtid,
            'action' => $action,
            'participants' => $participants,
        ]);

        $usecase = new manage_exam_participants();

        if ($params['action'] === 'assign') {
            $list = json_decode($params['participants'], true) ?: [];
            $res = $usecase->assign_participants($params['cbtid'], $list);
            return [
                'success' => $res['success'],
                'message' => $res['message'],
                'roster' => [],
            ];
        }

        $roster = $usecase->get_roster($params['cbtid']);
        return [
            'success' => true,
            'message' => 'Roster retrieved successfully',
            'roster' => $roster,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
            'roster' => new external_multiple_structure(
                new external_single_structure([
                    'user_id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username / NIS'),
                    'full_name' => new external_value(PARAM_TEXT, 'Student Full Name'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                    'room_code' => new external_value(PARAM_TEXT, 'Room code'),
                    'seat_number' => new external_value(PARAM_TEXT, 'Assigned Desk / Seat Number'),
                    'attempt_id' => new external_value(PARAM_INT, 'Attempt ID', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_TEXT, 'Status'),
                    'strike_count' => new external_value(PARAM_INT, 'Strikes'),
                    'score' => new external_value(PARAM_FLOAT, 'Final Score', VALUE_OPTIONAL),
                ])
            ),
        ]);
    }
}

