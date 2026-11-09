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
 * CLI Auto-Configuration for mod_cbt and Next.js integration.
 * Enables web services, configures protocols, provisions CBT external service,
 * generates admin API token, provisions sample exam activity, and synchronizes Next.js .env.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Locate config.php
$config_paths = [
    __DIR__ . '/../../../../config.php',
    __DIR__ . '/../../../config.php',
    dirname(dirname(dirname(__DIR__))) . '/config.php',
];

$loaded = false;
foreach ($config_paths as $p) {
    if (file_exists($p)) {
        require_once($p);
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Error: config.php Moodle tidak ditemukan.\n");
    exit(1);
}

require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/cbt/lib.php');

cli_heading("Aksaventra CBT Auto-Configuration Engine");

global $DB, $CFG;

// 1. Enable Global Web Services & REST protocol
cli_writeln("[1/7] Mengaktifkan Web Services & REST Protocol...");
set_config('enablewebservices', 1);
set_config('enablewsdocumentation', 1);
set_config('webserviceprotocols', 'rest');

// 2. Configure mod_cbt Default Settings
cli_writeln("[2/7] Mengonfigurasi parameter default mod_cbt...");
set_config('nextjs_url', 'http://localhost:3000/exam', 'mod_cbt');
set_config('portal_url', 'http://localhost:3000', 'mod_cbt');
set_config('default_timelimit', 7200, 'mod_cbt');
set_config('default_maxstrikes', 3, 'mod_cbt');

// 3. Register External Service: mod_cbt_service
cli_writeln("[3/7] Mendaftarkan External Service mod_cbt_service...");
$service = $DB->get_record('external_services', ['shortname' => 'mod_cbt_service']);
if (!$service) {
    $service = new stdClass();
    $service->name = 'CBT Examination Service';
    $service->shortname = 'mod_cbt_service';
    $service->restrictedusers = 0;
    $service->enabled = 1;
    $service->timecreated = time();
    $service->timemodified = time();
    $service->id = $DB->insert_record('external_services', $service);
    cli_writeln("  -> Service mod_cbt_service berhasil dibuat (ID: {$service->id}).");
} else {
    $service->enabled = 1;
    $service->restrictedusers = 0;
    $service->timemodified = time();
    $DB->update_record('external_services', $service);
    cli_writeln("  -> Service mod_cbt_service aktif (ID: {$service->id}).");
}

// 4. Attach all 12 functions to mod_cbt_service
cli_writeln("[4/7] Mendaftarkan 12 fungsi API headless ke mod_cbt_service...");
$required_functions = [
    'mod_cbt_get_exam_manifest' => 'mod_cbt\external\get_exam_manifest',
    'mod_cbt_sync_batch_answers' => 'mod_cbt\external\sync_batch_answers',
    'mod_cbt_report_violation' => 'mod_cbt\external\report_violation',
    'mod_cbt_submit_exam' => 'mod_cbt\external\submit_exam',
    'mod_cbt_proctor_control' => 'mod_cbt\external\proctor_control',
    'mod_cbt_manage_schedule' => 'mod_cbt\external\manage_schedule',
    'mod_cbt_manage_roster' => 'mod_cbt\external\manage_roster',
    'mod_cbt_get_proctor_stream' => 'mod_cbt\external\get_proctor_stream',
    'mod_cbt_get_recapitulation' => 'mod_cbt\external\get_recapitulation',
    'mod_cbt_manage_groups' => 'mod_cbt\external\manage_groups',
    'mod_cbt_assign_group_to_exam' => 'mod_cbt\external\assign_group_to_exam',
    'mod_cbt_import_export' => 'mod_cbt\external\import_export',
    'core_webservice_get_site_info' => 'core_webservice_external',
];

foreach ($required_functions as $func_name => $classname) {
    // Check if function exists in mdl_external_functions
    $f_rec = $DB->get_record('external_functions', ['name' => $func_name]);
    if (!$f_rec) {
        $f_rec = new stdClass();
        $f_rec->name = $func_name;
        $f_rec->classname = $classname;
        $f_rec->methodname = 'execute';
        $f_rec->classpath = '';
        $f_rec->component = 'mod_cbt';
        $f_rec->capabilities = '';
        $f_rec->services = 'mod_cbt_service';
        $f_rec->id = $DB->insert_record('external_functions', $f_rec);
    }

    // Link function to service in mdl_external_services_functions
    $link = $DB->get_record('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $func_name,
    ]);
    if (!$link) {
        $link = new stdClass();
        $link->externalserviceid = $service->id;
        $link->functionname = $func_name;
        $DB->insert_record('external_services_functions', $link);
    }
}
cli_writeln("  -> 12 fungsi API berhasil dihubungkan ke mod_cbt_service.");

// 5. Generate / Fetch Permanent Admin API Token
cli_writeln("[5/7] Menginisialisasi Permanent Admin Token API...");
$admin = get_admin();
if (!$admin) {
    $admin = $DB->get_record('user', ['username' => 'admin']);
}
if (!$admin) {
    $admin = $DB->get_record('user', ['id' => 2]); // Fallback first non-guest user
}

$token_record = $DB->get_record('external_tokens', [
    'userid' => $admin->id,
    'externalserviceid' => $service->id,
    'tokentype' => 0, // EXTERNAL_TOKEN_PERMANENT
]);

if (!$token_record) {
    $new_token = md5(uniqid('cbt_admin_', true)) . bin2hex(random_bytes(8));
    $token_record = new stdClass();
    $token_record->token = $new_token;
    $token_record->privatetoken = null;
    $token_record->tokentype = 0; // EXTERNAL_TOKEN_PERMANENT
    $token_record->userid = $admin->id;
    $token_record->externalserviceid = $service->id;
    $token_record->contextid = 1; // System context
    $token_record->creatorid = $admin->id;
    $token_record->timecreated = time();
    $token_record->validuntil = 0;
    $token_record->lastaccess = 0;
    $token_record->id = $DB->insert_record('external_tokens', $token_record);
    cli_writeln("  -> Token Baru Dibuat: {$token_record->token}");
} else {
    cli_writeln("  -> Token Aktif Ditemukan: {$token_record->token}");
}

// 6. Auto-provision Course & CBT Exam Instance (if none exists)
cli_writeln("[6/7] Memeriksa ketersediaan Sesi Ujian CBT Default...");
$cbt_count = $DB->count_records('cbt');
if ($cbt_count == 0) {
    cli_writeln("  -> Membuat Kursus & Sesi Ujian CBT Default...");
    
    // Check or create default course
    $courses = $DB->get_records_select('course', 'id > 1', null, 'id ASC', '*', 0, 1);
    $course = !empty($courses) ? reset($courses) : null;
    if (!$course) {
        $course = new stdClass();
        $course->category = 1;
        $course->fullname = 'Ujian CBT Sekolah';
        $course->shortname = 'CBT-SEKOLAH';
        $course->summary = 'Sesi Ujian Berbasis Komputer Resmi Aksaventra';
        $course->format = 'topics';
        $course->numsections = 1;
        $course->startdate = time();
        $course->visible = 1;
        $course->timecreated = time();
        $course->timemodified = time();
        $course->id = $DB->insert_record('course', $course);
    }

    // Get module ID of 'cbt'
    $module = $DB->get_record('modules', ['name' => 'cbt']);
    if ($module) {
        // Create CBT instance
        $cbt = new stdClass();
        $cbt->course = $course->id;
        $cbt->name = 'Simulasi Ujian CBT Aksaventra';
        $cbt->intro = '<p>Selamat datang di sesi ujian CBT Aksaventra. Kerjakan dengan jujur dan tertib.</p>';
        $cbt->introformat = FORMAT_HTML;
        $cbt->timeopen = 0;
        $cbt->timeclose = 0;
        $cbt->timelimit = 7200; // 120 menit
        $cbt->maxstrikes = 3;
        $cbt->active_token = '123456';
        $cbt->tokensecret = bin2hex(random_bytes(16));
        $cbt->grade = 100.00;
        $cbt->timecreated = time();
        $cbt->timemodified = time();
        $cbt->id = $DB->insert_record('cbt', $cbt);

        // Create Course Module
        $cm = new stdClass();
        $cm->course = $course->id;
        $cm->module = $module->id;
        $cm->instance = $cbt->id;
        $cm->section = 1;
        $cm->idnumber = 'cbt_' . $cbt->id;
        $cm->added = time();
        $cm->visible = 1;
        $cm->visibleold = 1;
        $cm->groupmode = 0;
        $cm->groupingid = 0;
        $cm->id = $DB->insert_record('course_modules', $cm);

        // Grade item
        cbt_grade_item_update($cbt);

        cli_writeln("  -> Sesi '{$cbt->name}' berhasil dibuat otomatis (CBT ID: {$cbt->id}, Course Module ID: {$cm->id}).");
    }
} else {
    cli_writeln("  -> {$cbt_count} sesi ujian CBT sudah tersedia di sistem.");
}

// 7. Synchronize Next.js .env
cli_writeln("[7/7] Menyinkronkan konfigurasi ke frontend Next.js (aksaventra-cbt)...");
$nextjs_env_path = 'D:/Project/Website/aksaventra-cbt/.env';
if (file_exists($nextjs_env_path)) {
    $env_content = file_get_contents($nextjs_env_path);
    
    // Prepare Moodle API environment variables
    $wwwroot = !empty($CFG->wwwroot) ? $CFG->wwwroot : 'http://localhost:8080';
    $endpoint = $wwwroot . '/webservice/rest/server.php';
    $token = $token_record->token;

    $configs = [
        'NEXT_PUBLIC_MOODLE_URL' => $wwwroot,
        'MOODLE_WS_ENDPOINT' => $endpoint,
        'MOODLE_WS_TOKEN' => $token,
        'NEXT_PUBLIC_DEFAULT_CBT_ID' => '1',
    ];

    foreach ($configs as $k => $v) {
        if (preg_match("/^{$k}=.*$/m", $env_content)) {
            $env_content = preg_replace("/^{$k}=.*$/m", "{$k}=\"{$v}\"", $env_content);
        } else {
            $env_content .= "\n{$k}=\"{$v}\"";
        }
    }

    file_put_contents($nextjs_env_path, $env_content);
    cli_writeln("  -> File {$nextjs_env_path} berhasil diperbarui dengan Token API dan Endpoint!");
}

cli_heading("KONFIGURASI OTOMATIS SELESAI DENGAN SUKSES!");
cli_writeln("Moodle Backend dan Next.js Frontend kini telah terhubung secara otomatis.");
