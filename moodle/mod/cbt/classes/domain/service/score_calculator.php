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

namespace mod_cbt\domain\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Domain service for calculating exam scores in-memory without database overhead.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score_calculator {

    /**
     * Calculate score for an exam based on question answer keys and submitted answers.
     *
     * @param array $answer_keys_map Map of [ question_id => [ 'type' => string, 'weight' => float, 'key' => mixed ] ]
     * @param array $submitted_answers Map of [ question_id => [ 'ans' => mixed, 'flag' => bool, 'ts' => int ] ]
     * @param float $target_max_grade Max grade in gradebook (e.g., 100.0)
     * @return array [
     *     'sumgrades' => float,
     *     'raw_score' => float,
     *     'max_raw_score' => float,
     *     'percentage' => float,
     *     'details' => array
     * ]
     */
    public static function calculate(
        array $answer_keys_map,
        array $submitted_answers,
        float $target_max_grade = 100.0
    ): array {
        $raw_score = 0.0;
        $max_raw_score = 0.0;
        $details = [];

        foreach ($answer_keys_map as $qid => $qdata) {
            $type = $qdata['type'] ?? 'multichoice_single';
            $weight = (float)($qdata['weight'] ?? 1.0);
            $max_raw_score += $weight;

            $correct_key = $qdata['key'] ?? null;
            $user_submission = $submitted_answers[$qid]['ans'] ?? null;

            $fraction = self::calculate_fraction($type, $correct_key, $user_submission);
            $item_score = $fraction * $weight;
            $raw_score += $item_score;

            $details[$qid] = [
                'type' => $type,
                'weight' => $weight,
                'fraction' => $fraction,
                'score' => $item_score,
            ];
        }

        $percentage = $max_raw_score > 0 ? ($raw_score / $max_raw_score) : 0.0;
        $scaled_grade = round($percentage * $target_max_grade, 5);

        return [
            'sumgrades' => $scaled_grade,
            'raw_score' => round($raw_score, 5),
            'max_raw_score' => round($max_raw_score, 5),
            'percentage' => round($percentage * 100, 2),
            'details' => $details,
        ];
    }

    /**
     * Calculate earned fraction (0.0 to 1.0) for a question.
     *
     * @param string $type
     * @param mixed $correct_key
     * @param mixed $user_answer
     * @return float Fraction between 0.0 and 1.0
     */
    public static function calculate_fraction(string $type, $correct_key, $user_answer): float {
        if ($user_answer === null || $user_answer === '') {
            return 0.0;
        }

        switch ($type) {
            case 'multichoice_single':
                $expected = strtoupper(trim((string)$correct_key));
                $given = strtoupper(trim((string)$user_answer));
                return ($expected !== '' && $expected === $given) ? 1.0 : 0.0;

            case 'multichoice_multi':
                // Correct key is array of keys: e.g. ['A', 'C']
                $expected_list = array_map('strtoupper', array_map('trim', (array)$correct_key));
                $given_list = array_map('strtoupper', array_map('trim', (array)$user_answer));

                if (empty($expected_list)) {
                    return 0.0;
                }

                $correct_count = 0;
                $incorrect_count = 0;
                foreach ($given_list as $choice) {
                    if (in_array($choice, $expected_list, true)) {
                        $correct_count++;
                    } else {
                        $incorrect_count++;
                    }
                }

                $total_expected = count($expected_list);
                $earned = ($correct_count / $total_expected) - ($incorrect_count / $total_expected);
                return (float)max(0.0, min(1.0, $earned));

            case 'match':
                // Pairs: [ 'subq1' => 'matchA', 'subq2' => 'matchB' ]
                if (!is_array($correct_key) || !is_array($user_answer) || empty($correct_key)) {
                    return 0.0;
                }

                $matched = 0;
                $total_pairs = count($correct_key);
                foreach ($correct_key as $subq => $expected_match) {
                    if (isset($user_answer[$subq]) && trim((string)$user_answer[$subq]) === trim((string)$expected_match)) {
                        $matched++;
                    }
                }
                return $total_pairs > 0 ? (float)($matched / $total_pairs) : 0.0;

            case 'shortanswer':
                // Correct key can be string or array of accepted alternatives
                $accepted = array_map('mb_strtolower', array_map('trim', (array)$correct_key));
                $given = mb_strtolower(trim((string)$user_answer));
                return in_array($given, $accepted, true) ? 1.0 : 0.0;

            default:
                return 0.0;
        }
    }
}
