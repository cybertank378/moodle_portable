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

namespace mod_cbt\infrastructure\cache;

defined('MOODLE_INTERNAL') || die();

/**
 * Universal cache store for static exam snapshots and server-side answer keys.
 * Ensures 0 database queries to mdl_question during live high-concurrency exams.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class muc_snapshot_store {
    public const CACHE_SNAPSHOT = 'questionsnapshot';
    public const CACHE_KEYS = 'answerkeys';

    /**
     * Get or build stripped student question snapshot for a CBT exam.
     *
     * @param \stdClass $cbt Record from mdl_cbt
     * @return array List of stripped questions
     */
    public static function get_question_snapshot(\stdClass $cbt): array {
        $cache = \cache::make('mod_cbt', self::CACHE_SNAPSHOT);
        $cache_key = 'cbt_' . $cbt->id;

        $cached = $cache->get($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // Build snapshot from source and store in cache
        $built = self::build_and_store_snapshots($cbt);
        return $built['snapshot'];
    }

    /**
     * Get private server-side answer keys map for score calculation.
     *
     * @param \stdClass $cbt Record from mdl_cbt
     * @return array Map of [ question_id => [ 'type' => string, 'weight' => float, 'key' => mixed ] ]
     */
    public static function get_answer_keys(\stdClass $cbt): array {
        $cache = \cache::make('mod_cbt', self::CACHE_KEYS);
        $cache_key = 'keys_' . $cbt->id;

        $cached = $cache->get($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // Build snapshot and keys together
        $built = self::build_and_store_snapshots($cbt);
        return $built['answer_keys'];
    }

    /**
     * Purge MUC cache for a CBT instance (e.g. after question edit / freeze).
     *
     * @param int $cbtid
     */
    public static function purge_cache(int $cbtid): void {
        $cache_snap = \cache::make('mod_cbt', self::CACHE_SNAPSHOT);
        $cache_keys = \cache::make('mod_cbt', self::CACHE_KEYS);

        $cache_snap->delete('cbt_' . $cbtid);
        $cache_keys->delete('keys_' . $cbtid);
    }

    /**
     * Build questions snapshot and private keys map from bank or fallback items.
     *
     * @param \stdClass $cbt
     * @return array [ 'snapshot' => array, 'answer_keys' => array ]
     */
    public static function build_and_store_snapshots(\stdClass $cbt): array {
        global $DB;

        $questions_snapshot = [];
        $answer_keys = [];

        // 1. Attempt to fetch questions linked to course category or question table if available
        $q_records = [];
        try {
            $sql = "SELECT q.id, q.qtype, q.name, q.questiontext, q.questiontextformat, q.defaultmark
                      FROM {question} q
                     WHERE q.qtype IN ('multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay', 'gapselect', 'ddwtos')
                       AND q.parent = 0
                  ORDER BY q.id ASC";
            $q_records = $DB->get_records_sql($sql, [], 0, 50);
        } catch (\Throwable $e) {
            $q_records = [];
        }

        if (!empty($q_records)) {
            $slot = 1;
            foreach ($q_records as $q) {
                $qid = (int)$q->id;
                $weight = (float)($q->defaultmark ?? 1.0);

                if ($q->qtype === 'multichoice') {
                    $is_single = true;
                    try {
                        $mc_opts = $DB->get_record('qtype_multichoice_options', ['questionid' => $qid]);
                        if ($mc_opts && isset($mc_opts->single)) {
                            $is_single = ((int)$mc_opts->single === 1);
                        }
                    } catch (\Throwable $e) {
                        $is_single = true;
                    }

                    $answers = $DB->get_records('question_answers', ['question' => $qid], 'id ASC');
                    $options = [];
                    $correct_keys = [];
                    $single_correct = 'A';
                    $opt_labels = ['A', 'B', 'C', 'D', 'E', 'F'];
                    $opt_idx = 0;

                    foreach ($answers as $ans) {
                        $label = $opt_labels[$opt_idx] ?? ('OPT' . ($opt_idx + 1));
                        $options[] = [
                            'key' => $label,
                            'html' => $ans->answer,
                        ];
                        if ((float)$ans->fraction > 0.0) {
                            $correct_keys[] = $label;
                            if ((float)$ans->fraction > 0.5) {
                                $single_correct = $label;
                            }
                        }
                        $opt_idx++;
                    }

                    if ($is_single) {
                        $questions_snapshot[] = [
                            'slot' => $slot++,
                            'questionId' => $qid,
                            'type' => 'multichoice_single',
                            'stemHtml' => $q->questiontext,
                            'options' => $options,
                        ];
                        $answer_keys[$qid] = [
                            'type' => 'multichoice_single',
                            'weight' => $weight,
                            'key' => $single_correct,
                        ];
                    } else {
                        $questions_snapshot[] = [
                            'slot' => $slot++,
                            'questionId' => $qid,
                            'type' => 'multichoice_multi',
                            'stemHtml' => $q->questiontext,
                            'options' => $options,
                        ];
                        $answer_keys[$qid] = [
                            'type' => 'multichoice_multi',
                            'weight' => $weight,
                            'key' => !empty($correct_keys) ? $correct_keys : [$single_correct],
                        ];
                    }

                } else if ($q->qtype === 'truefalse') {
                    $answers = $DB->get_records('question_answers', ['question' => $qid], 'id ASC');
                    $correct_bool = 'true';
                    foreach ($answers as $ans) {
                        if ((float)$ans->fraction > 0.5) {
                            $raw_text = strtolower(trim($ans->answer));
                            $correct_bool = (strpos($raw_text, 'true') !== false || $raw_text === '1') ? 'true' : 'false';
                            break;
                        }
                    }

                    $questions_snapshot[] = [
                        'slot' => $slot++,
                        'questionId' => $qid,
                        'type' => 'truefalse',
                        'stemHtml' => $q->questiontext,
                        'options' => [
                            ['key' => 'true', 'html' => 'Benar (True)'],
                            ['key' => 'false', 'html' => 'Salah (False)'],
                        ],
                    ];

                    $answer_keys[$qid] = [
                        'type' => 'truefalse',
                        'weight' => $weight,
                        'key' => $correct_bool,
                    ];

                } else if ($q->qtype === 'shortanswer') {
                    $answers = $DB->get_records('question_answers', ['question' => $qid], 'id ASC');
                    $accepted = [];
                    foreach ($answers as $ans) {
                        if ((float)$ans->fraction > 0.0) {
                            $accepted[] = trim($ans->answer);
                        }
                    }

                    $questions_snapshot[] = [
                        'slot' => $slot++,
                        'questionId' => $qid,
                        'type' => 'shortanswer',
                        'stemHtml' => $q->questiontext,
                        'options' => [],
                    ];

                    $answer_keys[$qid] = [
                        'type' => 'shortanswer',
                        'weight' => $weight,
                        'key' => $accepted,
                    ];

                } else if ($q->qtype === 'numerical') {
                    $answers = $DB->get_records('question_answers', ['question' => $qid], 'fraction DESC');
                    $target_ans = 0.0;
                    $tolerance = 0.0;

                    if (!empty($answers)) {
                        $primary = reset($answers);
                        $target_ans = (float)$primary->answer;
                        try {
                            $numrec = $DB->get_record('question_numerical', ['answer' => $primary->id]);
                            if ($numrec && isset($numrec->tolerance)) {
                                $tolerance = (float)$numrec->tolerance;
                            }
                        } catch (\Throwable $e) {
                            $tolerance = 0.0;
                        }
                    }

                    $questions_snapshot[] = [
                        'slot' => $slot++,
                        'questionId' => $qid,
                        'type' => 'numerical',
                        'stemHtml' => $q->questiontext,
                        'options' => [],
                    ];

                    $answer_keys[$qid] = [
                        'type' => 'numerical',
                        'weight' => $weight,
                        'key' => [
                            'answer' => $target_ans,
                            'tolerance' => $tolerance,
                        ],
                    ];

                } else if ($q->qtype === 'match') {
                    $subquestions = [];
                    $choices = [];
                    $pairs = [];

                    try {
                        $subq_records = $DB->get_records('qtype_match_subquestions', ['questionid' => $qid], 'id ASC');
                        $sub_idx = 1;
                        foreach ($subq_records as $sub) {
                            if (!empty($sub->questiontext) && !empty($sub->answertext)) {
                                $subquestions[] = [
                                    'id' => $sub_idx,
                                    'text' => $sub->questiontext,
                                ];
                                $choices[] = $sub->answertext;
                                $pairs[$sub_idx] = $sub->answertext;
                                $sub_idx++;
                            }
                        }
                    } catch (\Throwable $e) {
                        $subquestions = [];
                    }

                    $questions_snapshot[] = [
                        'slot' => $slot++,
                        'questionId' => $qid,
                        'type' => 'match',
                        'stemHtml' => $q->questiontext,
                        'subquestions' => $subquestions,
                        'choices' => array_values(array_unique($choices)),
                    ];

                    $answer_keys[$qid] = [
                        'type' => 'match',
                        'weight' => $weight,
                        'key' => $pairs,
                    ];

                } else if ($q->qtype === 'essay') {
                    $questions_snapshot[] = [
                        'slot' => $slot++,
                        'questionId' => $qid,
                        'type' => 'essay',
                        'stemHtml' => $q->questiontext,
                        'options' => [],
                    ];

                    $answer_keys[$qid] = [
                        'type' => 'essay',
                        'weight' => $weight,
                        'key' => ['requires_manual_grading' => true],
                    ];
                }
            }
        }

        // If no questions exist yet in question bank, create sample exam questions covering all question types
        if (empty($questions_snapshot)) {
            // 1. Multichoice Single
            $qid_mc = 5012;
            $questions_snapshot[] = [
                'slot' => 1,
                'questionId' => $qid_mc,
                'type' => 'multichoice_single',
                'stemHtml' => '<p>Jika persamaan kuadrat $$2x^2 - 7x + 3 = 0$$ memiliki akar-akar $$x_1$$ dan $$x_2$$, maka nilai dari $$x_1^2 + x_2^2$$ adalah...</p>',
                'options' => [
                    ['key' => 'A', 'html' => '$$\\frac{37}{4}$$'],
                    ['key' => 'B', 'html' => '$$\\frac{49}{4}$$'],
                    ['key' => 'C', 'html' => '$$\\frac{25}{4}$$'],
                    ['key' => 'D', 'html' => '$$\\frac{31}{4}$$'],
                ],
            ];
            $answer_keys[$qid_mc] = [
                'type' => 'multichoice_single',
                'weight' => 1.0,
                'key' => 'A',
            ];

            // 2. Multichoice Multi (Kompleks)
            $qid_multi = 5013;
            $questions_snapshot[] = [
                'slot' => 2,
                'questionId' => $qid_multi,
                'type' => 'multichoice_multi',
                'stemHtml' => '<p>Pilihlah seluruh besaran pokok dalam Sistem Internasional (SI) berikut ini!</p>',
                'options' => [
                    ['key' => 'A', 'html' => 'Massa (Kilogram)'],
                    ['key' => 'B', 'html' => 'Kecepatan (m/s)'],
                    ['key' => 'C', 'html' => 'Waktu (Sekon)'],
                    ['key' => 'D', 'html' => 'Gaya (Newton)'],
                ],
            ];
            $answer_keys[$qid_multi] = [
                'type' => 'multichoice_multi',
                'weight' => 2.0,
                'key' => ['A', 'C'],
            ];

            // 3. True / False
            $qid_tf = 5014;
            $questions_snapshot[] = [
                'slot' => 3,
                'questionId' => $qid_tf,
                'type' => 'truefalse',
                'stemHtml' => '<p>UUD Negara Republik Indonesia Tahun 1945 telah mengalami empat kali amandemen.</p>',
                'options' => [
                    ['key' => 'true', 'html' => 'Benar (True)'],
                    ['key' => 'false', 'html' => 'Salah (False)'],
                ],
            ];
            $answer_keys[$qid_tf] = [
                'type' => 'truefalse',
                'weight' => 1.0,
                'key' => 'true',
            ];

            // 4. Short Answer
            $qid_sa = 5015;
            $questions_snapshot[] = [
                'slot' => 4,
                'questionId' => $qid_sa,
                'type' => 'shortanswer',
                'stemHtml' => '<p>Ibukota negara Indonesia yang baru di Kalimantan Timur bernama...</p>',
                'options' => [],
            ];
            $answer_keys[$qid_sa] = [
                'type' => 'shortanswer',
                'weight' => 1.0,
                'key' => ['Nusantara', 'IKN Nusantara'],
            ];

            // 5. Numerical with Tolerance
            $qid_num = 5016;
            $questions_snapshot[] = [
                'slot' => 5,
                'questionId' => $qid_num,
                'type' => 'numerical',
                'stemHtml' => '<p>Berapakah nilai percepatan gravitasi bumi rata-rata dalam m/s^2 (toleransi 0.2)?</p>',
                'options' => [],
            ];
            $answer_keys[$qid_num] = [
                'type' => 'numerical',
                'weight' => 1.0,
                'key' => [
                    'answer' => 9.8,
                    'tolerance' => 0.2,
                ],
            ];

            // 6. Match / Menjodohkan
            $qid_match = 5017;
            $questions_snapshot[] = [
                'slot' => 6,
                'questionId' => $qid_match,
                'type' => 'match',
                'stemHtml' => '<p>Jodohkan masing-masing negara berikut dengan ibukotanya yang tepat!</p>',
                'subquestions' => [
                    ['id' => 1, 'text' => 'Jepang'],
                    ['id' => 2, 'text' => 'Perancis'],
                    ['id' => 3, 'text' => 'Australia'],
                ],
                'choices' => ['Tokyo', 'Paris', 'Canberra', 'Sydney'],
            ];
            $answer_keys[$qid_match] = [
                'type' => 'match',
                'weight' => 1.5,
                'key' => [
                    1 => 'Tokyo',
                    2 => 'Paris',
                    3 => 'Canberra',
                ],
            ];

            // 7. Essay
            $qid_essay = 5018;
            $questions_snapshot[] = [
                'slot' => 7,
                'questionId' => $qid_essay,
                'type' => 'essay',
                'stemHtml' => '<p>Jelaskan secara komprehensif dampak revolusi industri 4.0 terhadap efisiensi sistem pendidikan nasional!</p>',
                'options' => [],
            ];
            $answer_keys[$qid_essay] = [
                'type' => 'essay',
                'weight' => 3.0,
                'key' => ['requires_manual_grading' => true],
            ];
        }

        // Cache both representations
        $cache_snap = \cache::make('mod_cbt', self::CACHE_SNAPSHOT);
        $cache_keys = \cache::make('mod_cbt', self::CACHE_KEYS);

        $cache_snap->set('cbt_' . $cbt->id, $questions_snapshot);
        $cache_keys->set('keys_' . $cbt->id, $answer_keys);

        return [
            'snapshot' => $questions_snapshot,
            'answer_keys' => $answer_keys,
        ];
    }
}

