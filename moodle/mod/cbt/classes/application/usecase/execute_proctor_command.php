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
use mod_cbt\domain\entity\strike_policy;
use mod_cbt\infrastructure\persistence\db_attempt_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to execute proctor intervention commands.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execute_proctor_command {
    private db_attempt_repository $repository;

    public function __construct(?db_attempt_repository $repository = null) {
        $this->repository = $repository ?? new db_attempt_repository();
    }

    /**
     * Execute proctor command on an attempt.
     *
     * @param int $attemptid
     * @param string $action Command: 'unlock', 'extend_time', 'reset_device', 'force_submit'
     * @param array $payload Additional parameters, e.g. [ 'seconds' => 600, 'reason' => '...' ]
     * @return array [ 'success' => bool, 'message' => string, 'attempt' => array ]
     * @throws \moodle_exception
     */
    public function execute(int $attemptid, string $action, array $payload = []): array {
        $record = $this->repository->get_attempt_by_id($attemptid);
        if (!$record) {
            throw new \moodle_exception('invalidattemptid', 'mod_cbt');
        }

        $attempt = exam_attempt::from_record($record);
        $message = '';

        switch ($action) {
            case 'unlock':
                $this->repository->update_strikes($attempt->id, 0, exam_attempt::STATUS_INPROGRESS);
                $this->repository->log_violation($attempt->id, strike_policy::EVENT_PROCTOR_UNLOCK, $payload);
                $attempt->unlock();
                $message = 'Attempt unlocked successfully.';
                break;

            case 'extend_time':
                $seconds = (int)($payload['seconds'] ?? 300);
                $this->repository->add_extra_time($attempt->id, $seconds);
                $this->repository->log_violation($attempt->id, strike_policy::EVENT_TIME_EXTEND, $payload);
                $attempt->add_extra_time($seconds);
                $message = "Added {$seconds} seconds extra time.";
                break;

            case 'reset_device':
                $this->repository->reset_device_binding($attempt->id);
                $this->repository->log_violation($attempt->id, strike_policy::EVENT_RESET_DEVICE, $payload);
                $attempt->device_hash = '';
                $message = 'Device lock unbound. Student can resume on another computer.';
                break;

            case 'force_submit':
                $finalizer = new finalize_attempt($this->repository);
                $res = $finalizer->execute($attempt->id);
                $this->repository->log_violation($attempt->id, strike_policy::EVENT_FORCE_SUBMIT, $payload);
                $attempt->status = exam_attempt::STATUS_SUBMITTED;
                $attempt->sumgrades = $res['sumgrades'];
                $message = 'Attempt force submitted by proctor.';
                break;

            default:
                throw new \moodle_exception('invalidaction', 'mod_cbt');
        }

        return [
            'success' => true,
            'message' => $message,
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'strike_count' => $attempt->strike_count,
                'extra_time' => $attempt->extra_time,
                'sumgrades' => $attempt->sumgrades,
            ],
        ];
    }
}
