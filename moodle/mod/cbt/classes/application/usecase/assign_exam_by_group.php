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

namespace mod_cbt\application\usecase;

use mod_cbt\infrastructure\persistence\db_attempt_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to assign entire classroom(s) / student groups into an exam with auto-seat allocation.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_exam_by_group {
    private db_attempt_repository $repository;

    public function __construct(?db_attempt_repository $repository = null) {
        $this->repository = $repository ?? new db_attempt_repository();
    }

    /**
     * Assign one or multiple classes/cohorts to an exam with room and seat allocation.
     *
     * @param int $cbtid
     * @param array $class_ids Array of cohort/class IDs
     * @param string $room_code Target exam room e.g. "LAB-01"
     * @param string $seat_prefix Desk prefix e.g. "M-"
     * @param int $start_seat Starting desk number e.g. 1
     * @return array [ 'success' => bool, 'assigned_count' => int, 'message' => string, 'assigned_students' => array ]
     * @throws \moodle_exception
     */
    public function execute(
        int $cbtid,
        array $class_ids,
        string $room_code = 'LAB-01',
        string $seat_prefix = 'M-',
        int $start_seat = 1
    ): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/enrol/locallib.php');

        $cbt = $DB->get_record('cbt', ['id' => $cbtid], '*', MUST_EXIST);
        $course_id = (int)$cbt->course;

        if (empty($class_ids)) {
            throw new \moodle_exception('noclassesselected', 'mod_cbt', '', null, 'Pilih minimal satu kelas/kelompok.');
        }

        // 1. Fetch all unique students from the selected classes
        list($in_sql, $in_params) = $DB->get_in_or_equal($class_ids);
        $sql = "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email
                  FROM {cohort_members} cm
                  JOIN {user} u ON u.id = cm.userid
                 WHERE cm.cohortid {$in_sql} AND u.deleted = 0
              ORDER BY u.firstname ASC, u.lastname ASC";

        $students = $DB->get_records_sql($sql, $in_params);
        if (empty($students)) {
            return [
                'success' => false,
                'assigned_count' => 0,
                'message' => 'Tidak ditemukan siswa pada kelas yang dipilih.',
                'assigned_students' => [],
            ];
        }

        // 2. Ensure students are enrolled in the course
        $enrol_instances = enrol_get_instances($course_id, true);
        $manual_instance = null;
        foreach ($enrol_instances as $instance) {
            if ($instance->enrol === 'manual') {
                $manual_instance = $instance;
                break;
            }
        }
        $manual_plugin = enrol_get_plugin('manual');

        $current_seat = max(1, $start_seat);
        $assigned = [];

        foreach ($students as $student) {
            $user_id = (int)$student->id;

            // Auto enrol student if not enrolled
            if ($manual_instance && $manual_plugin) {
                if (!is_enrolled(\context_course::instance($course_id), $user_id)) {
                    $manual_plugin->enrol_user($manual_instance, $user_id, 5); // 5 = Student role
                }
            }

            // Generate seat number e.g. "M-01", "M-02"
            $seat_number = sprintf('%s%02d', $seat_prefix, $current_seat);

            // Assign to CBT roster
            $this->repository->assign_roster_participant($cbtid, $user_id, $room_code, $seat_number);

            $assigned[] = [
                'user_id' => $user_id,
                'username' => $student->username,
                'full_name' => trim($student->firstname . ' ' . $student->lastname),
                'room_code' => $room_code,
                'seat_number' => $seat_number,
            ];

            $current_seat++;
        }

        $count = count($assigned);

        return [
            'success' => true,
            'assigned_count' => $count,
            'message' => "Berhasil menugaskan {$count} siswa ke dalam ujian pada ruangan {$room_code}.",
            'assigned_students' => $assigned,
        ];
    }
}

