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
 * Event observers for real-time bidirectional sync with Aksaventra CBT.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_created',
        'callback'  => '\mod_cbt\observer::on_user_created',
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback'  => '\mod_cbt\observer::on_user_updated',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\mod_cbt\observer::on_user_deleted',
    ],
    [
        'eventname' => '\core\event\cohort_created',
        'callback'  => '\mod_cbt\observer::on_cohort_created',
    ],
    [
        'eventname' => '\core\event\cohort_updated',
        'callback'  => '\mod_cbt\observer::on_cohort_updated',
    ],
    [
        'eventname' => '\core\event\cohort_deleted',
        'callback'  => '\mod_cbt\observer::on_cohort_deleted',
    ],
    [
        'eventname' => '\core\event\cohort_member_added',
        'callback'  => '\mod_cbt\observer::on_cohort_member_added',
    ],
    [
        'eventname' => '\core\event\cohort_member_removed',
        'callback'  => '\mod_cbt\observer::on_cohort_member_removed',
    ],
    [
        'eventname' => '\core\event\course_created',
        'callback'  => '\mod_cbt\observer::on_course_created',
    ],
    [
        'eventname' => '\core\event\course_updated',
        'callback'  => '\mod_cbt\observer::on_course_updated',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\mod_cbt\observer::on_course_deleted',
    ],
    [
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\mod_cbt\observer::on_course_module_changed',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\mod_cbt\observer::on_course_module_changed',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\mod_cbt\observer::on_course_module_changed',
    ],
    [
        'eventname' => '\core\event\question_created',
        'callback'  => '\mod_cbt\observer::on_question_changed',
    ],
    [
        'eventname' => '\core\event\question_updated',
        'callback'  => '\mod_cbt\observer::on_question_changed',
    ],
    [
        'eventname' => '\core\event\question_deleted',
        'callback'  => '\mod_cbt\observer::on_question_changed',
    ],
];
