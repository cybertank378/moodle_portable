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
use mod_cbt\domain\service\token_generator;
use mod_cbt\infrastructure\cache\muc_snapshot_store;
use mod_cbt\infrastructure\persistence\db_attempt_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to authenticate student, bind device, and return JSON exam snapshot.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authenticate_attempt {
    private db_attempt_repository $repository;

    public function __construct(?db_attempt_repository $repository = null) {
        $this->repository = $repository ?? new db_attempt_repository();
    }

    /**
     * Execute attempt authentication and snapshot provisioning.
     *
     * @param \stdClass $cbt
     * @param int $userid
     * @param string $token
     * @param string $device_hash
     * @param string $ip_address
     * @return array Standard manifest payload
     * @throws \moodle_exception
     */
    public function execute(
        \stdClass $cbt,
        int $userid,
        string $token,
        string $device_hash,
        string $ip_address = ''
    ): array {
        $now = time();

        // 1. Check time window
        if ($cbt->timeopen > 0 && $now < $cbt->timeopen) {
            throw new \moodle_exception('exam_not_started', 'mod_cbt');
        }
        if ($cbt->timeclose > 0 && $now > $cbt->timeclose) {
            throw new \moodle_exception('exam_closed', 'mod_cbt');
        }

        // 2. Validate Room Token if configured
        if (!empty($cbt->tokensecret) || !empty($cbt->active_token)) {
            $valid = token_generator::validate(
                $token,
                $cbt->tokensecret ?? '',
                $cbt->active_token ?? '',
                (int)($cbt->token_expiry ?? 0)
            );
            if (!$valid) {
                throw new \moodle_exception('invalid_token', 'mod_cbt');
            }
        }

        // 4. Retrieve or create attempt
        $existing = $this->repository->get_user_attempt((int)$cbt->id, $userid);
        if ($existing) {
            $attempt = exam_attempt::from_record($existing);

            if ($attempt->is_submitted()) {
                throw new \moodle_exception('exam_submitted', 'mod_cbt');
            }

            if ($attempt->is_locked()) {
                throw new \moodle_exception('exam_locked', 'mod_cbt');
            }

            // Pelacakan perangkat dinamis (portabel): perbarui device_hash dan ip_address jika berganti mesin
            global $DB;
            $updates = [];
            if ($device_hash !== '' && $attempt->device_hash !== $device_hash) {
                $attempt->device_hash = $device_hash;
                $updates['device_hash'] = $device_hash;
            }
            if ($ip_address !== '' && $attempt->ip_address !== $ip_address) {
                $attempt->ip_address = $ip_address;
                $updates['ip_address'] = $ip_address;
            }
            if (!empty($updates)) {
                $updates['timemodified'] = time();
                $DB->update_record('cbt_attempts', (object)(array_merge(['id' => $attempt->id], $updates)));
            }
        } else {
            // Build question order
            $questions_snapshot = muc_snapshot_store::get_question_snapshot($cbt);
            $qids = array_column($questions_snapshot, 'questionId');
            shuffle($qids);

            $attempt_data = new \stdClass();
            $attempt_data->cbtid = (int)$cbt->id;
            $attempt_data->userid = $userid;
            $attempt_data->status = exam_attempt::STATUS_INPROGRESS;
            $attempt_data->timestart = $now;
            $attempt_data->timefinish = 0;
            $attempt_data->extra_time = 0;
            $attempt_data->strike_count = 0;
            $attempt_data->device_hash = $device_hash;
            $attempt_data->ip_address = $ip_address;
            $attempt_data->question_order = json_encode($qids);
            $attempt_data->answers_json = json_encode(new \stdClass());
            $attempt_data->sync_version = 1;
            $attempt_data->sumgrades = null;

            $attempt_id = $this->repository->create_attempt($attempt_data);
            $attempt_data->id = $attempt_id;
            $attempt = exam_attempt::from_record($attempt_data);

            // Trigger attempt started event
            \mod_cbt\event\attempt_started::create_from_attempt($cbt, $attempt_data)->trigger();
        }

        // 5. Fetch stripped questions
        $questions_snapshot = muc_snapshot_store::get_question_snapshot($cbt);

        // Sort questions according to attempt's question_order
        if (!empty($attempt->question_order)) {
            $map = [];
            foreach ($questions_snapshot as $q) {
                $map[$q['questionId']] = $q;
            }
            $sorted = [];
            $slot = 1;
            foreach ($attempt->question_order as $qid) {
                if (isset($map[$qid])) {
                    $item = $map[$qid];
                    $item['slot'] = $slot++;
                    $sorted[] = $item;
                }
            }
            if (!empty($sorted)) {
                $questions_snapshot = $sorted;
            }
        }

        $remaining_secs = $attempt->calculate_remaining_seconds((int)$cbt->timelimit, $now);

        return [
            'examId' => (int)$cbt->id,
            'title' => (string)$cbt->name,
            'durationSeconds' => (int)$cbt->timelimit,
            'maxStrikes' => (int)$cbt->maxstrikes,
            'serverTimestamp' => $now,
            'attempt' => [
                'attemptId' => $attempt->id,
                'timeStart' => $attempt->timestart,
                'extraTimeSeconds' => $attempt->extra_time,
                'remainingSeconds' => $remaining_secs,
                'strikeCount' => $attempt->strike_count,
                'status' => $attempt->status,
                'syncVersion' => $attempt->sync_version,
            ],
            'questions' => $questions_snapshot,
        ];
    }
}

