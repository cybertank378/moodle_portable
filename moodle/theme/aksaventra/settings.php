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
 * Settings for theme_aksaventra.
 *
 * @package    theme_aksaventra
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings = new theme_boost_admin_settingspage_tabs('themesettingaksaventra', get_string('configtitle', 'theme_aksaventra'));

    // 1. Branding Settings Page
    $page = new admin_settingpage('theme_aksaventra_branding', get_string('brandingsettings', 'theme_aksaventra'));

    // Brand Main Title
    $name = 'theme_aksaventra/brand_main';
    $title = get_string('brand_main', 'theme_aksaventra');
    $description = get_string('brand_main_desc', 'theme_aksaventra');
    $default = 'AKSAVENTRA';
    $setting = new admin_setting_configtext($name, $title, $description, $default, PARAM_TEXT);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Brand Subtitle
    $name = 'theme_aksaventra/brand_sub';
    $title = get_string('brand_sub', 'theme_aksaventra');
    $description = get_string('brand_sub_desc', 'theme_aksaventra');
    $default = 'PORTABLE CBT';
    $setting = new admin_setting_configtext($name, $title, $description, $default, PARAM_TEXT);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Custom Brand Logo file
    $name = 'theme_aksaventra/brand_logo';
    $title = get_string('brand_logo', 'theme_aksaventra');
    $description = get_string('brand_logo_desc', 'theme_aksaventra');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'brand_logo');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Brand Logo Alt Text
    $name = 'theme_aksaventra/brand_alt';
    $title = get_string('brand_alt', 'theme_aksaventra');
    $description = get_string('brand_alt_desc', 'theme_aksaventra');
    $default = 'Aksaventra';
    $setting = new admin_setting_configtext($name, $title, $description, $default, PARAM_TEXT);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);

    // 2. Advanced SCSS Settings Page (Inherited from Boost pattern)
    $page = new admin_settingpage('theme_aksaventra_advanced', get_string('advancedsettings', 'theme_boost'));

    // Raw SCSS
    $setting = new admin_setting_scsscode('theme_aksaventra/scsspost',
        get_string('rawscss', 'theme_boost'), get_string('rawscss_desc', 'theme_boost'), '', PARAM_RAW);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);
}
