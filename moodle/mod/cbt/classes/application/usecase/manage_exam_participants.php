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
 * Use case to manage participants, room allocation, and desk numbers from Next.js into Moodle.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_exam_participants {
    private db_attempt_repository $repository;

    public function __construct(?db_attempt_repository $repository = null) {
        $this->repository = $repository ?? new db_attempt_repository();
    }

    /**
     * Get roster participants of an exam with assigned room and desk/seat numbers.
     *
     * @param int $cbtid
     * @return array
     */
    public function get_roster(int $cbtid): array {
        global $DB;

        // Fetch roster joined with users and active attempts
        $sql = "SELECT r.id, r.cbtid, r.userid, r.room_code, r.seat_number,
                       u.username, u.firstname, u.lastname, u.email,
                       a.id AS attempt_id, a.status AS attempt_status, a.strike_count, a.extra_time, a.sumgrades
                  FROM {cbt_roster} r
                  JOIN {user} u ON u.id = r.userid
             LEFT JOIN {cbt_attempts} a ON a.cbtid = r.cbtid AND a.userid = r.userid
                 WHERE r.cbtid = :cbtid
              ORDER BY r.room_code ASC, r.seat_number ASC, u.lastname ASC";

        $records = $DB->get_records_sql($sql, ['cbtid' => $cbtid]);
        $list = [];

        foreach ($records as $rec) {
            $list[] = [
                'user_id' => (int)$rec->userid,
                'username' => $rec->username,
                'full_name' => trim($rec->firstname . ' ' . $rec->lastname),
                'email' => $rec->email,
                'room_code' => $rec->room_code,
                'seat_number' => $rec->seat_number,
                'attempt_id' => $rec->attempt_id ? (int)$rec->attempt_id : null,
                'status' => $rec->attempt_status ?? 'not_started',
                'strike_count' => (int)($rec->strike_count ?? 0),
                'score' => $rec->sumgrades !== null ? (float)$rec->sumgrades : null,
            ];
        }

        return $list;
    }

    /**
     * Assign or update a list of participants to an exam with room and seat allocation.
     *
     * @param int $cbtid
     * @param array $participants Array of [ 'user_id' => int, 'room_code' => string, 'seat_number' => string ]
     * @return array [ 'success' => bool, 'assigned_count' => int ]
     */
    public function assign_participants(int $cbtid, array $participants): array {
        $count = 0;
        foreach ($participants as $p) {
            $user_id = (int)($p['user_id'] ?? 0);
            $room_code = trim((string)($p['room_code'] ?? 'UTAMA'));
            $seat_number = trim((string)($p['seat_number'] ?? '01'));

            if ($user_id > 0) {
                $this->repository->assign_roster_participant($cbtid, $user_id, $room_code, $seat_number);
                $count++;
            }
        }

        return [
            'success' => true,
            'assigned_count' => $count,
            'message' => "Berhasil menjadwalkan {$count} peserta ujian beserta nomor meja.",
        ];
    }
}

