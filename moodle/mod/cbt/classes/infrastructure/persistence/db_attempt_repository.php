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

namespace mod_cbt\infrastructure\persistence;

defined('MOODLE_INTERNAL') || die();

/**
 * High-performance database repository for CBT attempts and violation logs.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class db_attempt_repository {

    /**
     * Fetch an attempt record by primary key.
     *
     * @param int $attemptid
     * @return \stdClass|null
     */
    public function get_attempt_by_id(int $attemptid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('cbt_attempts', ['id' => $attemptid]);
        return $record ?: null;
    }

    /**
     * Fetch active attempt for a given cbt and user.
     *
     * @param int $cbtid
     * @param int $userid
     * @return \stdClass|null
     */
    public function get_user_attempt(int $cbtid, int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('cbt_attempts', ['cbtid' => $cbtid, 'userid' => $userid]);
        return $record ?: null;
    }

    /**
     * Create a new attempt session.
     *
     * @param \stdClass $attempt_data
     * @return int Inserted attempt ID
     */
    public function create_attempt(\stdClass $attempt_data): int {
        global $DB;
        $now = time();
        $attempt_data->timecreated = $now;
        $attempt_data->timemodified = $now;

        return (int)$DB->insert_record('cbt_attempts', $attempt_data);
    }

    /**
     * Atomic optimistic upsert of answers JSON and sync_version.
     * Prevents stale overwrites under concurrent sync calls.
     *
     * @param int $attemptid
     * @param string $answers_json
     * @param int $new_sync_version
     * @param int $expected_version
     * @return bool
     */
    public function update_answers_atomic(
        int $attemptid,
        string $answers_json,
        int $new_sync_version,
        int $expected_version
    ): bool {
        global $DB;
        $now = time();

        $sql = "UPDATE {cbt_attempts}
                   SET answers_json = :answers,
                       sync_version = :newver,
                       timemodified = :timemod
                 WHERE id = :id AND sync_version <= :expected";

        $params = [
            'answers' => $answers_json,
            'newver' => $new_sync_version,
            'timemod' => $now,
            'id' => $attemptid,
            'expected' => $expected_version,
        ];

        return $DB->execute($sql, $params);
    }

    /**
     * Update attempt status, timefinish, and sumgrades.
     *
     * @param int $attemptid
     * @param string $status
     * @param int|null $timefinish
     * @param float|null $sumgrades
     * @return bool
     */
    public function update_status(int $attemptid, string $status, ?int $timefinish = null, ?float $sumgrades = null): bool {
        global $DB;
        $update = new \stdClass();
        $update->id = $attemptid;
        $update->status = $status;
        $update->timemodified = time();

        if ($timefinish !== null) {
            $update->timefinish = $timefinish;
        }
        if ($sumgrades !== null) {
            $update->sumgrades = $sumgrades;
        }

        return $DB->update_record('cbt_attempts', $update);
    }

    /**
     * Update strike count and status.
     *
     * @param int $attemptid
     * @param int $strike_count
     * @param string $status
     * @return bool
     */
    public function update_strikes(int $attemptid, int $strike_count, string $status): bool {
        global $DB;
        $update = new \stdClass();
        $update->id = $attemptid;
        $update->strike_count = $strike_count;
        $update->status = $status;
        $update->timemodified = time();

        return $DB->update_record('cbt_attempts', $update);
    }

    /**
     * Add extra time to attempt in seconds.
     *
     * @param int $attemptid
     * @param int $seconds
     * @return bool
     */
    public function add_extra_time(int $attemptid, int $seconds): bool {
        global $DB;
        $sql = "UPDATE {cbt_attempts}
                   SET extra_time = extra_time + :secs,
                       timemodified = :now
                 WHERE id = :id";
        return $DB->execute($sql, ['secs' => max(0, $seconds), 'now' => time(), 'id' => $attemptid]);
    }

    /**
     * Insert violation or proctor action log.
     *
     * @param int $attemptid
     * @param string $event_type
     * @param array $details
     * @return int Log ID
     */
    public function log_violation(int $attemptid, string $event_type, array $details = []): int {
        global $DB;
        $log = new \stdClass();
        $log->attemptid = $attemptid;
        $log->event_type = $event_type;
        $log->details = !empty($details) ? json_encode($details) : null;
        $log->timecreated = time();

        return (int)$DB->insert_record('cbt_violation_logs', $log);
    }

    /**
     * Get violation logs for an attempt.
     *
     * @param int $attemptid
     * @return array
     */
    public function get_violation_logs(int $attemptid): array {
        global $DB;
        return $DB->get_records('cbt_violation_logs', ['attemptid' => $attemptid], 'timecreated DESC');
    }

    /**
     * Get all attempts for a given CBT instance.
     *
     * @param int $cbtid
     * @return array
     */
    public function get_exam_attempts(int $cbtid): array {
        global $DB;
        return $DB->get_records('cbt_attempts', ['cbtid' => $cbtid], 'id ASC');
    }

    /**
     * Get roster participants with seat and room allocation.
     *
     * @param int $cbtid
     * @return array
     */
    public function get_roster_participants(int $cbtid): array {
        global $DB;
        $sql = "SELECT r.id, r.cbtid, r.userid, r.room_code, r.seat_number,
                       u.username, u.firstname, u.lastname, u.email
                  FROM {cbt_roster} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.cbtid = :cbtid
              ORDER BY r.room_code ASC, r.seat_number ASC";
        return $DB->get_records_sql($sql, ['cbtid' => $cbtid]);
    }

    /**
     * Assign or update a participant's room and seat.
     *
     * @param int $cbtid
     * @param int $userid
     * @param string $room_code
     * @param string $seat_number
     * @return int
     */
    public function assign_roster_participant(int $cbtid, int $userid, string $room_code, string $seat_number): int {
        global $DB;
        $existing = $DB->get_record('cbt_roster', ['cbtid' => $cbtid, 'userid' => $userid]);
        $now = time();

        if ($existing) {
            $existing->room_code = $room_code;
            $existing->seat_number = $seat_number;
            $existing->timemodified = $now;
            $DB->update_record('cbt_roster', $existing);
            return (int)$existing->id;
        }

        $record = new \stdClass();
        $record->cbtid = $cbtid;
        $record->userid = $userid;
        $record->room_code = $room_code;
        $record->seat_number = $seat_number;
        $record->timecreated = $now;
        $record->timemodified = $now;
        return (int)$DB->insert_record('cbt_roster', $record);
    }
}

