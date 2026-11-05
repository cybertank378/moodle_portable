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
use mod_cbt\application\usecase\batch_sync_answers;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service for atomic optimistic batch answer sync.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_batch_answers extends external_api {

    /**
     * Parameter definitions.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The ID of the attempt'),
            'sync_version' => new external_value(PARAM_INT, 'Client sync sequence version'),
            'answers_json' => new external_value(PARAM_RAW, 'JSON payload of answer batch queue'),
        ]);
    }

    /**
     * Execute answer synchronization.
     *
     * @param int $attemptid
     * @param int $sync_version
     * @param string $answers_json
     * @return array
     */
    public static function execute(int $attemptid, int $sync_version, string $answers_json): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'sync_version' => $sync_version,
            'answers_json' => $answers_json,
        ]);

        $attempt_rec = $DB->get_record('cbt_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cbt = $DB->get_record('cbt', ['id' => $attempt_rec->cbtid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('cbt', $cbt->id, $cbt->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/cbt:attempt', $context);

        // Verify that current user owns this attempt
        if ((int)$attempt_rec->userid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'Cannot sync answers for another user attempt');
        }

        $usecase = new batch_sync_answers();
        $result = $usecase->execute($params['attemptid'], $params['sync_version'], $params['answers_json']);

        return [
            'success' => $result['success'],
            'sync_version' => $result['sync_version'],
            'server_time' => $result['server_time'],
            'remaining_seconds' => $result['remaining_seconds'],
            'status' => $result['status'],
            'error' => $result['error'] ?? '',
        ];
    }

    /**
     * Return structure description.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Sync status'),
            'sync_version' => new external_value(PARAM_INT, 'Current server sync version'),
            'server_time' => new external_value(PARAM_INT, 'Current server timestamp'),
            'remaining_seconds' => new external_value(PARAM_INT, 'Remaining time in seconds'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Current attempt status'),
            'error' => new external_value(PARAM_TEXT, 'Error message if failed', VALUE_DEFAULT, ''),
        ]);
    }
}

