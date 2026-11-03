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

namespace mod_cbt\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad-hoc background task to push CBT attempt grades to Moodle Gradebook asynchronously.
 * Prevents mass submit lockouts and DB connection exhaustion (ISSUE-PHP-01).
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_grades_task extends \core\task\adhoc_task {

    /**
     * Execute grade synchronization.
     */
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/cbt/lib.php');

        $data = $this->get_custom_data();
        if (empty($data->cbtid) || empty($data->userid)) {
            return;
        }

        $cbt = $DB->get_record('cbt', ['id' => $data->cbtid]);
        if (!$cbt) {
            return;
        }

        cbt_update_grades($cbt, (int)$data->userid);
    }
}

