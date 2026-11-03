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

namespace mod_cbt\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_cbt\domain\entity\exam_attempt;
use mod_cbt\domain\entity\strike_policy;
use mod_cbt\infrastructure\persistence\db_attempt_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to report anti-cheat violation events.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_violation extends external_api {

    /**
     * Parameter definitions.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The ID of the attempt'),
            'event_type' => new external_value(PARAM_ALPHANUMEXT, 'Violation event type (TAB_BLUR, FULLSCREEN_EXIT, DEVTOOLS_OPEN)'),
            'details' => new external_value(PARAM_RAW, 'Optional details or client context JSON', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Process violation report.
     *
     * @param int $attemptid
     * @param string $event_type
     * @param string $details
     * @return array
     */
    public static function execute(int $attemptid, string $event_type, string $details = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'event_type' => $event_type,
            'details' => $details,
        ]);

        $attempt_rec = $DB->get_record('cbt_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cbt = $DB->get_record('cbt', ['id' => $attempt_rec->cbtid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('cbt', $cbt->id, $cbt->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/cbt:attempt', $context);

        if ((int)$attempt_rec->userid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'Cannot report violation for another user attempt');
        }

        $repo = new db_attempt_repository();
        $attempt = exam_attempt::from_record($attempt_rec);

        if ($attempt->is_locked() || $attempt->is_submitted()) {
            return [
                'strike_count' => $attempt->strike_count,
                'locked' => $attempt->is_locked(),
                'status' => $attempt->status,
                'message' => 'Attempt already in final or locked status',
            ];
        }

        $eval = strike_policy::evaluate($params['event_type'], $attempt->strike_count, (int)$cbt->maxstrikes);
        $new_status = $eval['should_lock'] ? exam_attempt::STATUS_LOCKED : exam_attempt::STATUS_INPROGRESS;

        // Update database
        $repo->update_strikes($attempt->id, $eval['new_strikes'], $new_status);

        // Record log
        $details_array = json_decode($params['details'], true) ?: ['raw' => $params['details']];
        $repo->log_violation($attempt->id, $params['event_type'], $details_array);

        // Trigger locked event if locked
        if ($eval['should_lock']) {
            $attempt->strike_count = $eval['new_strikes'];
            \mod_cbt\event\attempt_locked::create_from_attempt($cbt, $attempt_rec, $eval['reason'])->trigger();
        }

        return [
            'strike_count' => $eval['new_strikes'],
            'locked' => $eval['should_lock'],
            'status' => $new_status,
            'message' => $eval['reason'],
        ];
    }

    /**
     * Return structure description.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'strike_count' => new external_value(PARAM_INT, 'New total strikes'),
            'locked' => new external_value(PARAM_BOOL, 'Whether attempt is locked'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Attempt status'),
            'message' => new external_value(PARAM_TEXT, 'Evaluation reason or notice'),
        ]);
    }
}

