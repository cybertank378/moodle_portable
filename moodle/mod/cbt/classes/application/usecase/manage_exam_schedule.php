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
 * Use case to manage exam schedules from frontend (Next.js) into Moodle mod_cbt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_exam_schedule {

    /**
     * List all available exam schedules with timing and course metadata.
     *
     * @param int $courseid Optional filter by course ID (0 for all)
     * @return array
     */
    public function list_schedules(int $courseid = 0): array {
        global $DB;

        $params = [];
        $where = "1=1";
        if ($courseid > 0) {
            $where = "c.course = :courseid";
            $params['courseid'] = $courseid;
        }

        $sql = "SELECT c.id, c.course, co.fullname AS coursename, c.name, c.intro,
                       c.timeopen, c.timeclose, c.timelimit, c.maxstrikes,
                       c.active_token, c.token_expiry, c.grade
                  FROM {cbt} c
                  JOIN {course} co ON co.id = c.course
                 WHERE {$where}
              ORDER BY c.timeopen ASC, c.id DESC";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];

        foreach ($records as $rec) {
            $result[] = [
                'id' => (int)$rec->id,
                'course_id' => (int)$rec->course,
                'course_name' => $rec->coursename,
                'title' => $rec->name,
                'intro' => $rec->intro ?? '',
                'time_open' => (int)$rec->timeopen,
                'time_close' => (int)$rec->timeclose,
                'duration_seconds' => (int)$rec->timelimit,
                'max_strikes' => (int)$rec->maxstrikes,
                'active_token' => $rec->active_token,
                'token_expiry' => (int)$rec->token_expiry,
                'max_grade' => (float)$rec->grade,
            ];
        }

        return $result;
    }

    /**
     * Create or update an exam schedule.
     *
     * @param array $data [ 'id' => ?int, 'course_id' => int, 'title' => string, 'time_open' => int, 'time_close' => int, 'duration_seconds' => int, 'max_strikes' => int, 'active_token' => string ]
     * @return array [ 'success' => bool, 'cbt_id' => int, 'message' => string ]
     * @throws \moodle_exception
     */
    public function save_schedule(array $data): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $now = time();
        $cbt_id = (int)($data['id'] ?? 0);
        $course_id = (int)($data['course_id'] ?? 1); // Defaults to site/first course
        $title = trim((string)($data['title'] ?? 'Ujian CBT'));
        $time_open = (int)($data['time_open'] ?? $now);
        $time_close = (int)($data['time_close'] ?? ($now + 86400));
        $duration = (int)($data['duration_seconds'] ?? 7200);
        $max_strikes = (int)($data['max_strikes'] ?? 3);
        $token = strtoupper(trim((string)($data['active_token'] ?? '')));

        if ($token === '') {
            $token = \mod_cbt\domain\service\token_generator::generate_token('CBT_SECRET', $now, 900);
        }

        if ($cbt_id > 0) {
            $existing = $DB->get_record('cbt', ['id' => $cbt_id]);
            if (!$existing) {
                throw new \moodle_exception('invalidcbtid', 'mod_cbt');
            }

            $existing->name = $title;
            $existing->timeopen = $time_open;
            $existing->timeclose = $time_close;
            $existing->timelimit = $duration;
            $existing->maxstrikes = $max_strikes;
            $existing->active_token = $token;
            $existing->token_expiry = $now + 900;
            $existing->timemodified = $now;

            $DB->update_record('cbt', $existing);

            return [
                'success' => true,
                'cbt_id' => $cbt_id,
                'message' => 'Jadwal ujian berhasil diperbarui.',
            ];
        }

        // Create new CBT activity instance
        $cbt = new \stdClass();
        $cbt->course = $course_id;
        $cbt->name = $title;
        $cbt->intro = '<p>Ujian CBT Aksaventra Headless</p>';
        $cbt->introformat = FORMAT_HTML;
        $cbt->timeopen = $time_open;
        $cbt->timeclose = $time_close;
        $cbt->timelimit = $duration;
        $cbt->maxstrikes = $max_strikes;
        $cbt->tokensecret = bin2hex(random_bytes(16));
        $cbt->active_token = $token;
        $cbt->token_expiry = $now + 900;
        $cbt->grade = 100.00000;
        $cbt->timecreated = $now;
        $cbt->timemodified = $now;

        $new_id = (int)$DB->insert_record('cbt', $cbt);

        return [
            'success' => true,
            'cbt_id' => $new_id,
            'message' => 'Jadwal ujian baru berhasil dibuat.',
        ];
    }
}

