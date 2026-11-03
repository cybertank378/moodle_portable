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
 * Standard Moodle library functions and hooks for mod_cbt.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add a new instance of CBT.
 *
 * @param \stdClass $cbt Form data.
 * @param \mod_cbt_mod_form|null $mform
 * @return int Inserted ID.
 */
function cbt_add_instance($cbt, $mform = null) {
    global $DB;

    $cbt->timecreated = time();
    $cbt->timemodified = $cbt->timecreated;

    if (empty($cbt->tokensecret)) {
        $cbt->tokensecret = bin2hex(random_bytes(16));
    }

    $cbt->id = $DB->insert_record('cbt', $cbt);
    cbt_grade_item_update($cbt);

    return $cbt->id;
}

/**
 * Update existing instance of CBT.
 *
 * @param \stdClass $cbt Form data.
 * @param \mod_cbt_mod_form|null $mform
 * @return bool
 */
function cbt_update_instance($cbt, $mform = null) {
    global $DB;

    $cbt->timemodified = time();
    $cbt->id = $cbt->instance;

    $DB->update_record('cbt', $cbt);
    cbt_grade_item_update($cbt);

    // Invalidate cached MUC snapshot when exam settings change
    \mod_cbt\infrastructure\cache\muc_snapshot_store::purge_cache((int)$cbt->id);

    return true;
}

/**
 * Delete instance of CBT and related records.
 *
 * @param int $id
 * @return bool
 */
function cbt_delete_instance($id) {
    global $DB;

    if (!$cbt = $DB->get_record('cbt', ['id' => $id])) {
        return false;
    }

    // Delete attempts and violation logs
    $attempts = $DB->get_records('cbt_attempts', ['cbtid' => $id], '', 'id');
    if (!empty($attempts)) {
        $attempt_ids = array_keys($attempts);
        $DB->delete_records_list('cbt_violation_logs', 'attemptid', $attempt_ids);
        $DB->delete_records('cbt_attempts', ['cbtid' => $id]);
    }

    $DB->delete_records('cbt', ['id' => $id]);
    cbt_grade_item_delete($cbt);

    \mod_cbt\infrastructure\cache\muc_snapshot_store::purge_cache((int)$id);

    return true;
}

/**
 * Declare supported features.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed True if yes (some features may use other values)
 */
function cbt_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

/**
 * Create or update grade item for the given CBT instance.
 *
 * @param \stdClass $cbt
 * @param mixed $grades
 * @return int 0 if ok, error code otherwise
 */
function cbt_grade_item_update($cbt, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $cbt->name,
        'idnumber' => $cbt->cmidnumber ?? '',
    ];

    if ($cbt->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = (float)$cbt->grade;
        $params['grademin']  = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/cbt', $cbt->course, 'mod', 'cbt', $cbt->id, 0, $grades, $params);
}

/**
 * Delete grade item for given CBT instance.
 *
 * @param \stdClass $cbt
 * @return int 0 if ok, error code otherwise
 */
function cbt_grade_item_delete($cbt) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/cbt', $cbt->course, 'mod', 'cbt', $cbt->id, 0, null, ['deleted' => 1]);
}

/**
 * Update grades in central gradebook.
 *
 * @param \stdClass $cbt
 * @param int $userid Specific user or 0 for all
 * @param bool $nullifnone
 */
function cbt_update_grades($cbt, $userid = 0, $nullifnone = true) {
    global $DB;

    $params = ['cbtid' => $cbt->id, 'status' => \mod_cbt\domain\entity\exam_attempt::STATUS_SUBMITTED];
    if ($userid > 0) {
        $params['userid'] = $userid;
    }

    $attempts = $DB->get_records('cbt_attempts', $params);
    $grades = [];

    foreach ($attempts as $attempt) {
        $grades[$attempt->userid] = new \stdClass();
        $grades[$attempt->userid]->userid = $attempt->userid;
        $grades[$attempt->userid]->rawgrade = $attempt->sumgrades !== null ? (float)$attempt->sumgrades : null;
        $grades[$attempt->userid]->datesubmitted = $attempt->timefinish;
    }

    cbt_grade_item_update($cbt, $grades);
}

/**
 * Return a list of page types.
 *
 * @param string $pagetype
 * @param string $parentcontext
 * @param string $currentcontext
 * @return array
 */
function cbt_page_type_list($pagetype, $parentcontext, $currentcontext) {
    return [
        'mod-cbt-*' => get_string('page-mod-cbt-x', 'mod_cbt'),
        'mod-cbt-view' => get_string('page-mod-cbt-view', 'mod_cbt'),
    ];
}

