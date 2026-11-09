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

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to generate complete exam recapitulation, grades summary, and cheating audits for teachers.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_exam_recapitulation {

    /**
     * Generate recapitulation summary for a CBT exam.
     *
     * @param int $cbtid
     * @return array
     */
    public function execute(int $cbtid): array {
        global $DB;

        $cbt = $DB->get_record('cbt', ['id' => $cbtid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cbt->course], '*', MUST_EXIST);

        // Fetch all roster students joined with their attempts
        $sql = "SELECT r.userid, r.room_code, r.seat_number,
                       u.username, u.firstname, u.lastname, u.email,
                       a.id AS attempt_id, a.status, a.timestart, a.timefinish,
                       a.extra_time, a.strike_count, a.sumgrades, a.ip_address, a.answers_json
                  FROM {cbt_roster} r
                  JOIN {user} u ON u.id = r.userid
             LEFT JOIN {cbt_attempts} a ON a.cbtid = r.cbtid AND a.userid = r.userid
                 WHERE r.cbtid = :cbtid
              ORDER BY r.seat_number ASC, u.lastname ASC";

        $records = $DB->get_records_sql($sql, ['cbtid' => $cbtid]);
        $grades = [];
        $total_students = count($records);
        $submitted_count = 0;
        $locked_count = 0;
        $in_progress_count = 0;
        $not_started_count = 0;
        $sum_scores = 0.0;
        $highest_score = 0.0;
        $lowest_score = 100.0;

        foreach ($records as $rec) {
            $status = $rec->status ?? 'not_started';
            $final_score = $rec->sumgrades !== null ? (float)$rec->sumgrades : null;

            if ($status === 'submitted') {
                $submitted_count++;
                if ($final_score !== null) {
                    $sum_scores += $final_score;
                    if ($final_score > $highest_score) {
                        $highest_score = $final_score;
                    }
                    if ($final_score < $lowest_score) {
                        $lowest_score = $final_score;
                    }
                }
            } else if ($status === 'locked') {
                $locked_count++;
            } else if ($status === 'inprogress') {
                $in_progress_count++;
            } else {
                $not_started_count++;
            }

            $grades[] = [
                'user_id' => (int)$rec->userid,
                'username' => $rec->username,
                'full_name' => trim($rec->firstname . ' ' . $rec->lastname),
                'email' => $rec->email,
                'room_code' => $rec->room_code,
                'seat_number' => $rec->seat_number,
                'attempt_id' => $rec->attempt_id ? (int)$rec->attempt_id : null,
                'status' => $status,
                'strike_count' => (int)($rec->strike_count ?? 0),
                'time_start' => $rec->timestart ? (int)$rec->timestart : null,
                'time_finish' => $rec->timefinish ? (int)$rec->timefinish : null,
                'duration_taken_seconds' => ($rec->timestart && $rec->timefinish) ? ($rec->timefinish - $rec->timestart) : null,
                'final_score' => $final_score,
                'max_score' => (float)$cbt->grade,
            ];
        }

        $average_score = $submitted_count > 0 ? round($sum_scores / $submitted_count, 2) : 0.0;
        if ($submitted_count === 0) {
            $lowest_score = 0.0;
        }

        return [
            'exam' => [
                'id' => (int)$cbt->id,
                'title' => $cbt->name,
                'course_name' => $course->fullname,
                'time_open' => (int)$cbt->timeopen,
                'time_close' => (int)$cbt->timeclose,
                'timelimit' => (int)$cbt->timelimit,
                'max_grade' => (float)$cbt->grade,
            ],
            'statistics' => [
                'total_participants' => $total_students,
                'submitted' => $submitted_count,
                'locked' => $locked_count,
                'in_progress' => $in_progress_count,
                'not_started' => $not_started_count,
                'average_score' => $average_score,
                'highest_score' => $highest_score,
                'lowest_score' => $lowest_score,
            ],
            'results' => $grades,
        ];
    }
}

