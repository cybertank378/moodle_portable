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
use mod_cbt\domain\service\score_calculator;
use mod_cbt\infrastructure\cache\muc_snapshot_store;
use mod_cbt\infrastructure\persistence\db_attempt_repository;
use mod_cbt\task\push_grades_task;

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to finalize and grade attempt, queuing gradebook push asynchronously.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalize_attempt {
    private db_attempt_repository $repository;

    public function __construct(?db_attempt_repository $repository = null) {
        $this->repository = $repository ?? new db_attempt_repository();
    }

    /**
     * Finalize attempt, calculate score from in-memory answer keys, and queue adhoc grade sync.
     *
     * @param int $attemptid
     * @return array [ 'success' => bool, 'sumgrades' => float, 'timefinish' => int ]
     * @throws \moodle_exception
     */
    public function execute(int $attemptid): array {
        global $DB;

        $record = $this->repository->get_attempt_by_id($attemptid);
        if (!$record) {
            throw new \moodle_exception('invalidattemptid', 'mod_cbt');
        }

        $attempt = exam_attempt::from_record($record);
        if ($attempt->is_submitted()) {
            return [
                'success' => true,
                'sumgrades' => (float)$attempt->sumgrades,
                'timefinish' => $attempt->timefinish,
            ];
        }

        $cbt = $DB->get_record('cbt', ['id' => $attempt->cbtid]);
        if (!$cbt) {
            throw new \moodle_exception('invalidcbtid', 'mod_cbt');
        }

        // 1. Fetch private answer keys from MUC cache (zero DB query to questions)
        $answer_keys = muc_snapshot_store::get_answer_keys($cbt);

        // 2. Pure in-memory score calculation
        $target_grade = (float)($cbt->grade ?? 100.0);
        $score_result = score_calculator::calculate($answer_keys, $attempt->answers, $target_grade);
        $final_grade = $score_result['sumgrades'];

        $now = time();
        $this->repository->update_status($attempt->id, exam_attempt::STATUS_SUBMITTED, $now, $final_grade);
        $attempt->status = exam_attempt::STATUS_SUBMITTED;
        $attempt->timefinish = $now;
        $attempt->sumgrades = $final_grade;

        // 3. Queue Ad-hoc Task to push grade to Moodle Gradebook asynchronously
        // Prevents database deadlocks and connection exhaustion under concurrent submits (ISSUE-PHP-01)
        try {
            $task = new push_grades_task();
            $task->set_custom_data([
                'cbtid' => (int)$cbt->id,
                'attemptid' => (int)$attempt->id,
                'userid' => (int)$attempt->userid,
                'grade' => $final_grade,
            ]);
            \core\task\manager::queue_adhoc_task($task);
        } catch (\Throwable $e) {
            // Fallback direct grade update if queue unavailable
            require_once(__DIR__ . '/../../../lib.php');
            if (function_exists('cbt_update_grades')) {
                cbt_update_grades($cbt, $attempt->userid);
            }
        }

        // 4. Trigger submitted event
        \mod_cbt\event\attempt_submitted::create_from_attempt($cbt, $record, $final_grade)->trigger();

        return [
            'success' => true,
            'sumgrades' => $final_grade,
            'timefinish' => $now,
        ];
    }
}

