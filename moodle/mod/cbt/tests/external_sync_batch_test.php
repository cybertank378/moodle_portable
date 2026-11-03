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

use mod_cbt\domain\entity\exam_attempt;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for batch answer synchronization and optimistic locking logic.
 *
 * @package    mod_cbt
 * @category   test
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_sync_batch_test extends \advanced_testcase {

    public function test_apply_batch_answers_optimistic_locking() {
        $record = new \stdClass();
        $record->id = 1;
        $record->cbtid = 10;
        $record->userid = 42;
        $record->status = exam_attempt::STATUS_INPROGRESS;
        $record->timestart = time();
        $record->timefinish = 0;
        $record->extra_time = 0;
        $record->strike_count = 0;
        $record->device_hash = 'DEVICE_HASH_ABC';
        $record->ip_address = '127.0.0.1';
        $record->question_order = json_encode([101, 102, 103]);
        $record->answers_json = json_encode([
            101 => ['ans' => 'A', 'flag' => false, 'ts' => 1000],
        ]);
        $record->sync_version = 1;

        $attempt = exam_attempt::from_record($record);
        $this->assertEquals(1, $attempt->sync_version);
        $this->assertEquals('A', $attempt->answers[101]['ans']);

        // 1. Next sequential batch from client (v2)
        $batch1 = [
            102 => ['ans' => 'C', 'flag' => false, 'ts' => 1010],
        ];
        $res1 = $attempt->apply_batch_answers($batch1, 2);
        $this->assertTrue($res1);
        $this->assertEquals(3, $attempt->sync_version);
        $this->assertEquals('C', $attempt->answers[102]['ans']);

        // 2. Out-of-order / delayed batch with newer timestamp
        $batch_delayed = [
            101 => ['ans' => 'B', 'flag' => true, 'ts' => 1020], // newer ts
        ];
        $res2 = $attempt->apply_batch_answers($batch_delayed, 1); // client version is stale (1 < 3)
        $this->assertTrue($res2);
        $this->assertEquals('B', $attempt->answers[101]['ans']); // merged by newer timestamp
        $this->assertTrue($attempt->answers[101]['flag']);
    }

    public function test_remaining_time_calculation() {
        $now = 1730000000;
        $record = new \stdClass();
        $record->id = 2;
        $record->cbtid = 10;
        $record->userid = 43;
        $record->status = exam_attempt::STATUS_INPROGRESS;
        $record->timestart = $now - 3000; // 3000s elapsed
        $record->timefinish = 0;
        $record->extra_time = 600; // 600s extra
        $record->strike_count = 0;
        $record->device_hash = 'HASH';
        $record->ip_address = '127.0.0.1';
        $record->question_order = '[]';
        $record->answers_json = '{}';
        $record->sync_version = 1;

        $attempt = exam_attempt::from_record($record);

        // timelimit = 7200
        // allowed = start + 7200 + 600 = now - 3000 + 7800 = now + 4800
        // remaining = 4800
        $remaining = $attempt->calculate_remaining_seconds(7200, $now);
        $this->assertEquals(4800, $remaining);
        $this->assertFalse($attempt->is_time_expired(7200, $now));

        // When time has passed
        $future = $now + 5000;
        $this->assertEquals(0, $attempt->calculate_remaining_seconds(7200, $future));
        $this->assertTrue($attempt->is_time_expired(7200, $future));
    }
}

