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

            case 'verify_user_credentials':
                $res = self::verify_user_credentials($data);
                return [
                    'success' => $res['valid'],
                    'message' => $res['message'],
                    'record_id' => (int)($res['user_id'] ?? 0),
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
     * Fetch Moodle question bank entries (Moodle 5.x versioned schema with graceful fallback).
     * Only retrieves the latest version with status = 'ready' so historical/draft/hidden revisions
     * are never duplicated in Aksaventra CBT.
     */
    private static function fetch_questions(): array {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('question')) {
            return [];
        }

        $hasVersioning = $dbman->table_exists('question_versions')
            && $dbman->table_exists('question_bank_entries');
        $hasAnswersTable = $dbman->table_exists('question_answers');
        $hasNumericalTable = $dbman->table_exists('question_numerical');
        $hasMcOptionsTable = $dbman->table_exists('qtype_multichoice_options');

        if ($hasVersioning) {
            $sql = "SELECT q.id, q.qtype, q.name, q.questiontext, q.questiontextformat,
                           q.defaultmark, q.generalfeedback, qbe.idnumber AS code,
                           qbe.id AS questionbankentryid, qv.version
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE q.parent = 0
                       AND qv.status = 'ready'
                       AND qv.version = (
                           SELECT MAX(v2.version)
                             FROM {question_versions} v2
                            WHERE v2.questionbankentryid = qbe.id
                       )
                  ORDER BY q.id DESC";
            $records = $DB->get_records_sql($sql, [], 0, 500);
        } else {
            $records = $DB->get_records_select(
                'question',
                'parent = 0',
                null,
                'id DESC',
                'id, name, questiontext, questiontextformat, qtype, defaultmark, generalfeedback',
                0,
                500
            );
        }

        $list = [];
        $labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        foreach ($records as $q) {
            $qid = (int)$q->id;
            $answers = $hasAnswersTable
                ? $DB->get_records('question_answers', ['question' => $qid], 'id ASC')
                : [];

            $options = [];
            $idx = 0;
            $positiveCount = 0;
            $primaryAnswerId = 0;
            $highestFraction = -1.0;

            foreach ($answers as $ans) {
                $frac = (float)$ans->fraction;
                $isCorrect = $frac > 0.0;
                if ($isCorrect) {
                    $positiveCount++;
                }
                if ($frac > $highestFraction) {
                    $highestFraction = $frac;
                    $primaryAnswerId = (int)$ans->id;
                }

                $options[] = [
                    'id' => 'moodle-ans-' . $ans->id,
                    'label' => $labels[$idx] ?? (string)($idx + 1),
                    'text' => self::clean_question_html((string)$ans->answer, 'answer', (int)$ans->id),
                    'fraction' => $frac,
                    'isCorrect' => $isCorrect,
                ];
                $idx++;
            }

            // Determine CBT question type (map multi-response multichoice -> mcma).
            $effectiveType = (string)$q->qtype;
            if ($effectiveType === 'multichoice') {
                $isSingle = true;
                if ($hasMcOptionsTable) {
                    try {
                        $mcopt = $DB->get_record('qtype_multichoice_options', ['questionid' => $qid]);
                        if ($mcopt && isset($mcopt->single)) {
                            $isSingle = ((int)$mcopt->single === 1);
                        } else if ($positiveCount > 1) {
                            $isSingle = false;
                        }
                    } catch (\Throwable $e) {
                        $isSingle = ($positiveCount <= 1);
                    }
                } else if ($positiveCount > 1) {
                    $isSingle = false;
                }
                if (!$isSingle) {
                    $effectiveType = 'mcma';
                }
            }

            // Retrieve tolerance metadata for numerical questions from mdl_question_numerical.
            $tolerance = null;
            if ($q->qtype === 'numerical' && $hasNumericalTable) {
                try {
                    $numrec = null;
                    if ($primaryAnswerId > 0) {
                        $numrec = $DB->get_record('question_numerical', ['answer' => $primaryAnswerId]);
                    }
                    if (!$numrec) {
                        $numrec = $DB->get_record('question_numerical', ['question' => $qid], '*', IGNORE_MULTIPLE);
                    }
                    $tolerance = ($numrec && isset($numrec->tolerance) && is_numeric($numrec->tolerance))
                        ? (float)$numrec->tolerance
                        : 0.0;
                } catch (\Throwable $e) {
                    $tolerance = 0.0;
                }
            }

            $code = (isset($q->code) && trim((string)$q->code) !== '')
                ? trim((string)$q->code)
                : ('MDL-Q-' . $qid);

            $list[] = [
                'id' => $qid,
                'questionbankentryid' => (int)($q->questionbankentryid ?? 0),
                'version' => (int)($q->version ?? 1),
                'code' => $code,
                'name' => $q->name,
                'type' => $effectiveType,
                'stem' => self::clean_question_html((string)$q->questiontext, 'questiontext', $qid),
                'points' => (float)($q->defaultmark ?: 1.0),
                'tolerance' => $tolerance,
                'explanation' => self::clean_question_html((string)($q->generalfeedback ?? ''), 'generalfeedback', $qid),
                'options' => $options,
            ];
        }

        return $list;
    }

    /**
     * Resolves @@PLUGINFILE@@/<filename> into inline data:image/...;base64,... URIs
     * and strips noisy wrapper HTML (<div>, <span>, <p>, <font>) while preserving <img>, <svg>, <table>, <sub>, <sup>.
     */
    private static function clean_question_html(string $raw_html, string $filearea = 'questiontext', int $itemid = 0): string {
        global $DB;
        $html = trim($raw_html);
        if ($html === '') {
            return '';
        }

        if (str_contains($html, '@@PLUGINFILE@@/') && $itemid > 0 && function_exists('get_file_storage')) {
            try {
                $fs = get_file_storage();
                $file_records = $DB->get_records_select(
                    'files',
                    "component = 'question' AND filearea = ? AND itemid = ? AND filename <> '.'",
                    [$filearea, $itemid],
                    'id ASC',
                    'id, filename, mimetype, filesize'
                );
                foreach ($file_records as $frec) {
                    $fname = (string)$frec->filename;
                    if ($fname === '' || (int)$frec->filesize > 2097152) {
                        continue;
                    }
                    $stored = $fs->get_file_by_id((int)$frec->id);
                    if ($stored) {
                        $content = $stored->get_content();
                        if ($content !== false && $content !== '') {
                            $mime = !empty($frec->mimetype) ? (string)$frec->mimetype : 'image/jpeg';
                            $data_uri = 'data:' . $mime . ';base64,' . base64_encode($content);
                            $html = str_replace(
                                ['@@PLUGINFILE@@/' . $fname, '@@PLUGINFILE@@/' . rawurlencode($fname)],
                                $data_uri,
                                $html
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                debugging('clean_question_html file resolution skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|li|h[1-6])>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<!--[\s\S]*?-->/', '', $html) ?? $html;
        $html = preg_replace('/<\/?(?:div|span|p|font|o:p|center|section|article)(?:\s[^>]*)?>/i', '', $html) ?? $html;
        $html = preg_replace('/\s+(?:border|align|hspace|vspace)="[^"]*"/i', '', $html) ?? $html;

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace("\xC2\xA0", ' ', $html);

        $lines = array_map(fn($l) => trim((string)(preg_replace('/[ \t]+/', ' ', $l) ?? $l)), explode("\n", $html));
        $html = trim((string)(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? implode("\n", $lines)));

        return $html;
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
     * Resolve or provision a valid Moodle 5.x question category ID for a course/subject.
     * In Moodle 5.x, question categories belong to a CONTEXT_MODULE (mod_qbank) context.
     * Falls back gracefully to Moodle 4.x / existing categories if needed.
     */
    private static function resolve_default_question_category(array $data): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        // 1. Resolve target course from moodle_course_id or subject name.
        $course = null;
        $courseid = (int)($data['moodle_course_id'] ?? 0);
        if ($courseid > 1) {
            $course = $DB->get_record('course', ['id' => $courseid]);
        }
        if (!$course && !empty($data['subject'])) {
            $subj = trim((string)$data['subject']);
            $course = $DB->get_record('course', ['fullname' => $subj]);
            if (!$course) {
                $course = $DB->get_record('course', ['shortname' => $subj]);
            }
        }
        if (!$course) {
            $course = $DB->get_record_sql(
                'SELECT * FROM {course} WHERE id > 1 AND visible = 1 ORDER BY id ASC',
                [],
                IGNORE_MULTIPLE
            );
        }
        if (!$course) {
            $course = get_site();
        }

        // 2. Moodle 5.x native shared Question Bank module (mod_qbank / CONTEXT_MODULE).
        if ($course && class_exists('\core_question\local\bank\question_bank_helper')) {
            try {
                $modinfo = get_fast_modinfo($course);
                $qbankmodname = \core_question\local\bank\question_bank_helper::get_default_question_bank_activity_name();
                $qbanks = $modinfo->get_instances_of($qbankmodname);
                $cm = !empty($qbanks) ? reset($qbanks) : null;

                if (!$cm) {
                    $cm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
                }

                if ($cm) {
                    $modcontext = \context_module::instance($cm->id);
                    $cat = question_get_default_category($modcontext->id, true);
                    if ($cat && !empty($cat->id)) {
                        return (int)$cat->id;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to legacy/direct category lookup below.
            }
        }

        // 3. Fallback for Moodle 4.x or course context lookup.
        if ($course) {
            try {
                $coursecontext = \context_course::instance($course->id);
                $existingcat = $DB->get_record_select(
                    'question_categories',
                    'contextid = ? AND parent <> 0',
                    [$coursecontext->id],
                    'id ASC',
                    '*',
                    IGNORE_MULTIPLE
                );
                if ($existingcat) {
                    return (int)$existingcat->id;
                }
            } catch (\Throwable $e) {
                // Continue to generic fallback.
            }
        }

        // 4. Use any existing non-top category if available.
        $anycat = $DB->get_record_select('question_categories', 'parent <> 0', null, 'id ASC', '*', IGNORE_MULTIPLE);
        if ($anycat) {
            return (int)$anycat->id;
        }

        // 5. Last resort: create top + default category on course or system context.
        $ctxid = $course ? \context_course::instance($course->id)->id : \context_system::instance()->id;
        $top = $DB->get_record('question_categories', ['contextid' => $ctxid, 'parent' => 0]);
        if (!$top) {
            $top = new \stdClass();
            $top->name = 'top';
            $top->info = '';
            $top->infoformat = FORMAT_HTML;
            $top->contextid = $ctxid;
            $top->parent = 0;
            $top->sortorder = 0;
            $top->stamp = make_unique_id_code();
            $top->id = (int)$DB->insert_record('question_categories', $top);
        }

        $defaultcat = new \stdClass();
        $defaultcat->name = 'Default for Aksaventra CBT';
        $defaultcat->info = 'Kategori bank soal default untuk sinkronisasi Aksaventra CBT.';
        $defaultcat->infoformat = FORMAT_HTML;
        $defaultcat->contextid = $ctxid;
        $defaultcat->parent = (int)$top->id;
        $defaultcat->sortorder = 999;
        $defaultcat->stamp = make_unique_id_code();
        return (int)$DB->insert_record('question_categories', $defaultcat);
    }

    /**
     * Create or update a question in Moodle 5.x question tables from Next.js Question Bank.
     * Registers mdl_question_bank_entries & mdl_question_versions, distributes MCMA fractions
     * proportionally per Moodle rules, sets mdl_qtype_multichoice_options.single, and stores
     * numerical tolerance in mdl_question_numerical.
     */
    private static function upsert_question(array $data): array {
        global $DB, $USER;

        $dbman = $DB->get_manager();
        $now = time();
        $ownerid = !empty($USER->id) ? (int)$USER->id : 2;
        $moodleid = (int)($data['moodle_question_id'] ?? 0);
        $stem = (string)($data['stem'] ?? '');
        $rawType = strtolower(trim((string)($data['type'] ?? 'multichoice')));
        $points = (float)($data['points'] ?? 1.0);
        $code = trim((string)($data['code'] ?? 'CBT-Q-' . $now));
        $explanation = (string)($data['explanation'] ?? '');
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $tolerance = (isset($data['tolerance']) && is_numeric($data['tolerance'])) ? (float)$data['tolerance'] : 0.0;

        // Normalize CBT question type -> Moodle native qtype.
        $isMcma = in_array($rawType, ['mcma', 'multichoice_multi'], true);
        if (in_array($rawType, ['mcma', 'multichoice_multi', 'multichoice_single', 'multichoice'], true)) {
            $moodleQtype = 'multichoice';
        } else {
            $moodleQtype = $rawType;
        }

        // Count correct options to validate MCMA vs single choice.
        $totalCorrect = 0;
        foreach ($options as $opt) {
            if (!empty($opt['isCorrect'])) {
                $totalCorrect++;
            }
        }
        if ($moodleQtype === 'multichoice' && $totalCorrect > 1) {
            $isMcma = true;
        }

        $hasVersioning = $dbman->table_exists('question_bank_entries')
            && $dbman->table_exists('question_versions');
        $qbeid = 0;
        $version = 1;
        $categoryid = 0;

        // Try matching existing question by moodle_question_id or by bank entry idnumber.
        $existingQ = null;
        if ($moodleid > 0) {
            $existingQ = $DB->get_record('question', ['id' => $moodleid]);
        }
        if (!$existingQ && $hasVersioning && $code !== '') {
            $existingEntry = $DB->get_record('question_bank_entries', ['idnumber' => $code], '*', IGNORE_MULTIPLE);
            if ($existingEntry) {
                $latestVer = $DB->get_record_sql(
                    "SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC",
                    [$existingEntry->id],
                    IGNORE_MULTIPLE
                );
                if ($latestVer) {
                    $existingQ = $DB->get_record('question', ['id' => $latestVer->questionid]);
                    $qbeid = (int)$existingEntry->id;
                    $version = (int)$latestVer->version;
                    $categoryid = (int)$existingEntry->questioncategoryid;
                }
            }
        }

        if ($existingQ) {
            $existingQ->name = $code;
            $existingQ->questiontext = $stem;
            $existingQ->defaultmark = $points;
            $existingQ->generalfeedback = $explanation;
            $existingQ->qtype = $moodleQtype;
            $existingQ->timemodified = $now;
            $existingQ->modifiedby = $ownerid;
            $DB->update_record('question', $existingQ);
            $qid = (int)$existingQ->id;

            // Auto-heal missing Moodle 5 bank entry/version rows on legacy pushed questions.
            if ($hasVersioning) {
                $qvExisting = $DB->get_record('question_versions', ['questionid' => $qid], '*', IGNORE_MULTIPLE);
                if ($qvExisting) {
                    $qbeid = (int)$qvExisting->questionbankentryid;
                    $version = (int)$qvExisting->version;
                    if ($qvExisting->status !== 'ready') {
                        $qvExisting->status = 'ready';
                        $DB->update_record('question_versions', $qvExisting);
                    }
                    $entryRec = $DB->get_record('question_bank_entries', ['id' => $qbeid]);
                    if ($entryRec) {
                        $categoryid = (int)$entryRec->questioncategoryid;
                    }
                } else {
                    $categoryid = self::resolve_default_question_category($data);
                    $idnumber = $code !== '' ? $code : null;
                    if ($idnumber !== null && $DB->record_exists('question_bank_entries', [
                        'questioncategoryid' => $categoryid,
                        'idnumber' => $idnumber,
                    ])) {
                        $idnumber = $idnumber . '-' . $qid;
                    }
                    $qbe = new \stdClass();
                    $qbe->questioncategoryid = $categoryid;
                    $qbe->idnumber = $idnumber;
                    $qbe->ownerid = $ownerid;
                    if ($dbman->field_exists('question_bank_entries', 'nextversion')) {
                        $qbe->nextversion = 2;
                    }
                    $qbeid = (int)$DB->insert_record('question_bank_entries', $qbe);

                    $qv = new \stdClass();
                    $qv->questionbankentryid = $qbeid;
                    $qv->version = 1;
                    $qv->questionid = $qid;
                    $qv->status = 'ready';
                    $DB->insert_record('question_versions', $qv);
                    $version = 1;
                }
            }
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
            $q->createdby = $ownerid;
            $q->modifiedby = $ownerid;
            $qid = (int)$DB->insert_record('question', $q);

            // Register question natively in Moodle 5 question_bank_entries & question_versions.
            if ($hasVersioning) {
                $categoryid = self::resolve_default_question_category($data);
                $idnumber = $code !== '' ? $code : null;
                if ($idnumber !== null && $DB->record_exists('question_bank_entries', [
                    'questioncategoryid' => $categoryid,
                    'idnumber' => $idnumber,
                ])) {
                    $idnumber = $idnumber . '-' . $qid;
                }

                $qbe = new \stdClass();
                $qbe->questioncategoryid = $categoryid;
                $qbe->idnumber = $idnumber;
                $qbe->ownerid = $ownerid;
                if ($dbman->field_exists('question_bank_entries', 'nextversion')) {
                    $qbe->nextversion = 2;
                }
                $qbeid = (int)$DB->insert_record('question_bank_entries', $qbe);

                $qv = new \stdClass();
                $qv->questionbankentryid = $qbeid;
                $qv->version = 1;
                $qv->questionid = $qid;
                $qv->status = 'ready';
                $DB->insert_record('question_versions', $qv);
                $version = 1;
            }
        }

        // Sync question_answers with Moodle 5 proportional fraction rules.
        $DB->delete_records('question_answers', ['question' => $qid]);
        if ($moodleQtype === 'numerical' && $dbman->table_exists('question_numerical')) {
            $DB->delete_records('question_numerical', ['question' => $qid]);
        }

        // Handle numerical questions that pass scalar 'answer' without options array.
        if ($moodleQtype === 'numerical' && empty($options) && isset($data['answer'])) {
            $options = [[
                'text' => (string)$data['answer'],
                'isCorrect' => true,
                'tolerance' => $tolerance,
            ]];
            $totalCorrect = 1;
        }

        $mcmaPositive = ($totalCorrect > 0) ? round(1.0 / $totalCorrect, 7) : 0.0;
        $mcmaNegative = ($totalCorrect > 0) ? round(-1.0 / $totalCorrect, 7) : 0.0;

        foreach ($options as $opt) {
            $isCorrect = !empty($opt['isCorrect']);
            if ($moodleQtype === 'multichoice' && $isMcma) {
                $fraction = $isCorrect ? $mcmaPositive : $mcmaNegative;
            } else if ($moodleQtype === 'multichoice') {
                $fraction = $isCorrect ? 1.0 : 0.0;
            } else if (isset($opt['fraction']) && is_numeric($opt['fraction'])) {
                $fraction = (float)$opt['fraction'];
            } else {
                $fraction = $isCorrect ? 1.0 : 0.0;
            }

            $ans = new \stdClass();
            $ans->question = $qid;
            $ans->answer = (string)($opt['text'] ?? $opt['answer'] ?? '');
            $ans->answerformat = ($moodleQtype === 'numerical' || $moodleQtype === 'shortanswer') ? FORMAT_PLAIN : FORMAT_HTML;
            $ans->fraction = $fraction;
            $ans->feedback = (string)($opt['feedback'] ?? '');
            $ans->feedbackformat = FORMAT_HTML;
            $ansid = (int)$DB->insert_record('question_answers', $ans);

            // Store numerical tolerance metadata in mdl_question_numerical.
            if ($moodleQtype === 'numerical' && $dbman->table_exists('question_numerical')) {
                $optTolerance = (isset($opt['tolerance']) && is_numeric($opt['tolerance']))
                    ? (float)$opt['tolerance']
                    : $tolerance;
                $num = new \stdClass();
                $num->question = $qid;
                $num->answer = $ansid;
                $num->tolerance = (string)$optTolerance;
                $DB->insert_record('question_numerical', $num);
            }
        }

        // Upsert qtype_multichoice_options so Moodle 5 renders single vs MCMA properly.
        if ($moodleQtype === 'multichoice' && $dbman->table_exists('qtype_multichoice_options')) {
            $mcopt = $DB->get_record('qtype_multichoice_options', ['questionid' => $qid]);
            if ($mcopt) {
                $mcopt->single = $isMcma ? 0 : 1;
                $DB->update_record('qtype_multichoice_options', $mcopt);
            } else {
                $mcopt = new \stdClass();
                $mcopt->questionid = $qid;
                $mcopt->layout = 0;
                $mcopt->single = $isMcma ? 0 : 1;
                $mcopt->shuffleanswers = 1;
                $mcopt->correctfeedback = '';
                $mcopt->correctfeedbackformat = FORMAT_HTML;
                $mcopt->partiallycorrectfeedback = '';
                $mcopt->partiallycorrectfeedbackformat = FORMAT_HTML;
                $mcopt->incorrectfeedback = '';
                $mcopt->incorrectfeedbackformat = FORMAT_HTML;
                $mcopt->answernumbering = 'abc';
                $mcopt->shownumcorrect = 0;
                $mcopt->showstandardinstruction = 1;
                $DB->insert_record('qtype_multichoice_options', $mcopt);
            }
        }

        // Ensure question_numerical_options exists for numerical questions.
        if ($moodleQtype === 'numerical' && $dbman->table_exists('question_numerical_options')) {
            if (!$DB->record_exists('question_numerical_options', ['question' => $qid])) {
                $numopt = new \stdClass();
                $numopt->question = $qid;
                $numopt->showunits = 3; // Units not used at all.
                $numopt->unitsleft = 0;
                $numopt->unitgradingtype = 0;
                $numopt->unitpenalty = 0.1;
                $DB->insert_record('question_numerical_options', $numopt);
            }
        }

        return [
            'success' => true,
            'question_id' => $qid,
            'questionbankentry_id' => $qbeid,
            'category_id' => $categoryid,
            'version' => $version,
            'message' => "Soal {$code} berhasil disinkronkan ke Bank Soal Moodle 5.",
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
                require_once($CFG->dirroot . '/cohort/lib.php');
                $cohort = $DB->get_record('cohort', ['id' => $moodleid]);
                if ($cohort) {
                    cohort_delete_cohort($cohort);
                } else {
                    $DB->delete_records('cohort_members', ['cohortid' => $moodleid]);
                    $DB->delete_records('cohort', ['id' => $moodleid]);
                }
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
                $dbman = $DB->get_manager();
                if ($dbman->table_exists('question_numerical')) {
                    $DB->delete_records('question_numerical', ['question' => $moodleid]);
                }
                if ($dbman->table_exists('question_numerical_options')) {
                    $DB->delete_records('question_numerical_options', ['question' => $moodleid]);
                }
                if ($dbman->table_exists('qtype_multichoice_options')) {
                    $DB->delete_records('qtype_multichoice_options', ['questionid' => $moodleid]);
                }
                $DB->delete_records('question_answers', ['question' => $moodleid]);
                if ($dbman->table_exists('question_versions')) {
                    $qvs = $DB->get_records('question_versions', ['questionid' => $moodleid]);
                    $DB->delete_records('question_versions', ['questionid' => $moodleid]);
                    if ($dbman->table_exists('question_bank_entries')) {
                        foreach ($qvs as $qv) {
                            if (!$DB->record_exists('question_versions', ['questionbankentryid' => $qv->questionbankentryid])) {
                                $DB->delete_records('question_bank_entries', ['id' => $qv->questionbankentryid]);
                            }
                        }
                    }
                }
                $DB->delete_records('question', ['id' => $moodleid]);
                return ['success' => true, 'message' => "Soal #{$moodleid} dihapus dari Moodle."];

            default:
                return ['success' => false, 'message' => 'Entitas delete tidak dikenal'];
        }
    }

    /**
     * Verify a Moodle user's credentials (including Site Administrators for whom /login/token.php refuses token creation)
     * and return their resolved CBT role (ADMIN, TEACHER, PROCTOR, STUDENT).
     */
    private static function verify_user_credentials(array $data): array {
        global $DB, $CFG;

        $username = strtolower(trim((string)($data['username'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($username === '' || $password === '') {
            return ['valid' => false, 'message' => 'Username dan password wajib diisi.'];
        }

        $user = $DB->get_record('user', [
            'username' => $username,
            'deleted' => 0,
            'suspended' => 0,
        ]);

        if (!$user) {
            return ['valid' => false, 'message' => 'Pengguna tidak ditemukan di Moodle.'];
        }

        $passwordValid = false;
        if (function_exists('validate_internal_user_password')) {
            $passwordValid = validate_internal_user_password($user, $password);
        } else if (!empty($user->password)) {
            $passwordValid = password_verify($password, (string)$user->password);
        }

        if (!$passwordValid && function_exists('authenticate_user_login')) {
            $authed = authenticate_user_login($username, $password);
            if ($authed && !empty($authed->id)) {
                $passwordValid = true;
            }
        }

        if (!$passwordValid) {
            return ['valid' => false, 'message' => 'Kata sandi tidak cocok.'];
        }

        $uid = (int)$user->id;
        $role = 'STUDENT';

        $siteadminids = array_map('intval', array_map('trim', explode(',', (string)($CFG->siteadmins ?? ''))));
        if (in_array($uid, $siteadminids, true) || $username === 'admin' || (function_exists('is_siteadmin') && is_siteadmin($uid))) {
            $role = 'ADMIN';
        } else {
            try {
                $roles = $DB->get_records_sql(
                    "SELECT r.shortname
                       FROM {role_assignments} ra
                       JOIN {role} r ON r.id = ra.roleid
                      WHERE ra.userid = :userid",
                    ['userid' => $uid]
                );
                foreach ($roles as $r) {
                    $short = strtolower(trim((string)$r->shortname));
                    if (in_array($short, ['manager', 'coursecreator', 'editingteacher'], true)) {
                        $role = 'TEACHER';
                        break;
                    } else if ($short === 'teacher') {
                        $role = 'PROCTOR';
                    }
                }
            } catch (\Throwable $e) {
                debugging('Role check skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return [
            'valid' => true,
            'user_id' => $uid,
            'username' => (string)$user->username,
            'full_name' => trim(((string)($user->firstname ?? '')) . ' ' . ((string)($user->lastname ?? ''))) ?: (string)$user->username,
            'email' => (string)($user->email ?? ''),
            'role' => $role,
            'message' => 'Kredensial Moodle valid.',
        ];
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
