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
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action: list_classes, create_class, create_student, list_students, get_class_students, assign_cohort_members, remove_cohort_member, enroll_students'),
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
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $name = (string)($data['name'] ?? '');
                    $code = (string)($data['code'] ?? '');
                    $desc = (string)($data['description'] ?? '');
                    $moodle_id = (int)($data['moodle_id'] ?? 0);
                    $res = $usecase->create_class_group($name, $code, $desc, $moodle_id);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'record_id' => (int)($res['class_id'] ?? 0),
                    'classes' => [],
                    'students' => [],
                ];

            case 'create_student':
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $res = $usecase->create_or_update_student($data);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'record_id' => (int)($res['user_id'] ?? 0),
                    'classes' => [],
                    'students' => [],
                ];

            case 'assign_cohort_members':
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $cohort_id = (int)($data['cohort_id'] ?? $data['class_id'] ?? 0);
                    $user_ids = is_array($data['user_ids'] ?? null) ? $data['user_ids'] : [];
                    $replace = !isset($data['replace_cohort']) || !empty($data['replace_cohort']);
                    $processed = $usecase->bulk_assign_cohort($user_ids, $cohort_id, $replace);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => true,
                    'message' => "{$processed} anggota cohort berhasil diperbarui.",
                    'record_id' => $cohort_id,
                    'classes' => [],
                    'students' => [],
                ];

            case 'remove_cohort_member':
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $cohort_id = (int)($data['cohort_id'] ?? $data['class_id'] ?? 0);
                    $user_id = (int)($data['user_id'] ?? 0);
                    $ok = $usecase->remove_member_from_cohort($cohort_id, $user_id);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => $ok,
                    'message' => $ok ? 'Anggota berhasil dikeluarkan dari cohort.' : 'Gagal mengeluarkan anggota cohort.',
                    'record_id' => $cohort_id,
                    'classes' => [],
                    'students' => [],
                ];

            case 'enroll_students':
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $res = $usecase->enroll_students_to_course_or_cbt($data);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => (bool)($res['success'] ?? false),
                    'message' => (string)($res['message'] ?? 'Enrollment diproses.'),
                    'record_id' => (int)($res['enrolled_users'] ?? 0),
                    'classes' => [],
                    'students' => [],
                ];

            case 'list_students':
                $students = $usecase->list_all_students();
                return [
                    'success' => true,
                    'message' => 'All students retrieved successfully',
                    'record_id' => 0,
                    'classes' => [],
                    'students' => $students,
                ];

            case 'get_class_students':
                $class_id = (int)($data['class_id'] ?? 0);
                $students = $usecase->get_students_by_class($class_id);
                return [
                    'success' => true,
                    'message' => 'Students retrieved successfully',
                    'record_id' => 0,
                    'classes' => [],
                    'students' => $students,
                ];

            case 'list_classes':
            default:
                $classes = $usecase->list_classes();
                return [
                    'success' => true,
                    'message' => 'Classes retrieved successfully',
                    'record_id' => 0,
                    'classes' => $classes,
                    'students' => [],
                ];
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Status'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'record_id' => new external_value(PARAM_INT, 'Created/updated Moodle record ID', VALUE_OPTIONAL, 0),
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
                    'class_id' => new external_value(PARAM_INT, 'Class ID', VALUE_OPTIONAL, 0),
                    'class_name' => new external_value(PARAM_TEXT, 'Class name', VALUE_OPTIONAL, ''),
                ])
            ),
        ]);
    }
}

