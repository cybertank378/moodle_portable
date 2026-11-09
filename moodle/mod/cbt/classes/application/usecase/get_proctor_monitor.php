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
 * Use case to provide real-time proctor monitoring grid and live cheating alerts.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_proctor_monitor {

    /**
     * Fetch real-time candidate matrix for proctor room.
     *
     * @param int $cbtid
     * @param string $room_code Optional room filter
     * @return array [ 'exam' => array, 'candidates' => array, 'alerts' => array ]
     */
    public function execute(int $cbtid, string $room_code = ''): array {
        global $DB;

        $cbt = $DB->get_record('cbt', ['id' => $cbtid], '*', MUST_EXIST);
        $now = time();

        $params = ['cbtid' => $cbtid];
        $room_where = "";
        if ($room_code !== '') {
            $room_where = "AND r.room_code = :roomcode";
            $params['roomcode'] = $room_code;
        }

        // 1. Fetch roster candidates joined with attempts
        $sql = "SELECT r.userid, r.room_code, r.seat_number,
                       u.username, u.firstname, u.lastname,
                       a.id AS attempt_id, a.status AS attempt_status,
                       a.timestart, a.timefinish, a.extra_time, a.strike_count,
                       a.ip_address, a.timemodified
                  FROM {cbt_roster} r
                  JOIN {user} u ON u.id = r.userid
             LEFT JOIN {cbt_attempts} a ON a.cbtid = r.cbtid AND a.userid = r.userid
                 WHERE r.cbtid = :cbtid {$room_where}
              ORDER BY r.seat_number ASC, u.lastname ASC";

        $records = $DB->get_records_sql($sql, $params);
        $candidates = [];
        $active_attempt_ids = [];

        foreach ($records as $rec) {
            $att_id = $rec->attempt_id ? (int)$rec->attempt_id : null;
            if ($att_id) {
                $active_attempt_ids[] = $att_id;
            }

            $status = $rec->attempt_status ?? 'not_started';
            $is_locked = ($status === 'locked');

            $candidates[] = [
                'user_id' => (int)$rec->userid,
                'username' => $rec->username,
                'full_name' => trim($rec->firstname . ' ' . $rec->lastname),
                'room_code' => $rec->room_code,
                'seat_number' => $rec->seat_number,
                'attempt_id' => $att_id,
                'status' => $status,
                'is_locked' => $is_locked,
                'strike_count' => (int)($rec->strike_count ?? 0),
                'extra_time' => (int)($rec->extra_time ?? 0),
                'ip_address' => $rec->ip_address ?? '',
                'last_active' => $rec->timemodified ? (int)$rec->timemodified : null,
            ];
        }

        // 2. Fetch recent cheating / violation alerts
        $alerts = [];
        if (!empty($active_attempt_ids)) {
            list($in_sql, $in_params) = $DB->get_in_or_equal($active_attempt_ids);
            $alert_sql = "SELECT l.id, l.attemptid, l.event_type, l.details, l.timecreated,
                                 u.firstname, u.lastname, r.seat_number
                            FROM {cbt_violation_logs} l
                            JOIN {cbt_attempts} a ON a.id = l.attemptid
                            JOIN {user} u ON u.id = a.userid
                       LEFT JOIN {cbt_roster} r ON r.cbtid = a.cbtid AND r.userid = a.userid
                           WHERE l.attemptid {$in_sql}
                        ORDER BY l.timecreated DESC, l.id DESC";

            $alert_records = $DB->get_records_sql($alert_sql, $in_params, 0, 30);
            foreach ($alert_records as $al) {
                $alerts[] = [
                    'id' => (int)$al->id,
                    'attempt_id' => (int)$al->attemptid,
                    'student_name' => trim($al->firstname . ' ' . $al->lastname),
                    'seat_number' => $al->seat_number ?? '-',
                    'event_type' => $al->event_type,
                    'details' => $al->details ? json_decode($al->details, true) : null,
                    'timestamp' => (int)$al->timecreated,
                ];
            }
        }

        return [
            'exam' => [
                'id' => (int)$cbt->id,
                'title' => $cbt->name,
                'active_token' => $cbt->active_token,
                'max_strikes' => (int)$cbt->maxstrikes,
                'duration_seconds' => (int)$cbt->timelimit,
            ],
            'candidates' => $candidates,
            'recent_alerts' => $alerts,
            'server_time' => $now,
        ];
    }
}

