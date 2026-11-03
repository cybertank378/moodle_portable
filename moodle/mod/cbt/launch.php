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
 * Handshake bridge endpoint: generates a one-time launch code and redirects to Next.js 16 front-end.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.

$cm = get_coursemodule_from_id('cbt', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$cbt = $DB->get_record('cbt', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = \context_module::instance($cm->id);
require_capability('mod/cbt:attempt', $context);

// Generate one-time short-lived launch token (valid 5 minutes)
$launch_secret = bin2hex(random_bytes(16));
$payload = [
    'userid' => (int)$USER->id,
    'cbtid' => (int)$cbt->id,
    'cmid' => (int)$cm->id,
    'time' => time(),
];

// Store in MUC application cache or session
$cache = \cache::make('mod_cbt', 'questionsnapshot');
$cache->set('launch_' . $launch_secret, $payload);

// Determine Next.js 16 frontend base URL
$nextjs_url = get_config('mod_cbt', 'nextjs_url');
if (empty($nextjs_url)) {
    // Default fallback to local Next.js 16 port or standard path
    $host = !empty($_SERVER['HTTP_HOST']) ? explode(':', $_SERVER['HTTP_HOST'])[0] : 'localhost';
    $nextjs_url = "http://{$host}:3000/exam";
}

$redirect_target = new moodle_url($nextjs_url, [
    'cbtid' => $cbt->id,
    'cmid' => $cm->id,
    'launch_token' => $launch_secret,
    'moodle_api' => (string)new moodle_url('/webservice/rest/server.php'),
]);

redirect($redirect_target);

