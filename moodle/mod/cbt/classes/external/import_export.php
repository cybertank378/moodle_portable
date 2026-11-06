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
use mod_cbt\application\usecase\import_export_service;

defined('MOODLE_INTERNAL') || die();

/**
 * External API service to handle batch import/export for students, questions, and grades.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_export extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHA, 'Action: import_students, export_students, import_questions, export_results'),
            'cbtid' => new external_value(PARAM_INT, 'The CBT instance ID', VALUE_DEFAULT, 0),
            'payload' => new external_value(PARAM_RAW, 'CSV string payload for import actions', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $action, int $cbtid = 0, string $payload = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action,
            'cbtid' => $cbtid,
            'payload' => $payload,
        ]);

        $service = new import_export_service();

        switch ($params['action']) {
            case 'import_students':
                $res = $service->import_students($params['payload'], $params['cbtid']);
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'processed_count' => $res['imported_count'],
                    'csv_data' => '',
                    'errors' => $res['errors'],
                ];

            case 'export_students':
                $csv = $service->export_students();
                return [
                    'success' => true,
                    'message' => 'Daftar siswa berhasil diekspor.',
                    'processed_count' => 0,
                    'csv_data' => $csv,
                    'errors' => [],
                ];

            case 'import_questions':
                $res = $service->import_questions($params['cbtid'], $params['payload']);
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'processed_count' => $res['imported_count'],
                    'csv_data' => '',
                    'errors' => $res['errors'],
                ];

            case 'export_results':
            default:
                $csv = $service->export_exam_results($params['cbtid']);
                return [
                    'success' => true,
                    'message' => 'Rekapitulasi nilai berhasil diekspor.',
                    'processed_count' => 0,
                    'csv_data' => $csv,
                    'errors' => [],
                ];
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
            'processed_count' => new external_value(PARAM_INT, 'Number of processed items'),
            'csv_data' => new external_value(PARAM_RAW, 'Exported CSV content (with UTF-8 BOM)'),
            'errors' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Error message')
            ),
        ]);
    }
}
