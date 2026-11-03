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

