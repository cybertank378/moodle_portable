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

namespace mod_cbt;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle event observer that dispatches real-time sync webhooks to Aksaventra CBT.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Set to true during inbound API writes from Next.js to avoid webhook echo loops.
     *
     * @var bool
     */
    public static bool $suppress_webhook = false;

    public static function on_user_created(\core\event\user_created $event): void {
        self::dispatch('user', 'created', (int)$event->objectid);
    }

    public static function on_user_updated(\core\event\user_updated $event): void {
        self::dispatch('user', 'updated', (int)$event->objectid);
    }

    public static function on_user_deleted(\core\event\user_deleted $event): void {
        self::dispatch('user', 'deleted', (int)$event->objectid);
    }

    public static function on_cohort_created(\core\event\cohort_created $event): void {
        self::dispatch('cohort', 'created', (int)$event->objectid);
    }

    public static function on_cohort_updated(\core\event\cohort_updated $event): void {
        self::dispatch('cohort', 'updated', (int)$event->objectid);
    }

    public static function on_cohort_deleted(\core\event\cohort_deleted $event): void {
        self::dispatch('cohort', 'deleted', (int)$event->objectid);
    }

    public static function on_cohort_member_added(\core\event\cohort_member_added $event): void {
        self::dispatch('cohort_member', 'added', (int)$event->objectid, [
            'userid' => (int)$event->relateduserid,
        ]);
    }

    public static function on_cohort_member_removed(\core\event\cohort_member_removed $event): void {
        self::dispatch('cohort_member', 'removed', (int)$event->objectid, [
            'userid' => (int)$event->relateduserid,
        ]);
    }

    public static function on_course_created(\core\event\course_created $event): void {
        self::dispatch('course', 'created', (int)$event->objectid);
    }

    public static function on_course_updated(\core\event\course_updated $event): void {
        self::dispatch('course', 'updated', (int)$event->objectid);
    }

    public static function on_course_deleted(\core\event\course_deleted $event): void {
        self::dispatch('course', 'deleted', (int)$event->objectid);
    }

    public static function on_course_module_changed(\core\event\base $event): void {
        $other = $event->other ?? [];
        $modulename = $other['modulename'] ?? '';
        if ($modulename !== 'cbt') {
            return;
        }
        $action = str_contains($event->eventname, 'deleted') ? 'deleted' : 'updated';
        if (str_contains($event->eventname, 'created')) {
            $action = 'created';
        }
        self::dispatch('cbt', $action, (int)($other['instanceid'] ?? $event->objectid));
    }

    public static function on_question_changed(\core\event\base $event): void {
        $action = 'updated';
        if (str_contains($event->eventname, 'created')) {
            $action = 'created';
        } elseif (str_contains($event->eventname, 'deleted')) {
            $action = 'deleted';
        }
        self::dispatch('question', $action, (int)$event->objectid);
    }

    /**
     * Dispatch a fast, non-blocking HTTP POST webhook to Aksaventra CBT.
     */
    public static function dispatch(string $entity, string $action, int $objectid, array $extra = []): void {
        if (self::$suppress_webhook || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return;
        }

        $portalurl = rtrim((string)(get_config('mod_cbt', 'portal_url') ?: 'http://localhost:3000'), '/');
        $secret = (string)(get_config('mod_cbt', 'webhook_secret') ?: 'aksaventra_sync_secret_2026');
        $endpoint = $portalurl . '/api/sync/moodle-webhook';

        $payload = json_encode([
            'entity' => $entity,
            'action' => $action,
            'objectId' => $objectid,
            'extra' => $extra,
            'timestamp' => time(),
        ]);

        if (!function_exists('curl_init') || $payload === false) {
            return;
        }

        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 800,
                CURLOPT_TIMEOUT_MS => 1500,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Moodle-Webhook-Secret: ' . $secret,
                ],
            ]);
            @curl_exec($ch);
            @curl_close($ch);
        } catch (\Throwable $e) {
            // Never block Moodle transactions if CBT portal is unreachable.
            debugging('mod_cbt webhook dispatch failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
