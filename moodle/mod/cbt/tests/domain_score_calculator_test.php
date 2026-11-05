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

namespace mod_cbt\tests;

use mod_cbt\domain\entity\strike_policy;
use mod_cbt\domain\service\score_calculator;
use mod_cbt\domain\service\token_generator;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for domain score calculator and strike policy.
 *
 * @package    mod_cbt
 * @category   test
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class domain_score_calculator_test extends \advanced_testcase {

    public function test_multichoice_single_grading() {
        $keys = [
            101 => ['type' => 'multichoice_single', 'weight' => 1.0, 'key' => 'B'],
            102 => ['type' => 'multichoice_single', 'weight' => 2.0, 'key' => 'D'],
        ];

        // 1. Both correct
        $submission_full = [
            101 => ['ans' => 'B', 'flag' => false, 'ts' => 100],
            102 => ['ans' => 'D', 'flag' => false, 'ts' => 100],
        ];
        $result = score_calculator::calculate($keys, $submission_full, 100.0);
        $this->assertEquals(100.0, $result['sumgrades']);
        $this->assertEquals(3.0, $result['raw_score']);
        $this->assertEquals(100.0, $result['percentage']);

        // 2. One incorrect
        $submission_partial = [
            101 => ['ans' => 'A', 'flag' => false, 'ts' => 100], // 0.0 * 1.0 = 0.0
            102 => ['ans' => 'D', 'flag' => false, 'ts' => 100], // 1.0 * 2.0 = 2.0
        ];
        $result2 = score_calculator::calculate($keys, $submission_partial, 100.0);
        // (2 / 3) * 100 = 66.66667
        $this->assertEquals(66.66667, $result2['sumgrades']);
    }

    public function test_multichoice_multi_grading() {
        $keys = [
            201 => ['type' => 'multichoice_multi', 'weight' => 2.0, 'key' => ['A', 'C']],
        ];

        // Both correct selected
        $sub1 = [201 => ['ans' => ['A', 'C'], 'flag' => false, 'ts' => 100]];
        $res1 = score_calculator::calculate($keys, $sub1, 100.0);
        $this->assertEquals(100.0, $res1['sumgrades']);

        // One correct selected
        $sub2 = [201 => ['ans' => ['A'], 'flag' => false, 'ts' => 100]];
        $res2 = score_calculator::calculate($keys, $sub2, 100.0);
        $this->assertEquals(50.0, $res2['sumgrades']);

        // One correct, one wrong selected -> (1/2 - 1/2) = 0
        $sub3 = [201 => ['ans' => ['A', 'B'], 'flag' => false, 'ts' => 100]];
        $res3 = score_calculator::calculate($keys, $sub3, 100.0);
        $this->assertEquals(0.0, $res3['sumgrades']);
    }

    public function test_shortanswer_grading() {
        $keys = [
            301 => ['type' => 'shortanswer', 'weight' => 1.0, 'key' => ['Nusantara', 'IKN Nusantara']],
        ];

        $sub_exact = [301 => ['ans' => 'nusantara', 'flag' => false, 'ts' => 100]];
        $res1 = score_calculator::calculate($keys, $sub_exact, 100.0);
        $this->assertEquals(100.0, $res1['sumgrades']);

        $sub_alt = [301 => ['ans' => ' IKN Nusantara ', 'flag' => false, 'ts' => 100]];
        $res2 = score_calculator::calculate($keys, $sub_alt, 100.0);
        $this->assertEquals(100.0, $res2['sumgrades']);

        $sub_wrong = [301 => ['ans' => 'Jakarta', 'flag' => false, 'ts' => 100]];
        $res3 = score_calculator::calculate($keys, $sub_wrong, 100.0);
        $this->assertEquals(0.0, $res3['sumgrades']);
    }

    public function test_truefalse_grading() {
        $keys = [
            401 => ['type' => 'truefalse', 'weight' => 1.0, 'key' => 'true'],
            402 => ['type' => 'truefalse', 'weight' => 1.0, 'key' => 'false'],
        ];

        // 1. Both correct
        $sub1 = [
            401 => ['ans' => 'true', 'flag' => false, 'ts' => 100],
            402 => ['ans' => '0', 'flag' => false, 'ts' => 100], // '0' is normalized to false
        ];
        $res1 = score_calculator::calculate($keys, $sub1, 100.0);
        $this->assertEquals(100.0, $res1['sumgrades']);

        // 2. Boolean & text input: e.g. 'benar' / 'salah'
        $sub2 = [
            401 => ['ans' => 'benar', 'flag' => false, 'ts' => 100],
            402 => ['ans' => 'salah', 'flag' => false, 'ts' => 100],
        ];
        $res2 = score_calculator::calculate($keys, $sub2, 100.0);
        $this->assertEquals(100.0, $res2['sumgrades']);

        // 3. One wrong
        $sub3 = [
            401 => ['ans' => 'false', 'flag' => false, 'ts' => 100],
            402 => ['ans' => 'false', 'flag' => false, 'ts' => 100],
        ];
        $res3 = score_calculator::calculate($keys, $sub3, 100.0);
        $this->assertEquals(50.0, $res3['sumgrades']);
    }

    public function test_numerical_grading_with_tolerance() {
        $keys = [
            501 => [
                'type' => 'numerical',
                'weight' => 2.0,
                'key' => [
                    'answer' => 9.8,
                    'tolerance' => 0.2, // Accepted: 9.6 to 10.0
                ],
            ],
        ];

        // Exact
        $res_exact = score_calculator::calculate($keys, [501 => ['ans' => 9.8]], 100.0);
        $this->assertEquals(100.0, $res_exact['sumgrades']);

        // Within tolerance (9.7)
        $res_in = score_calculator::calculate($keys, [501 => ['ans' => '9.7']], 100.0);
        $this->assertEquals(100.0, $res_in['sumgrades']);

        // Boundary edge within tolerance (10.0)
        $res_edge = score_calculator::calculate($keys, [501 => ['ans' => 10.0]], 100.0);
        $this->assertEquals(100.0, $res_edge['sumgrades']);

        // Outside tolerance (10.1)
        $res_out = score_calculator::calculate($keys, [501 => ['ans' => 10.1]], 100.0);
        $this->assertEquals(0.0, $res_out['sumgrades']);

        // Non numeric
        $res_invalid = score_calculator::calculate($keys, [501 => ['ans' => 'abc']], 100.0);
        $this->assertEquals(0.0, $res_invalid['sumgrades']);
    }

    public function test_match_grading() {
        $keys = [
            601 => [
                'type' => 'match',
                'weight' => 3.0,
                'key' => [
                    1 => 'Tokyo',
                    2 => 'Paris',
                    3 => 'Canberra',
                ],
            ],
        ];

        // All matched
        $sub_full = [
            601 => [
                'ans' => [
                    1 => 'Tokyo',
                    2 => 'Paris',
                    3 => 'Canberra',
                ],
            ],
        ];
        $res_full = score_calculator::calculate($keys, $sub_full, 100.0);
        $this->assertEquals(100.0, $res_full['sumgrades']);

        // 2 of 3 matched -> 2/3 * 3.0 = 2.0 raw -> (2.0 / 3.0) * 100 = 66.66667
        $sub_partial = [
            601 => [
                'ans' => [
                    1 => 'Tokyo',
                    2 => 'Paris',
                    3 => 'Sydney', // Wrong
                ],
            ],
        ];
        $res_partial = score_calculator::calculate($keys, $sub_partial, 100.0);
        $this->assertEquals(66.66667, $res_partial['sumgrades']);
    }

    public function test_gapselect_grading() {
        $keys = [
            701 => [
                'type' => 'gapselect',
                'weight' => 2.0,
                'key' => [
                    1 => 'mitokondria',
                    2 => 'ATP',
                ],
            ],
        ];

        // Full match
        $sub1 = [701 => ['ans' => [1 => 'mitokondria', 2 => 'ATP']]];
        $res1 = score_calculator::calculate($keys, $sub1, 100.0);
        $this->assertEquals(100.0, $res1['sumgrades']);

        // Partial match
        $sub2 = [701 => ['ans' => [1 => 'mitokondria', 2 => 'glukosa']]];
        $res2 = score_calculator::calculate($keys, $sub2, 100.0);
        $this->assertEquals(50.0, $res2['sumgrades']);
    }

    public function test_essay_grading_and_manual_flag() {
        $keys = [
            801 => ['type' => 'essay', 'weight' => 5.0, 'key' => ['requires_manual_grading' => true]],
        ];

        $sub = [
            801 => ['ans' => 'Ini adalah jawaban esai yang panjang dan mendalam dari siswa.'],
        ];
        $res = score_calculator::calculate($keys, $sub, 100.0);

        // Auto fraction should be 0.0 pending review, with requires_manual_grading flag set to true
        $this->assertEquals(0.0, $res['sumgrades']);
        $this->assertTrue($res['requires_manual_grading']);
        $this->assertTrue($res['details'][801]['manual_grading']);
    }

    public function test_comprehensive_mixed_exam() {
        $keys = [
            1 => ['type' => 'multichoice_single', 'weight' => 1.0, 'key' => 'A'],
            2 => ['type' => 'truefalse', 'weight' => 1.0, 'key' => 'true'],
            3 => ['type' => 'shortanswer', 'weight' => 1.0, 'key' => ['Nusantara']],
            4 => ['type' => 'numerical', 'weight' => 1.0, 'key' => ['answer' => 100, 'tolerance' => 1]],
        ];

        // All 4 correct
        $sub = [
            1 => ['ans' => 'A'],
            2 => ['ans' => 'true'],
            3 => ['ans' => 'Nusantara'],
            4 => ['ans' => 99.5], // Within 100 +/- 1
        ];

        $res = score_calculator::calculate($keys, $sub, 100.0);
        $this->assertEquals(100.0, $res['sumgrades']);
        $this->assertEquals(4.0, $res['raw_score']);
        $this->assertEquals(4.0, $res['max_raw_score']);
        $this->assertFalse($res['requires_manual_grading']);
    }

    public function test_strike_policy_evaluation() {
        // Tab blur adds 1 strike
        $eval1 = strike_policy::evaluate(strike_policy::EVENT_TAB_BLUR, 0, 3);
        $this->assertEquals(1, $eval1['new_strikes']);
        $this->assertFalse($eval1['should_lock']);

        // Third strike locks
        $eval2 = strike_policy::evaluate(strike_policy::EVENT_FULLSCREEN_EXIT, 2, 3);
        $this->assertEquals(3, $eval2['new_strikes']);
        $this->assertTrue($eval2['should_lock']);

        // DevTools opens immediately locks
        $eval3 = strike_policy::evaluate(strike_policy::EVENT_DEVTOOLS_OPEN, 0, 3);
        $this->assertTrue($eval3['should_lock']);
    }

    public function test_token_generator_and_validation() {
        $secret = 'TEST_CBT_SECRET_KEY';
        $now = time();

        $token = token_generator::generate_token($secret, $now, 900);
        $this->assertEquals(6, strlen($token));

        // Validate TOTP
        $is_valid = token_generator::validate($token, $secret, '', 0, 900);
        $this->assertTrue($is_valid);

        // Validate Manual Token Override
        $manual_valid = token_generator::validate('ABC123', $secret, 'ABC123', $now + 3600);
        $this->assertTrue($manual_valid);

        // Invalid Token
        $invalid = token_generator::validate('XXXXXX', $secret, '', 0, 900);
        $this->assertFalse($invalid);
    }
}

