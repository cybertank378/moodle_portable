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

namespace mod_cbt\domain\entity;

defined('MOODLE_INTERNAL') || die();

/**
 * Anti-cheat strike policy and violation evaluation domain entity.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class strike_policy {
    public const EVENT_TAB_BLUR = 'TAB_BLUR';
    public const EVENT_FULLSCREEN_EXIT = 'FULLSCREEN_EXIT';
    public const EVENT_DEVTOOLS_OPEN = 'DEVTOOLS_OPEN';
    public const EVENT_PROCTOR_UNLOCK = 'PROCTOR_UNLOCK';
    public const EVENT_TIME_EXTEND = 'TIME_EXTEND';
    public const EVENT_RESET_DEVICE = 'RESET_DEVICE';
    public const EVENT_FORCE_SUBMIT = 'FORCE_SUBMIT';

    /**
     * Evaluate violation event against current strikes and max threshold.
     *
     * @param string $event_type
     * @param int $current_strikes
     * @param int $max_strikes (0 = no auto lock)
     * @return array [
     *     'new_strikes' => int,
     *     'strike_added' => int,
     *     'should_lock' => bool,
     *     'reason' => string
     * ]
     */
    public static function evaluate(string $event_type, int $current_strikes, int $max_strikes = 3): array {
        $added = 0;
        $should_lock = false;
        $reason = '';

        switch ($event_type) {
            case self::EVENT_DEVTOOLS_OPEN:
                // Opening DevTools can trigger immediate lock or full penalty
                $added = max(1, $max_strikes);
                $should_lock = true;
                $reason = 'Developer tools or inspection opened during exam';
                break;

            case self::EVENT_TAB_BLUR:
                $added = 1;
                $reason = 'Browser tab lost focus or switched window';
                break;

            case self::EVENT_FULLSCREEN_EXIT:
                $added = 1;
                $reason = 'Student exited fullscreen examination mode';
                break;

            default:
                $added = 1;
                $reason = 'Security rule violation: ' . $event_type;
                break;
        }

        $new_strikes = $current_strikes + $added;
        if ($max_strikes > 0 && $new_strikes >= $max_strikes) {
            $should_lock = true;
        }

        return [
            'new_strikes' => $new_strikes,
            'strike_added' => $added,
            'should_lock' => $should_lock,
            'reason' => $reason,
        ];
    }
}
