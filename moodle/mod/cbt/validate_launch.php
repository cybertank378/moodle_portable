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
 * Validates a launch token issued by mod_cbt/launch.php and returns student attempt session info.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$token = optional_param('launch_token', '', PARAM_ALPHANUMEXT);
if (empty($token)) {
    echo json_encode(['valid' => false, 'error' => 'Missing launch_token']);
    exit;
}

$cache = \cache::make('mod_cbt', 'questionsnapshot');
$payload = $cache->get('launch_' . $token);

if (!$payload || !is_array($payload)) {
    echo json_encode(['valid' => false, 'error' => 'Invalid or expired launch token']);
    exit;
}

$elapsed = time() - ($payload['time'] ?? 0);
if ($elapsed > 300) { // 5 minutes validity
    $cache->delete('launch_' . $token);
    echo json_encode(['valid' => false, 'error' => 'Launch token expired']);
    exit;
}

// Token is valid. Clear token to ensure strictly one-time launch consumption.
$cache->delete('launch_' . $token);

$user = $DB->get_record('user', ['id' => $payload['userid'], 'deleted' => 0], 'id, username, firstname, lastname, email');
if (!$user) {
    echo json_encode(['valid' => false, 'error' => 'User not found in Moodle']);
    exit;
}

$cbt = $DB->get_record('cbt', ['id' => $payload['cbtid']], 'id, name, timelimit, active_token, token_expiry');

echo json_encode([
    'valid' => true,
    'userid' => (int)$user->id,
    'username' => $user->username,
    'fullname' => fullname($user),
    'email' => $user->email,
    'cbtid' => (int)$payload['cbtid'],
    'cmid' => (int)$payload['cmid'],
    'cbt' => $cbt ? [
        'id' => (int)$cbt->id,
        'title' => $cbt->name,
        'timelimit' => (int)$cbt->timelimit,
        'active_token' => $cbt->active_token ?? '',
    ] : null,
]);
