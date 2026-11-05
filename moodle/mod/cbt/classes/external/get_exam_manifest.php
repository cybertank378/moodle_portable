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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_cbt\application\usecase\authenticate_attempt;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to authenticate and return stripped exam manifest.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_exam_manifest extends external_api {

    /**
     * Parameter definitions.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cbtid' => new external_value(PARAM_INT, 'The ID of the CBT activity instance'),
            'token' => new external_value(PARAM_ALPHANUMEXT, 'Room token if required by exam', VALUE_DEFAULT, ''),
            'device_hash' => new external_value(PARAM_ALPHANUMEXT, 'Client device fingerprint hash', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Authenticate attempt and return exam manifest.
     *
     * @param int $cbtid
     * @param string $token
     * @param string $device_hash
     * @return array
     */
    public static function execute(int $cbtid, string $token = '', string $device_hash = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cbtid' => $cbtid,
            'token' => $token,
            'device_hash' => $device_hash,
        ]);

        $cbt = $DB->get_record('cbt', ['id' => $params['cbtid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('cbt', $cbt->id, $cbt->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/cbt:attempt', $context);

        $ip = getremoteaddr();
        $usecase = new authenticate_attempt();

        return $usecase->execute(
            $cbt,
            (int)$USER->id,
            $params['token'],
            $params['device_hash'],
            $ip
        );
    }

    /**
     * Return structure description.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'examId' => new external_value(PARAM_INT, 'CBT ID'),
            'title' => new external_value(PARAM_TEXT, 'Exam Title'),
            'durationSeconds' => new external_value(PARAM_INT, 'Duration in seconds'),
            'maxStrikes' => new external_value(PARAM_INT, 'Max strikes'),
            'serverTimestamp' => new external_value(PARAM_INT, 'Server timestamp'),
            'attempt' => new external_single_structure([
                'attemptId' => new external_value(PARAM_INT, 'Attempt ID'),
                'timeStart' => new external_value(PARAM_INT, 'Start timestamp'),
                'extraTimeSeconds' => new external_value(PARAM_INT, 'Extra seconds added'),
                'remainingSeconds' => new external_value(PARAM_INT, 'Remaining seconds'),
                'strikeCount' => new external_value(PARAM_INT, 'Current strikes'),
                'status' => new external_value(PARAM_ALPHANUMEXT, 'Status'),
                'syncVersion' => new external_value(PARAM_INT, 'Sync version'),
            ]),
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'slot' => new external_value(PARAM_INT, 'Question display slot'),
                    'questionId' => new external_value(PARAM_INT, 'Question bank ID'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Question type'),
                    'stemHtml' => new external_value(PARAM_RAW, 'Question stem HTML'),
                    'options' => new external_multiple_structure(
                        new external_single_structure([
                            'key' => new external_value(PARAM_RAW, 'Option key identifier (A, B, etc.)'),
                            'html' => new external_value(PARAM_RAW, 'Option content HTML'),
                        ]),
                        'Choices list',
                        VALUE_OPTIONAL
                    ),
                ])
            ),
        ]);
    }
}

