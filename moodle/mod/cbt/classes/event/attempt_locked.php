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

namespace mod_cbt\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when an attempt is locked by anti-cheat.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_locked extends \core\event\base {

    protected function init() {
        $this->data['objecttable'] = 'cbt_attempts';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    public static function get_name() {
        return 'CBT attempt locked';
    }

    public function get_description() {
        return "The CBT attempt with id '{$this->objectid}' for user '{$this->userid}' was locked " .
            "due to anti-cheat strike policy in context '{$this->contextinstanceid}'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/cbt/view.php', ['id' => $this->contextinstanceid]);
    }

    public static function create_from_attempt(\stdClass $cbt, \stdClass $attempt, string $reason): self {
        $cm = get_coursemodule_from_instance('cbt', $cbt->id, $cbt->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return self::create([
            'objectid' => $attempt->id,
            'userid' => $attempt->userid,
            'context' => $context,
            'other' => [
                'cbtid' => $cbt->id,
                'strikes' => $attempt->strike_count ?? 0,
                'reason' => $reason,
            ],
        ]);
    }
}

