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
use mod_cbt\application\usecase\finalize_attempt;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to finalize and submit student exam attempt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_exam extends external_api {

    /**
     * Parameter definitions.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The ID of the attempt to submit'),
        ]);
    }

    /**
     * Finalize and submit attempt.
     *
     * @param int $attemptid
     * @return array
     */
    public static function execute(int $attemptid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
        ]);

        $attempt_rec = $DB->get_record('cbt_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cbt = $DB->get_record('cbt', ['id' => $attempt_rec->cbtid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('cbt', $cbt->id, $cbt->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/cbt:attempt', $context);

        if ((int)$attempt_rec->userid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'Cannot submit another user attempt');
        }

        $usecase = new finalize_attempt();
        $result = $usecase->execute($params['attemptid']);

        return [
            'success' => $result['success'],
            'sumgrades' => (float)$result['sumgrades'],
            'timefinish' => (int)$result['timefinish'],
        ];
    }

    /**
     * Return structure description.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether submit was successful'),
            'sumgrades' => new external_value(PARAM_FLOAT, 'Final calculated score'),
            'timefinish' => new external_value(PARAM_INT, 'Submission timestamp'),
        ]);
    }
}
