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
use mod_cbt\application\usecase\manage_classes_and_users;
use mod_cbt\application\usecase\manage_exam_schedule;

defined('MOODLE_INTERNAL') || die();

/**
 * Unified bidirectional master data sync Web Service for Aksaventra CBT.
 *
 * Supports pulling courses, classes/cohorts, students, CBT exams, and questions,
 * as well as pushing courses and questions from Next.js into Moodle.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_master extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(
                PARAM_ALPHANUMEXT,
                'Action: pull_all, pull_courses, pull_classes, pull_students, pull_exams, pull_questions, push_course, push_question'
            ),
            'payload' => new external_value(PARAM_RAW, 'JSON payload', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function execute(string $action, string $payload = '{}'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action,
            'payload' => $payload,
        ]);

        $data = json_decode($params['payload'], true) ?: [];
        $act = $params['action'];

        switch ($act) {
            case 'pull_courses':
                $result = ['courses' => self::fetch_courses()];
                break;

            case 'pull_classes':
                $uc = new manage_classes_and_users();
                $result = ['classes' => $uc->list_classes()];
                break;

            case 'pull_students':
                $uc = new manage_classes_and_users();
                $result = ['students' => $uc->list_all_students()];
                break;

            case 'pull_exams':
                $uc = new manage_exam_schedule();
                $result = ['exams' => $uc->list_schedules()];
                break;

            case 'pull_questions':
                $result = ['questions' => self::fetch_questions()];
                break;

            case 'push_course':
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $res = self::upsert_course($data);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'record_id' => $res['course_id'],
                    'data_json' => json_encode($res),
                ];

            case 'push_question':
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $res = self::upsert_question($data);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'record_id' => $res['question_id'],
                    'data_json' => json_encode($res),
                ];

            case 'delete_entity':
                \mod_cbt\observer::$suppress_webhook = true;
                try {
                    $res = self::delete_remote_entity($data);
                } finally {
                    \mod_cbt\observer::$suppress_webhook = false;
                }
                return [
                    'success' => $res['success'],
                    'message' => $res['message'],
                    'record_id' => (int)($data['moodle_id'] ?? 0),
                    'data_json' => json_encode($res),
                ];

            case 'pull_all':
            default:
                $groupsuc = new manage_classes_and_users();
                $examuc = new manage_exam_schedule();
                $errors = [];

                $courses = [];
                try {
                    $courses = self::fetch_courses();
                } catch (\Throwable $e) {
                    $errors[] = self::format_exception('courses', $e);
                }

                $classes = [];
                try {
                    $classes = $groupsuc->list_classes();
                } catch (\Throwable $e) {
                    $errors[] = self::format_exception('classes', $e);
                }

                $students = [];
                try {
                    $students = $groupsuc->list_all_students();
                } catch (\Throwable $e) {
                    $errors[] = self::format_exception('students', $e);
                }

                $exams = [];
                try {
                    $exams = $examuc->list_schedules();
                } catch (\Throwable $e) {
                    $errors[] = self::format_exception('exams', $e);
                }

                $questions = [];
                try {
                    $questions = self::fetch_questions();
                } catch (\Throwable $e) {
                    $errors[] = self::format_exception('questions', $e);
                }

                $result = [
                    'courses' => $courses,
                    'classes' => $classes,
                    'students' => $students,
                    'exams' => $exams,
                    'questions' => $questions,
                    'errors' => $errors,
                    'synced_at' => time(),
                ];
                break;
        }

        return [
            'success' => true,
            'message' => 'Master data retrieved successfully',
            'record_id' => 0,
            'data_json' => json_encode($result),
        ];
    }

    private static function format_exception(string $section, \Throwable $e): string {
        $msg = $section . ': ' . $e->getMessage();
        if (property_exists($e, 'debuginfo') && !empty($e->debuginfo)) {
            $msg .= ' [' . $e->debuginfo . ']';
        }
        return $msg;
    }

    /**
     * Fetch all visible Moodle courses (excluding site course id=1).
     */
    private static function fetch_courses(): array {
        global $DB;

        $records = $DB->get_records_select(
            'course',
            'id > 1 AND visible = 1',
            null,
            'fullname ASC',
            'id, fullname, shortname, summary'
        );

        $courses = [];
        foreach ($records as $c) {
            $courses[] = [
                'id' => (int)$c->id,
                'name' => $c->fullname,
                'short_name' => $c->shortname,
                'description' => strip_tags((string)($c->summary ?? '')),
            ];
        }
        return $courses;
    }

    /**
     * Fetch Moodle question bank entries (compatible with Moodle 4.x/5.x schema).
     */
    private static function fetch_questions(): array {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('question')) {
            return [];
        }

        $hasAnswersTable = $dbman->table_exists('question_answers');
        $records = $DB->get_records_select(
            'question',
            'parent = 0',
            null,
            'id DESC',
            'id, name, questiontext, qtype, defaultmark, generalfeedback',
            0,
            500
        );
        $list = [];

        foreach ($records as $q) {
            $answers = $hasAnswersTable
                ? $DB->get_records('question_answers', ['question' => $q->id], 'id ASC')
                : [];
            $options = [];
            $idx = 0;
            $labels = ['A', 'B', 'C', 'D', 'E', 'F'];
            foreach ($answers as $ans) {
                $options[] = [
                    'id' => 'moodle-ans-' . $ans->id,
                    'label' => $labels[$idx] ?? (string)($idx + 1),
                    'text' => trim(strip_tags((string)$ans->answer)),
                    'isCorrect' => ((float)$ans->fraction) > 0,
                ];
                $idx++;
            }

            $list[] = [
                'id' => (int)$q->id,
                'code' => 'MDL-Q-' . $q->id,
                'name' => $q->name,
                'type' => (string)$q->qtype,
                'stem' => (string)$q->questiontext,
                'points' => (float)($q->defaultmark ?: 1),
                'explanation' => strip_tags((string)($q->generalfeedback ?? '')),
                'options' => $options,
            ];
        }

        return $list;
    }

    /**
     * Create or update a Moodle course from Next.js Subject.
     */
    private static function upsert_course(array $data): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $moodleCourseId = (int)($data['moodle_course_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \moodle_exception('invalidcourse', 'error');
        }

        $shortname = trim((string)($data['short_name'] ?? ''));
        if ($shortname === '') {
            $shortname = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        }

        $existing = null;
        if ($moodleCourseId > 1) {
            $existing = $DB->get_record('course', ['id' => $moodleCourseId]);
        }
        if (!$existing) {
            $existing = $DB->get_record('course', ['fullname' => $name]);
        }
        if (!$existing) {
            $existing = $DB->get_record('course', ['shortname' => $shortname]);
        }

        if ($existing) {
            $existing->fullname = $name;
            if (!empty($data['short_name'])) {
                $existing->shortname = $shortname;
            }
            $existing->summary = (string)($data['description'] ?? $existing->summary);
            $existing->timemodified = time();
            $DB->update_record('course', $existing);
            return [
                'success' => true,
                'course_id' => (int)$existing->id,
                'message' => "Mata pelajaran {$name} diperbarui di Moodle.",
            ];
        }

        // Ensure a valid category exists
        $category = $DB->get_record_sql('SELECT id FROM {course_categories} ORDER BY id ASC', [], IGNORE_MULTIPLE);
        $categoryid = $category ? (int)$category->id : 1;

        $coursedata = new \stdClass();
        $coursedata->category = $categoryid;
        $coursedata->fullname = $name;
        $coursedata->shortname = $shortname . '-' . substr((string)time(), -4);
        $coursedata->summary = (string)($data['description'] ?? '');
        $coursedata->summaryformat = FORMAT_HTML;
        $coursedata->format = 'topics';
        $coursedata->visible = 1;

        $created = create_course($coursedata);

        return [
            'success' => true,
            'course_id' => (int)$created->id,
            'message' => "Mata pelajaran {$name} berhasil dibuat di Moodle.",
        ];
    }

    /**
     * Create or update a question in Moodle question tables from Next.js Question Bank.
     */
    private static function upsert_question(array $data): array {
        global $DB, $USER;

        $now = time();
        $moodleid = (int)($data['moodle_question_id'] ?? 0);
        $stem = (string)($data['stem'] ?? '');
        $qtype = (string)($data['type'] ?? 'multichoice');
        $points = (float)($data['points'] ?? 1.0);
        $code = trim((string)($data['code'] ?? 'CBT-Q-' . $now));
        $explanation = (string)($data['explanation'] ?? '');
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];

        // Map Aksaventra mcma -> Moodle multichoice
        $moodleQtype = $qtype === 'mcma' ? 'multichoice' : $qtype;

        if ($moodleid > 0 && $DB->record_exists('question', ['id' => $moodleid])) {
            $q = $DB->get_record('question', ['id' => $moodleid]);
            $q->name = $code;
            $q->questiontext = $stem;
            $q->defaultmark = $points;
            $q->generalfeedback = $explanation;
            $q->timemodified = $now;
            $DB->update_record('question', $q);
            $qid = (int)$q->id;
        } else {
            $q = new \stdClass();
            $q->parent = 0;
            $q->name = $code;
            $q->questiontext = $stem;
            $q->questiontextformat = FORMAT_HTML;
            $q->generalfeedback = $explanation;
            $q->generalfeedbackformat = FORMAT_HTML;
            $q->defaultmark = $points;
            $q->penalty = 0.3333333;
            $q->qtype = $moodleQtype;
            $q->length = 1;
            $q->stamp = make_unique_id_code();
            $q->timecreated = $now;
            $q->timemodified = $now;
            $q->createdby = $USER->id ?? 2;
            $q->modifiedby = $USER->id ?? 2;
            $qid = (int)$DB->insert_record('question', $q);
        }

        // Sync answers
        $DB->delete_records('question_answers', ['question' => $qid]);
        foreach ($options as $opt) {
            $ans = new \stdClass();
            $ans->question = $qid;
            $ans->answer = (string)($opt['text'] ?? '');
            $ans->answerformat = FORMAT_HTML;
            $ans->fraction = !empty($opt['isCorrect']) ? 1.0 : 0.0;
            $ans->feedback = '';
            $ans->feedbackformat = FORMAT_HTML;
            $DB->insert_record('question_answers', $ans);
        }

        return [
            'success' => true,
            'question_id' => $qid,
            'message' => "Soal {$code} berhasil disinkronkan ke Moodle.",
        ];
    }

    /**
     * Delete or deactivate a Moodle entity pushed from Aksaventra CBT.
     */
    private static function delete_remote_entity(array $data): array {
        global $DB, $CFG;

        $entity = (string)($data['entity'] ?? '');
        $moodleid = (int)($data['moodle_id'] ?? 0);
        if ($moodleid <= 0) {
            return ['success' => false, 'message' => 'ID Moodle tidak valid'];
        }

        switch ($entity) {
            case 'cohort':
                $DB->delete_records('cohort_members', ['cohortid' => $moodleid]);
                $DB->delete_records('cohort', ['id' => $moodleid]);
                return ['success' => true, 'message' => "Cohort #{$moodleid} dihapus dari Moodle."];

            case 'user':
                require_once($CFG->dirroot . '/user/lib.php');
                $user = $DB->get_record('user', ['id' => $moodleid, 'deleted' => 0]);
                if ($user) {
                    delete_user($user);
                }
                return ['success' => true, 'message' => "User #{$moodleid} dihapus dari Moodle."];

            case 'course':
                if ($moodleid > 1) {
                    $DB->set_field('course', 'visible', 0, ['id' => $moodleid]);
                }
                return ['success' => true, 'message' => "Course #{$moodleid} disembunyikan/dihapus."];

            case 'cbt':
                $DB->delete_records('cbt_roster', ['cbtid' => $moodleid]);
                $DB->delete_records('cbt', ['id' => $moodleid]);
                return ['success' => true, 'message' => "CBT #{$moodleid} dihapus dari Moodle."];

            case 'question':
                $DB->delete_records('question_answers', ['question' => $moodleid]);
                $DB->delete_records('question', ['id' => $moodleid]);
                return ['success' => true, 'message' => "Soal #{$moodleid} dihapus dari Moodle."];

            default:
                return ['success' => false, 'message' => 'Entitas delete tidak dikenal'];
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Status'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'record_id' => new external_value(PARAM_INT, 'Created/updated record ID', VALUE_OPTIONAL, 0),
            'data_json' => new external_value(PARAM_RAW, 'JSON-encoded sync payload'),
        ]);
    }
}
