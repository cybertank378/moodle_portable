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

use mod_cbt\domain\entity\exam_attempt;
use mod_cbt\infrastructure\persistence\db_attempt_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to atomically process and merge incoming batch answer queue.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class batch_sync_answers {
    private db_attempt_repository $repository;

    public function __construct(?db_attempt_repository $repository = null) {
        $this->repository = $repository ?? new db_attempt_repository();
    }

    /**
     * Execute optimistic concurrency batch sync.
     *
     * @param int $attemptid
     * @param int $client_sync_version
     * @param string $answers_json_raw JSON string representing batch answers
     * @return array [
     *     'success' => bool,
     *     'sync_version' => int,
     *     'server_time' => int,
     *     'remaining_seconds' => int,
     *     'status' => string
     * ]
     * @throws \moodle_exception
     */
    public function execute(int $attemptid, int $client_sync_version, string $answers_json_raw): array {
        global $DB;

        $record = $this->repository->get_attempt_by_id($attemptid);
        if (!$record) {
            throw new \moodle_exception('invalidattemptid', 'mod_cbt');
        }

        $attempt = exam_attempt::from_record($record);
        if (!$attempt->is_active()) {
            return [
                'success' => false,
                'sync_version' => $attempt->sync_version,
                'server_time' => time(),
                'remaining_seconds' => 0,
                'status' => $attempt->status,
                'error' => 'Attempt is not active (' . $attempt->status . ')',
            ];
        }

        // Check time limit
        $cbt = $DB->get_record('cbt', ['id' => $attempt->cbtid]);
        $now = time();
        $remaining = $attempt->calculate_remaining_seconds((int)($cbt->timelimit ?? 0), $now);

        if ($remaining <= 0 && (int)($cbt->timelimit ?? 0) > 0) {
            // Time expired, auto finalize
            $finalizer = new finalize_attempt($this->repository);
            $finalizer->execute($attempt->id);

            return [
                'success' => false,
                'sync_version' => $attempt->sync_version,
                'server_time' => $now,
                'remaining_seconds' => 0,
                'status' => exam_attempt::STATUS_SUBMITTED,
                'error' => 'Time expired, exam submitted',
            ];
        }

        $incoming_answers = json_decode($answers_json_raw, true);
        if (!is_array($incoming_answers)) {
            $incoming_answers = [];
        }

        $expected_version = $attempt->sync_version;
        $attempt->apply_batch_answers($incoming_answers, $client_sync_version);

        $merged_json = json_encode($attempt->answers);
        $this->repository->update_answers_atomic(
            $attempt->id,
            $merged_json,
            $attempt->sync_version,
            $expected_version
        );

        return [
            'success' => true,
            'sync_version' => $attempt->sync_version,
            'server_time' => $now,
            'remaining_seconds' => $remaining,
            'status' => $attempt->status,
        ];
    }
}
