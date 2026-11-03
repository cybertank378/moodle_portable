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

/**
 * Language strings for mod_cbt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'CBT Examination';
$string['modulenameplural'] = 'CBT Examinations';
$string['pluginname'] = 'CBT Examination';
$string['pluginadministration'] = 'CBT Examination administration';

$string['cbtname'] = 'Exam Name';
$string['intro'] = 'Instructions';
$string['timeopen'] = 'Exam Opens';
$string['timeclose'] = 'Exam Closes';
$string['timelimit'] = 'Time Limit';
$string['timelimit_help'] = 'Duration allowed for student to complete exam in minutes or seconds.';
$string['maxstrikes'] = 'Maximum Violations (Strikes)';
$string['maxstrikes_help'] = 'Number of times a student can leave fullscreen or change browser tabs before the session is automatically locked.';
$string['sebrequired'] = 'Require Safe Exam Browser (SEB)';
$string['sebrequired_help'] = 'Enforce student access strictly through Safe Exam Browser with matching config keys.';
$string['sebconfigkey'] = 'SEB Config Key Hash';
$string['tokensecret'] = 'Token Rotation Secret';
$string['tokensecret_help'] = 'Secret key used to generate 6-digit room tokens.';
$string['active_token'] = 'Current Active Token';
$string['active_token_help'] = 'Manual override 6-digit token entered by proctor in exam room.';
$string['token_expiry'] = 'Token Expiry';
$string['snapshot_hash'] = 'Snapshot Hash';
$string['grade'] = 'Maximum Grade';

$string['status'] = 'Attempt Status';
$string['status_inprogress'] = 'In Progress';
$string['status_locked'] = 'Locked by Anti-Cheat';
$string['status_submitted'] = 'Submitted';
$string['status_abandoned'] = 'Abandoned';

$string['strikes'] = 'Current Strikes';
$string['remaining_time'] = 'Remaining Time';
$string['launch_exam'] = 'Start / Resume CBT Exam';
$string['exam_closed'] = 'This exam is currently closed.';
$string['exam_not_started'] = 'This exam has not started yet.';
$string['exam_submitted'] = 'Your exam has already been submitted.';
$string['exam_locked'] = 'Your exam attempt has been locked due to cheating violations. Please contact the exam proctor.';
$string['invalid_token'] = 'Invalid or expired room token.';
$string['device_mismatch'] = 'Device mismatch. Your session is bound to another device or browser.';
$string['seb_required_error'] = 'Safe Exam Browser is strictly required to access this exam.';
$string['proctor_dashboard'] = 'Proctor Dashboard';
$string['unlock_attempt'] = 'Unlock Attempt';
$string['extend_time'] = 'Add Extra Time';
$string['force_submit'] = 'Force Submit';
$string['reset_device'] = 'Reset Device Lock';
