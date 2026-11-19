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
        $requires_manual_grading = false;

        foreach ($answer_keys_map as $qid => $qdata) {
            $type = $qdata['type'] ?? 'multichoice_single';
            $weight = (float)($qdata['weight'] ?? 1.0);
            $max_raw_score += $weight;

            $correct_key = $qdata['key'] ?? null;
            $user_submission = $submitted_answers[$qid]['ans'] ?? null;

            if ($type === 'essay') {
                $requires_manual_grading = true;
            }

            $fraction = self::calculate_fraction($type, $correct_key, $user_submission);
            $item_score = $fraction * $weight;
            $raw_score += $item_score;

            $details[$qid] = [
                'type' => $type,
                'weight' => $weight,
                'fraction' => $fraction,
                'score' => $item_score,
                'manual_grading' => ($type === 'essay'),
            ];
        }

        $percentage = $max_raw_score > 0 ? ($raw_score / $max_raw_score) : 0.0;
        $scaled_grade = round($percentage * $target_max_grade, 5);

        return [
            'sumgrades' => $scaled_grade,
            'raw_score' => round($raw_score, 5),
            'max_raw_score' => round($max_raw_score, 5),
            'percentage' => round($percentage * 100, 2),
            'requires_manual_grading' => $requires_manual_grading,
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
            case 'mcma':
                // Correct key is array of keys: e.g. ['A', 'C']
                $expected_list = array_values(array_unique(array_filter(
                    array_map('strtoupper', array_map('trim', (array)$correct_key)),
                    static fn($v) => $v !== ''
                )));
                $given_list = array_values(array_unique(array_filter(
                    array_map('strtoupper', array_map('trim', (array)$user_answer)),
                    static fn($v) => $v !== ''
                )));

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

            case 'truefalse':
                // Normalized check: boolean, 'true'/'false', '1'/'0', 't'/'f'
                $normalize_bool = static function ($val): ?bool {
                    if (is_bool($val)) {
                        return $val;
                    }
                    $s = strtolower(trim((string)$val));
                    if (in_array($s, ['1', 'true', 't', 'benar', 'ya'], true)) {
                        return true;
                    }
                    if (in_array($s, ['0', 'false', 'f', 'salah', 'tidak'], true)) {
                        return false;
                    }
                    return null;
                };

                $expected_bool = $normalize_bool($correct_key);
                $given_bool = $normalize_bool($user_answer);

                if ($expected_bool !== null && $given_bool !== null) {
                    return ($expected_bool === $given_bool) ? 1.0 : 0.0;
                }
                return 0.0;

            case 'numerical':
                // Correct key can be scalar or associative: ['answer' => 3.1415, 'tolerance' => 0.01]
                $normalized_user = self::parse_numeric($user_answer);
                if ($normalized_user === null) {
                    return 0.0;
                }

                $target_val = 0.0;
                $tolerance = 0.00001;

                if (is_array($correct_key)) {
                    $raw_target = $correct_key['answer'] ?? $correct_key[0] ?? null;
                    $parsed_target = self::parse_numeric($raw_target);
                    if ($parsed_target === null) {
                        return 0.0;
                    }
                    $target_val = $parsed_target;
                    $tolerance = (isset($correct_key['tolerance']) && is_numeric($correct_key['tolerance']))
                        ? (float)$correct_key['tolerance']
                        : 0.0;
                } else {
                    $parsed_target = self::parse_numeric($correct_key);
                    if ($parsed_target === null) {
                        return 0.0;
                    }
                    $target_val = $parsed_target;
                    $tolerance = 0.00001;
                }

                return (abs($normalized_user - $target_val) <= abs($tolerance) + 1e-9) ? 1.0 : 0.0;

            case 'ordering':
                // Moodle 5 qtype_ordering grading:
                // Supports 'absolute' (default), 'relative_pairs' (pairwise relative order), and 'all_or_nothing'.
                if (!is_array($user_answer) || !is_array($correct_key)) {
                    return 0.0;
                }

                $gradingtype = 'absolute';
                if (isset($correct_key['order']) && is_array($correct_key['order'])) {
                    $expected_raw = $correct_key['order'];
                    $gradingtype = strtolower(trim((string)($correct_key['gradingtype'] ?? 'absolute')));
                } else if (isset($correct_key['items']) && is_array($correct_key['items'])) {
                    $expected_raw = $correct_key['items'];
                    $gradingtype = strtolower(trim((string)($correct_key['gradingtype'] ?? 'absolute')));
                } else {
                    $expected_raw = $correct_key;
                }

                $expected = array_values(array_map(static fn($v) => trim((string)$v), $expected_raw));
                $given = array_values(array_map(static fn($v) => trim((string)$v), $user_answer));

                $n = count($expected);
                if ($n === 0 || empty($given)) {
                    return 0.0;
                }

                if ($gradingtype === 'all_or_nothing') {
                    return ($given === $expected) ? 1.0 : 0.0;
                }

                if (in_array($gradingtype, ['relative_pairs', 'relative', 'relative_all_previous_and_next'], true)) {
                    // Deduplicate user items preserving first occurrence to prevent score gaming.
                    $unique_given = array_values(array_unique($given));
                    if ($n === 1) {
                        return (isset($unique_given[0]) && $unique_given[0] === $expected[0]) ? 1.0 : 0.0;
                    }

                    $pos_map = array_flip($unique_given);
                    $correct_pairs = 0;
                    $total_pairs = (int)($n * ($n - 1) / 2);

                    for ($i = 0; $i < $n; $i++) {
                        for ($j = $i + 1; $j < $n; $j++) {
                            $item_a = $expected[$i];
                            $item_b = $expected[$j];
                            if (isset($pos_map[$item_a]) && isset($pos_map[$item_b]) && $pos_map[$item_a] < $pos_map[$item_b]) {
                                $correct_pairs++;
                            }
                        }
                    }

                    return $total_pairs > 0 ? (float)($correct_pairs / $total_pairs) : 0.0;
                }

                // Default: absolute position grading (Moodle 5 GRADING_ABSOLUTE_POSITION).
                $matched = 0;
                for ($i = 0; $i < $n; $i++) {
                    if (isset($given[$i]) && $given[$i] === $expected[$i]) {
                        $matched++;
                    }
                }
                return (float)($matched / $n);

            case 'calculated':
                // Moodle 5 qtype_calculated grading:
                // Evaluates formula (with optional dataset/variables) or direct target answer,
                // then checks nominal, relative, or geometric tolerance.
                $user_val = self::parse_numeric($user_answer);
                if ($user_val === null) {
                    return 0.0;
                }

                $target_val = null;
                $tolerance = 0.01;
                $tolerancetype = 'nominal';

                if (is_array($correct_key)) {
                    if (isset($correct_key['tolerance']) && is_numeric($correct_key['tolerance'])) {
                        $tolerance = (float)$correct_key['tolerance'];
                    }

                    $raw_tt = $correct_key['tolerancetype'] ?? 'nominal';
                    if (is_int($raw_tt) || ctype_digit((string)$raw_tt)) {
                        $tt_num = (int)$raw_tt;
                        $tolerancetype = ($tt_num === 1) ? 'relative' : (($tt_num === 3) ? 'geometric' : 'nominal');
                    } else {
                        $tolerancetype = strtolower(trim((string)$raw_tt));
                    }

                    if (isset($correct_key['formula']) && trim((string)$correct_key['formula']) !== '') {
                        $vars = [];
                        if (isset($correct_key['variables']) && is_array($correct_key['variables'])) {
                            $vars = $correct_key['variables'];
                        } else if (isset($correct_key['dataset']) && is_array($correct_key['dataset'])) {
                            $vars = $correct_key['dataset'];
                        }
                        $target_val = self::evaluate_formula((string)$correct_key['formula'], $vars);
                    } else if (isset($correct_key['answer'])) {
                        $target_val = self::parse_numeric($correct_key['answer']);
                    }
                } else {
                    $target_val = self::parse_numeric($correct_key);
                }

                if ($target_val === null || !is_finite($target_val)) {
                    return 0.0;
                }

                $tol = abs($tolerance);
                if ($tolerancetype === 'relative') {
                    $allowed_delta = abs($target_val * $tol);
                    return (abs($user_val - $target_val) <= $allowed_delta + 1e-9) ? 1.0 : 0.0;
                }

                if ($tolerancetype === 'geometric') {
                    $factor = 1.0 + $tol;
                    $bound1 = $target_val / $factor;
                    $bound2 = $target_val * $factor;
                    $low = min($bound1, $bound2);
                    $high = max($bound1, $bound2);
                    return ($user_val >= $low - 1e-9 && $user_val <= $high + 1e-9) ? 1.0 : 0.0;
                }

                // Nominal tolerance (default).
                return (abs($user_val - $target_val) <= $tol + 1e-9) ? 1.0 : 0.0;

            case 'gapselect':
            case 'ddwtos':
            case 'cloze':
                // Gap select: correct_key = [ 1 => 'answerA', 2 => 'answerB' ]
                if (!is_array($correct_key) || !is_array($user_answer) || empty($correct_key)) {
                    return 0.0;
                }

                $matched_gaps = 0;
                $total_gaps = count($correct_key);
                foreach ($correct_key as $gap_id => $expected_choice) {
                    if (isset($user_answer[$gap_id])) {
                        $user_choice = trim((string)$user_answer[$gap_id]);
                        if (strcasecmp($user_choice, trim((string)$expected_choice)) === 0) {
                            $matched_gaps++;
                        }
                    }
                }
                return $total_gaps > 0 ? (float)($matched_gaps / $total_gaps) : 0.0;

            case 'essay':
                // Essay answers are submitted as text/HTML and require manual teacher evaluation.
                // Auto-score defaults to 0.0 awaiting manual grading in Moodle Gradebook.
                return 0.0;

            default:
                return 0.0;
        }
    }

    /**
     * Parse scalar input to finite float (handles decimal comma normalization).
     *
     * @param mixed $val
     * @return float|null
     */
    private static function parse_numeric($val): ?float {
        if (is_int($val) || is_float($val)) {
            $f = (float)$val;
            return is_finite($f) ? $f : null;
        }
        if (!is_string($val)) {
            return null;
        }
        $clean = trim($val);
        if ($clean === '') {
            return null;
        }
        // Normalize comma decimal separator (e.g. '9,8' -> '9.8') if no dot is present.
        if (strpos($clean, ',') !== false && strpos($clean, '.') === false) {
            $clean = str_replace(',', '.', $clean);
        }
        if (!is_numeric($clean)) {
            return null;
        }
        $f = (float)$clean;
        return is_finite($f) ? $f : null;
    }

    /**
     * Safely evaluate a Moodle calculated-question mathematical formula without using eval().
     * Supports {var} placeholders, +, -, *, /, ^, **, parentheses, and whitelisted math functions.
     *
     * @param string $formula
     * @param array $variables Map of [ 'varName' => numericValue ]
     * @return float|null Evaluated finite result or null on invalid syntax / division by zero
     */
    public static function evaluate_formula(string $formula, array $variables = []): ?float {
        $expr = trim($formula);
        if ($expr === '') {
            return null;
        }

        // Strip leading '=' if formula was entered Excel-style.
        if ($expr[0] === '=') {
            $expr = trim(substr($expr, 1));
        }

        // Substitute Moodle dataset placeholders {var}.
        $invalidVar = false;
        $expr = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function ($m) use ($variables, &$invalidVar) {
            $name = $m[1];
            if (!array_key_exists($name, $variables) || !is_numeric($variables[$name])) {
                $invalidVar = true;
                return '0';
            }
            $val = (float)$variables[$name];
            return '(' . sprintf('%.15G', $val) . ')';
        }, $expr);

        if ($invalidVar || $expr === null) {
            return null;
        }

        // Normalize '**' power operator to '^'.
        $expr = str_replace('**', '^', $expr);

        // Tokenize expression.
        $tokens = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (ctype_digit($ch) || $ch === '.') {
                $start = $i;
                $hasDot = ($ch === '.');
                $i++;
                while ($i < $len && (ctype_digit($expr[$i]) || (!$hasDot && $expr[$i] === '.'))) {
                    if ($expr[$i] === '.') {
                        $hasDot = true;
                    }
                    $i++;
                }
                // Scientific notation e.g. 1e-5 or 2.5E+3.
                if ($i < $len && ($expr[$i] === 'e' || $expr[$i] === 'E')) {
                    $expPos = $i + 1;
                    if ($expPos < $len && ($expr[$expPos] === '+' || $expr[$expPos] === '-')) {
                        $expPos++;
                    }
                    if ($expPos < $len && ctype_digit($expr[$expPos])) {
                        $i = $expPos + 1;
                        while ($i < $len && ctype_digit($expr[$i])) {
                            $i++;
                        }
                    }
                }
                $numStr = substr($expr, $start, $i - $start);
                if (!is_numeric($numStr)) {
                    return null;
                }
                $tokens[] = ['type' => 'num', 'val' => (float)$numStr];
                continue;
            }
            if (ctype_alpha($ch) || $ch === '_') {
                $start = $i;
                $i++;
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['type' => 'ident', 'val' => strtolower(substr($expr, $start, $i - $start))];
                continue;
            }
            if (in_array($ch, ['+', '-', '*', '/', '^', '(', ')', ','], true)) {
                $tokens[] = ['type' => 'op', 'val' => $ch];
                $i++;
                continue;
            }
            // Unknown / unsafe character.
            return null;
        }

        if (empty($tokens)) {
            return null;
        }

        $pos = 0;
        $total = count($tokens);

        $parseExpr = null;
        $parseTerm = null;
        $parsePower = null;
        $parseUnary = null;
        $parsePrimary = null;

        $parseExpr = static function () use (&$tokens, &$pos, $total, &$parseTerm): ?float {
            $left = $parseTerm();
            if ($left === null) {
                return null;
            }
            while ($pos < $total && $tokens[$pos]['type'] === 'op' && ($tokens[$pos]['val'] === '+' || $tokens[$pos]['val'] === '-')) {
                $op = $tokens[$pos]['val'];
                $pos++;
                $right = $parseTerm();
                if ($right === null) {
                    return null;
                }
                $left = ($op === '+') ? ($left + $right) : ($left - $right);
            }
            return is_finite($left) ? $left : null;
        };

        $parseTerm = static function () use (&$tokens, &$pos, $total, &$parsePower): ?float {
            $left = $parsePower();
            if ($left === null) {
                return null;
            }
            while ($pos < $total && $tokens[$pos]['type'] === 'op' && ($tokens[$pos]['val'] === '*' || $tokens[$pos]['val'] === '/')) {
                $op = $tokens[$pos]['val'];
                $pos++;
                $right = $parsePower();
                if ($right === null) {
                    return null;
                }
                if ($op === '/') {
                    if (abs($right) < 1e-15) {
                        return null;
                    }
                    $left = $left / $right;
                } else {
                    $left = $left * $right;
                }
            }
            return is_finite($left) ? $left : null;
        };

        $parsePower = static function () use (&$tokens, &$pos, $total, &$parseUnary, &$parsePower): ?float {
            $base = $parseUnary();
            if ($base === null) {
                return null;
            }
            if ($pos < $total && $tokens[$pos]['type'] === 'op' && $tokens[$pos]['val'] === '^') {
                $pos++;
                $exp = $parsePower();
                if ($exp === null) {
                    return null;
                }
                $res = pow($base, $exp);
                return is_finite($res) ? (float)$res : null;
            }
            return $base;
        };

        $parseUnary = static function () use (&$tokens, &$pos, $total, &$parseUnary, &$parsePrimary): ?float {
            if ($pos < $total && $tokens[$pos]['type'] === 'op' && ($tokens[$pos]['val'] === '+' || $tokens[$pos]['val'] === '-')) {
                $op = $tokens[$pos]['val'];
                $pos++;
                $val = $parseUnary();
                if ($val === null) {
                    return null;
                }
                return ($op === '-') ? -$val : $val;
            }
            return $parsePrimary();
        };

        $parsePrimary = static function () use (&$tokens, &$pos, $total, &$parseExpr): ?float {
            if ($pos >= $total) {
                return null;
            }
            $tok = $tokens[$pos];

            if ($tok['type'] === 'num') {
                $pos++;
                return (float)$tok['val'];
            }

            if ($tok['type'] === 'op' && $tok['val'] === '(') {
                $pos++;
                $val = $parseExpr();
                if ($val === null || $pos >= $total || $tokens[$pos]['type'] !== 'op' || $tokens[$pos]['val'] !== ')') {
                    return null;
                }
                $pos++;
                return $val;
            }

            if ($tok['type'] === 'ident') {
                $name = $tok['val'];
                $pos++;

                // Constant pi without parentheses.
                if ($name === 'pi' && ($pos >= $total || $tokens[$pos]['type'] !== 'op' || $tokens[$pos]['val'] !== '(')) {
                    return M_PI;
                }

                // Expect function call arguments: func(arg1, arg2, ...).
                if ($pos >= $total || $tokens[$pos]['type'] !== 'op' || $tokens[$pos]['val'] !== '(') {
                    return null;
                }
                $pos++; // consume '('

                $args = [];
                if ($pos < $total && !($tokens[$pos]['type'] === 'op' && $tokens[$pos]['val'] === ')')) {
                    while (true) {
                        $argVal = $parseExpr();
                        if ($argVal === null) {
                            return null;
                        }
                        $args[] = $argVal;
                        if ($pos < $total && $tokens[$pos]['type'] === 'op' && $tokens[$pos]['val'] === ',') {
                            $pos++;
                            continue;
                        }
                        break;
                    }
                }

                if ($pos >= $total || $tokens[$pos]['type'] !== 'op' || $tokens[$pos]['val'] !== ')') {
                    return null;
                }
                $pos++; // consume ')'

                $res = null;
                switch ($name) {
                    case 'pi':
                        $res = empty($args) ? M_PI : null;
                        break;
                    case 'abs':
                        $res = count($args) === 1 ? abs($args[0]) : null;
                        break;
                    case 'sqrt':
                        $res = (count($args) === 1 && $args[0] >= 0.0) ? sqrt($args[0]) : null;
                        break;
                    case 'pow':
                        $res = count($args) === 2 ? pow($args[0], $args[1]) : null;
                        break;
                    case 'round':
                        $res = count($args) === 1 ? round($args[0]) : (count($args) === 2 ? round($args[0], (int)$args[1]) : null);
                        break;
                    case 'floor':
                        $res = count($args) === 1 ? floor($args[0]) : null;
                        break;
                    case 'ceil':
                        $res = count($args) === 1 ? ceil($args[0]) : null;
                        break;
                    case 'sin':
                        $res = count($args) === 1 ? sin($args[0]) : null;
                        break;
                    case 'cos':
                        $res = count($args) === 1 ? cos($args[0]) : null;
                        break;
                    case 'tan':
                        $res = count($args) === 1 ? tan($args[0]) : null;
                        break;
                    case 'asin':
                        $res = (count($args) === 1 && $args[0] >= -1.0 && $args[0] <= 1.0) ? asin($args[0]) : null;
                        break;
                    case 'acos':
                        $res = (count($args) === 1 && $args[0] >= -1.0 && $args[0] <= 1.0) ? acos($args[0]) : null;
                        break;
                    case 'atan':
                        $res = count($args) === 1 ? atan($args[0]) : null;
                        break;
                    case 'log':
                        $res = (count($args) === 1 && $args[0] > 0.0) ? log($args[0]) : null;
                        break;
                    case 'log10':
                        $res = (count($args) === 1 && $args[0] > 0.0) ? log10($args[0]) : null;
                        break;
                    case 'exp':
                        $res = count($args) === 1 ? exp($args[0]) : null;
                        break;
                    case 'min':
                        $res = !empty($args) ? min($args) : null;
                        break;
                    case 'max':
                        $res = !empty($args) ? max($args) : null;
                        break;
                    default:
                        return null;
                }

                return ($res !== null && is_finite((float)$res)) ? (float)$res : null;
            }

            return null;
        };

        $result = $parseExpr();
        if ($result === null || $pos !== $total || !is_finite($result)) {
            return null;
        }
        return $result;
    }
}

