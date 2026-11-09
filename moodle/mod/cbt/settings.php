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
 * Site administration settings for mod_cbt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // 1. Next.js Frontend Exam URL
    $settings->add(new admin_setting_configtext(
        'mod_cbt/nextjs_url',
        get_string('settings_nextjs_url', 'mod_cbt'),
        get_string('settings_nextjs_url_desc', 'mod_cbt'),
        'http://localhost:3000/exam',
        PARAM_URL
    ));

    // 2. Next.js Admin & Proctor Dashboard URL
    $settings->add(new admin_setting_configtext(
        'mod_cbt/portal_url',
        get_string('settings_portal_url', 'mod_cbt'),
        get_string('settings_portal_url_desc', 'mod_cbt'),
        'http://localhost:3000',
        PARAM_URL
    ));

    // 3. Default Time Limit (in seconds)
    $settings->add(new admin_setting_configtext(
        'mod_cbt/default_timelimit',
        get_string('settings_default_timelimit', 'mod_cbt'),
        get_string('settings_default_timelimit_desc', 'mod_cbt'),
        7200,
        PARAM_INT
    ));

    // 4. Default Maximum Strikes
    $settings->add(new admin_setting_configtext(
        'mod_cbt/default_maxstrikes',
        get_string('settings_default_maxstrikes', 'mod_cbt'),
        get_string('settings_default_maxstrikes_desc', 'mod_cbt'),
        3,
        PARAM_INT
    ));
}

