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

namespace mod_cbt\domain\entity;

defined('MOODLE_INTERNAL') || die();

/**
 * Domain entity representing a student's CBT exam attempt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_attempt {
    public const STATUS_INPROGRESS = 'inprogress';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ABANDONED = 'abandoned';

    public int $id;
    public int $cbtid;
    public int $userid;
    public string $status;
    public int $timestart;
    public int $timefinish;
    public int $extra_time;
    public int $strike_count;
    public string $device_hash;
    public string $ip_address;
    public array $question_order;
    public array $answers;
    public int $sync_version;
    public ?float $sumgrades;

    /**
     * Create an exam_attempt entity from a database record.
     *
     * @param \stdClass $record
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        $entity = new self();
        $entity->id = (int)$record->id;
        $entity->cbtid = (int)$record->cbtid;
        $entity->userid = (int)$record->userid;
        $entity->status = (string)$record->status;
        $entity->timestart = (int)$record->timestart;
        $entity->timefinish = (int)$record->timefinish;
        $entity->extra_time = (int)($record->extra_time ?? 0);
        $entity->strike_count = (int)($record->strike_count ?? 0);
        $entity->device_hash = (string)($record->device_hash ?? '');
        $entity->ip_address = (string)($record->ip_address ?? '');

        // Decode question order
        if (!empty($record->question_order)) {
            $decoded = json_decode($record->question_order, true);
            $entity->question_order = is_array($decoded) ? $decoded : [];
        } else {
            $entity->question_order = [];
        }

        // Decode answers JSON map
        if (!empty($record->answers_json)) {
            $decoded = json_decode($record->answers_json, true);
            $entity->answers = is_array($decoded) ? $decoded : [];
        } else {
            $entity->answers = [];
        }

        $entity->sync_version = (int)($record->sync_version ?? 0);
        $entity->sumgrades = isset($record->sumgrades) ? (float)$record->sumgrades : null;

        return $entity;
    }

    /**
     * Check if attempt is currently active and can be interacted with.
     *
     * @return bool
     */
    public function is_active(): bool {
        return $this->status === self::STATUS_INPROGRESS;
    }

    /**
     * Check if attempt is locked by anti-cheat.
     *
     * @return bool
     */
    public function is_locked(): bool {
        return $this->status === self::STATUS_LOCKED;
    }

    /**
     * Check if attempt has already been submitted.
     *
     * @return bool
     */
    public function is_submitted(): bool {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Calculate remaining time in seconds based on timelimit, timestart, and extra_time.
     *
     * @param int $timelimit Duration limit in seconds (0 = unlimited).
     * @param int|null $now Server timestamp to compare against.
     * @return int Remaining seconds, minimum 0.
     */
    public function calculate_remaining_seconds(int $timelimit, ?int $now = null): int {
        if ($timelimit <= 0) {
            return 86400; // Arbitrary 24h if unlimited
        }

        $current_time = $now ?? time();
        $total_allowed = $this->timestart + $timelimit + $this->extra_time;
        $remaining = $total_allowed - $current_time;

        return max(0, $remaining);
    }

    /**
     * Check if the attempt time has expired.
     *
     * @param int $timelimit
     * @param int|null $now
     * @return bool
     */
    public function is_time_expired(int $timelimit, ?int $now = null): bool {
        if ($timelimit <= 0) {
            return false;
        }
        return $this->calculate_remaining_seconds($timelimit, $now) <= 0;
    }

    /**
     * Apply incoming batch answers idempotently with optimistic locking.
     *
     * @param array $batch_answers Map of { question_id: { ans: string|array, flag: bool, ts: int } }
     * @param int $client_sync_version Client reported version.
     * @return bool True if applied, false if stale version rejected.
     */
    public function apply_batch_answers(array $batch_answers, int $client_sync_version): bool {
        if ($client_sync_version < $this->sync_version) {
            // Merge newer answers even on stale version based on timestamp
            foreach ($batch_answers as $qid => $item) {
                if (!isset($this->answers[$qid])) {
                    $this->answers[$qid] = $item;
                } else {
                    $existing_ts = $this->answers[$qid]['ts'] ?? 0;
                    $incoming_ts = $item['ts'] ?? 0;
                    if ($incoming_ts >= $existing_ts) {
                        $this->answers[$qid] = $item;
                    }
                }
            }
            $this->sync_version++;
            return true;
        }

        // Standard in-order merge
        foreach ($batch_answers as $qid => $item) {
            $this->answers[$qid] = $item;
        }
        $this->sync_version = max($this->sync_version + 1, $client_sync_version + 1);

        return true;
    }

    /**
     * Increment strike count and return new total.
     *
     * @param int $amount
     * @return int
     */
    public function increment_strikes(int $amount = 1): int {
        $this->strike_count += $amount;
        return $this->strike_count;
    }

    /**
     * Lock the attempt.
     */
    public function lock(): void {
        $this->status = self::STATUS_LOCKED;
    }

    /**
     * Unlock the attempt (proctor action).
     */
    public function unlock(): void {
        $this->status = self::STATUS_INPROGRESS;
        $this->strike_count = 0;
    }

    /**
     * Add extra time in seconds.
     *
     * @param int $seconds
     */
    public function add_extra_time(int $seconds): void {
        $this->extra_time += max(0, $seconds);
    }
}

