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
use mod_cbt\application\usecase\manage_classes_and_users;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to manage student accounts and classrooms/cohorts.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_groups extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHA, 'Action: list_classes, create_class, create_student, get_class_students'),
            'payload' => new external_value(PARAM_RAW, 'JSON payload with relevant data', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function execute(string $action, string $payload = '{}'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action,
            'payload' => $payload,
        ]);

        $usecase = new manage_classes_and_users();
        $data = json_decode($params['payload'], true) ?: [];

        switch ($params['action']) {
            case 'create_class':
                $name = (string)($data['name'] ?? '');
                $code = (string)($data['code'] ?? '');
                $desc = (string)($data['description'] ?? '');
                $res = $usecase->create_class_group($name, $code, $desc);
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'classes' => [],
                    'students' => [],
                ];

            case 'create_student':
                $res = $usecase->create_or_update_student($data);
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'classes' => [],
                    'students' => [],
                ];

            case 'get_class_students':
                $class_id = (int)($data['class_id'] ?? 0);
                $students = $usecase->get_students_by_class($class_id);
                return [
                    'success' => true,
                    'message' => 'Students retrieved successfully',
                    'classes' => [],
                    'students' => $students,
                ];

            case 'list_classes':
            default:
                $classes = $usecase->list_classes();
                return [
                    'success' => true,
                    'message' => 'Classes retrieved successfully',
                    'classes' => $classes,
                    'students' => [],
                ];
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Status'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'classes' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Class ID'),
                    'name' => new external_value(PARAM_TEXT, 'Class Name'),
                    'code' => new external_value(PARAM_TEXT, 'Class Code'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'student_count' => new external_value(PARAM_INT, 'Total students'),
                ])
            ),
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'user_id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'full_name' => new external_value(PARAM_TEXT, 'Full name'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                ])
            ),
        ]);
    }
}

