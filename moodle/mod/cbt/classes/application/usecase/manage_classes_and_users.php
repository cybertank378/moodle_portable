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

namespace mod_cbt\application\usecase;

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to manage student accounts, classrooms/groups (cohorts), and group memberships from Next.js.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_classes_and_users {

    /**
     * Create or update a student user.
     *
     * @param array $data [ 'username' => string, 'password' => string, 'firstname' => string, 'lastname' => string, 'email' => string, 'class_id' => ?int ]
     * @return array [ 'success' => bool, 'user_id' => int, 'message' => string ]
     * @throws \moodle_exception
     */
    public function create_or_update_student(array $data): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $username = strtolower(trim((string)($data['username'] ?? '')));
        $firstname = trim((string)($data['firstname'] ?? ''));
        $lastname = trim((string)($data['lastname'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $class_id = isset($data['class_id']) ? (int)$data['class_id'] : 0;
        $moodle_user_id = isset($data['moodle_user_id']) ? (int)$data['moodle_user_id'] : 0;
        $replace_cohort = !empty($data['replace_cohort']);
        $suspended = isset($data['is_active']) ? ($data['is_active'] ? 0 : 1) : null;
        $role = strtoupper(trim((string)($data['role'] ?? '')));
        $moodle_roles = is_array($data['moodle_roles'] ?? null) ? $data['moodle_roles'] : [];

        if ($username === '' || $firstname === '') {
            throw new \moodle_exception('invalidusernameorname', 'mod_cbt', '', null, 'Username/NIS dan Nama Lengkap wajib diisi.');
        }

        $now = time();
        $existing = null;
        if ($moodle_user_id > 0) {
            $existing = $DB->get_record('user', ['id' => $moodle_user_id, 'deleted' => 0]);
        }
        if (!$existing) {
            $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
        }

        if ($existing) {
            $existing->username = $username;
            $existing->firstname = $firstname;
            if ($lastname !== '' && $lastname !== '-') {
                $existing->lastname = $lastname;
            }
            if ($email !== '') {
                $existing->email = $email;
            }
            if ($password !== '' && !str_starts_with($password, '$argon2')) {
                $existing->password = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($suspended !== null) {
                $existing->suspended = $suspended;
            }
            $existing->timemodified = $now;
            $DB->update_record('user', $existing);
            $user_id = (int)$existing->id;
            $msg = "Akun pengguna {$username} berhasil diperbarui.";
        } else {
            if ($email === '') {
                $email = $username . '@cbt.local';
            }
            $user = new \stdClass();
            $user->auth = 'manual';
            $user->confirmed = 1;
            $user->suspended = $suspended ?? 0;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->username = $username;
            $rawPass = ($password !== '' && !str_starts_with($password, '$argon2')) ? $password : $username;
            $user->password = password_hash($rawPass, PASSWORD_DEFAULT);
            $user->firstname = $firstname;
            $user->lastname = $lastname !== '' ? $lastname : '-';
            $user->email = $email;
            $user->lang = 'en';
            $user->timecreated = $now;
            $user->timemodified = $now;

            $user_id = (int)$DB->insert_record('user', $user);
            $msg = "Akun pengguna {$username} berhasil dibuat.";
        }

        if ($role !== '' || !empty($moodle_roles) || $username === 'admin') {
            $this->sync_user_moodle_roles($user_id, $role, $moodle_roles, $username);
        }

        if ($role !== 'ADMIN' && $username !== 'admin') {
            if ($replace_cohort) {
                $this->replace_student_cohort($user_id, $class_id);
            } else if ($class_id > 0) {
                // If class_id provided without replace, add student to cohort
                $this->add_member_to_cohort($class_id, $user_id);
            }
        }

        return [
            'success' => true,
            'user_id' => $user_id,
            'message' => $msg,
        ];
    }

    /**
     * Synchronize Moodle system roles & siteadmins to match Aksaventra CBT role:
     * - ADMIN   -> manager (+ siteadmins)
     * - TEACHER -> editingteacher + coursecreator
     * - PROCTOR -> teacher (Non-editing teacher)
     * - STUDENT -> remove managed staff system roles
     *
     * @param int $user_id
     * @param string $role
     * @param array $moodle_roles
     * @param string $username
     * @return void
     */
    private function sync_user_moodle_roles(int $user_id, string $role, array $moodle_roles, string $username): void {
        global $DB, $CFG;

        $normalizedRole = strtoupper(trim($role));
        if ($username === 'admin') {
            $normalizedRole = 'ADMIN';
        }

        $targetShortnames = [];
        if (!empty($moodle_roles)) {
            foreach ($moodle_roles as $r) {
                $short = strtolower(trim((string)$r));
                if ($short !== '' && $short !== 'student') {
                    $targetShortnames[] = $short;
                }
            }
        }

        if (empty($targetShortnames)) {
            if ($normalizedRole === 'ADMIN') {
                $targetShortnames = ['manager'];
            } else if ($normalizedRole === 'TEACHER') {
                $targetShortnames = ['editingteacher', 'coursecreator'];
            } else if ($normalizedRole === 'PROCTOR') {
                $targetShortnames = ['teacher'];
            }
        }

        // Ensure TEACHER always includes coursecreator alongside editingteacher.
        if ($normalizedRole === 'TEACHER') {
            if (!in_array('editingteacher', $targetShortnames, true)) {
                $targetShortnames[] = 'editingteacher';
            }
            if (!in_array('coursecreator', $targetShortnames, true)) {
                $targetShortnames[] = 'coursecreator';
            }
        }
        $targetShortnames = array_values(array_unique($targetShortnames));

        // Update siteadmins for ADMIN vs non-ADMIN (never remove primary 'admin' user).
        $adminids = array_values(array_filter(array_map('intval', array_map('trim', explode(',', (string)($CFG->siteadmins ?? ''))))));
        if ($normalizedRole === 'ADMIN') {
            if (!in_array($user_id, $adminids, true)) {
                $adminids[] = $user_id;
                set_config('siteadmins', implode(',', $adminids));
            }
        } else if ($username !== 'admin' && in_array($user_id, $adminids, true)) {
            $remaining = array_values(array_filter($adminids, fn($id) => $id !== $user_id));
            if (!empty($remaining)) {
                set_config('siteadmins', implode(',', $remaining));
            }
        }

        // Assign / unassign managed staff roles at system context.
        $managedShortnames = ['manager', 'coursecreator', 'editingteacher', 'teacher'];
        try {
            $syscontext = \context_system::instance();
            foreach ($managedShortnames as $shortname) {
                $rolerec = $DB->get_record('role', ['shortname' => $shortname], 'id');
                if (!$rolerec) {
                    continue;
                }
                $roleid = (int)$rolerec->id;
                if (in_array($shortname, $targetShortnames, true)) {
                    role_assign($roleid, $user_id, $syscontext->id);
                } else {
                    role_unassign($roleid, $user_id, $syscontext->id);
                }
            }
        } catch (\Throwable $e) {
            debugging('sync_user_moodle_roles skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Create or update a classroom/group (Moodle Cohort) using official Moodle cohort API.
     *
     * @param string $name Class name e.g. "X RPL 1"
     * @param string $code Class/Cohort idnumber e.g. "X-RPL-1"
     * @param string $description
     * @param int $moodle_id Optional existing cohort id
     * @return array [ 'success' => bool, 'class_id' => int, 'message' => string ]
     */
    public function create_class_group(string $name, string $code = '', string $description = '', int $moodle_id = 0): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $name = trim($name);
        $code = trim($code !== '' ? $code : str_replace(' ', '-', strtoupper($name)));

        $existing = null;
        if ($moodle_id > 0) {
            $existing = $DB->get_record('cohort', ['id' => $moodle_id]);
        }
        if (!$existing && $code !== '') {
            $existing = $DB->get_record('cohort', ['idnumber' => $code]);
        }
        if (!$existing) {
            $existing = $DB->get_record('cohort', ['name' => $name]);
        }

        if ($existing) {
            $existing->name = $name;
            $existing->idnumber = $code;
            $existing->description = $description;
            $existing->visible = 1;
            cohort_update_cohort($existing);
            return [
                'success' => true,
                'class_id' => (int)$existing->id,
                'message' => "Cohort/Kelas {$name} berhasil diperbarui.",
            ];
        }

        $syscontext = \context_system::instance();
        $cohort = new \stdClass();
        $cohort->contextid = $syscontext->id;
        $cohort->name = $name;
        $cohort->idnumber = $code;
        $cohort->description = $description;
        $cohort->descriptionformat = FORMAT_HTML;
        $cohort->visible = 1;

        $id = (int)cohort_add_cohort($cohort);

        return [
            'success' => true,
            'class_id' => $id,
            'message' => "Cohort/Kelas {$name} berhasil dibuat.",
        ];
    }

    /**
     * List all visible Moodle cohorts (classrooms/groups) with student counts.
     *
     * @return array
     */
    public function list_classes(): array {
        global $DB;

        $records = $DB->get_records('cohort', ['visible' => 1], 'name ASC', 'id, name, idnumber, description');
        $countrows = $DB->get_records_sql(
            "SELECT cohortid, COUNT(userid) AS student_count
               FROM {cohort_members}
              GROUP BY cohortid"
        );

        $countmap = [];
        foreach ($countrows as $row) {
            $countmap[(int)$row->cohortid] = (int)$row->student_count;
        }

        $result = [];
        foreach ($records as $r) {
            $result[] = [
                'id' => (int)$r->id,
                'name' => $r->name,
                'code' => $r->idnumber ?? '',
                'description' => trim(strip_tags((string)($r->description ?? ''))),
                'student_count' => $countmap[(int)$r->id] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Add student to Moodle cohort using official cohort_add_member API (triggers cohort enrol sync).
     *
     * @param int $cohortid
     * @param int $userid
     * @return bool
     */
    public function add_member_to_cohort(int $cohortid, int $userid): bool {
        global $DB, $CFG;
        if ($cohortid <= 0 || $userid <= 0) {
            return false;
        }
        if (!$DB->record_exists('cohort', ['id' => $cohortid])) {
            return false;
        }
        if ($DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid])) {
            return true;
        }

        require_once($CFG->dirroot . '/cohort/lib.php');
        cohort_add_member($cohortid, $userid);
        return true;
    }

    /**
     * Remove student from Moodle cohort using official cohort_remove_member API.
     *
     * @param int $cohortid
     * @param int $userid
     * @return bool
     */
    public function remove_member_from_cohort(int $cohortid, int $userid): bool {
        global $DB, $CFG;
        if ($cohortid <= 0 || $userid <= 0) {
            return false;
        }
        if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid])) {
            return true;
        }

        require_once($CFG->dirroot . '/cohort/lib.php');
        cohort_remove_member($cohortid, $userid);
        return true;
    }

    /**
     * Replace a student's cohort membership cleanly without churning existing matching cohort.
     *
     * @param int $userid
     * @param int $target_cohortid 0 to unassign from all cohorts
     * @return void
     */
    public function replace_student_cohort(int $userid, int $target_cohortid = 0): void {
        global $DB, $CFG;
        if ($userid <= 0) {
            return;
        }
        require_once($CFG->dirroot . '/cohort/lib.php');

        $current = $DB->get_records('cohort_members', ['userid' => $userid]);
        foreach ($current as $cm) {
            if ((int)$cm->cohortid !== $target_cohortid) {
                cohort_remove_member((int)$cm->cohortid, $userid);
            }
        }

        if ($target_cohortid > 0) {
            $this->add_member_to_cohort($target_cohortid, $userid);
        }
    }

    /**
     * Batch assign/unassign multiple students to a Moodle cohort.
     *
     * @param array $userids
     * @param int $cohortid 0 to remove from all cohorts
     * @param bool $replace
     * @return int Number of processed users
     */
    public function bulk_assign_cohort(array $userids, int $cohortid = 0, bool $replace = true): int {
        $count = 0;
        foreach ($userids as $uid) {
            $userid = (int)$uid;
            if ($userid <= 0) {
                continue;
            }
            if ($replace) {
                $this->replace_student_cohort($userid, $cohortid);
            } else if ($cohortid > 0) {
                $this->add_member_to_cohort($cohortid, $userid);
            }
            $count++;
        }
        return $count;
    }

    /**
     * Enrol a Moodle user into a course using the official manual enrolment plugin.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $roleshortname
     * @return bool
     */
    public function enroll_student_in_course(int $userid, int $courseid, string $roleshortname = 'student'): bool {
        global $DB, $CFG;
        if ($userid <= 0 || $courseid <= 1) {
            return false;
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0]) || !$DB->record_exists('course', ['id' => $courseid])) {
            return false;
        }

        require_once($CFG->libdir . '/enrollib.php');
        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            return false;
        }

        $role = $DB->get_record('role', ['shortname' => $roleshortname]);
        $roleid = $role ? (int)$role->id : 5;

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return false;
            }
            $instanceid = $enrol->add_default_instance($course);
            if (!$instanceid) {
                $instanceid = $enrol->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED, 'roleid' => $roleid]);
            }
            $instance = $DB->get_record('enrol', ['id' => $instanceid]);
        }

        if (!$instance) {
            return false;
        }

        $enrol->enrol_user($instance, $userid, $roleid);
        return true;
    }

    /**
     * Enrol an entire Moodle Cohort into a course via the official cohort enrolment plugin
     * and fallback manual enrolment for members if cohort plugin is disabled.
     *
     * @param int $cohortid
     * @param int $courseid
     * @param string $roleshortname
     * @return bool
     */
    public function enroll_cohort_in_course(int $cohortid, int $courseid, string $roleshortname = 'student'): bool {
        global $DB, $CFG;
        if ($cohortid <= 0 || $courseid <= 1) {
            return false;
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || !$DB->record_exists('cohort', ['id' => $cohortid])) {
            return false;
        }

        require_once($CFG->libdir . '/enrollib.php');
        $role = $DB->get_record('role', ['shortname' => $roleshortname]);
        $roleid = $role ? (int)$role->id : 5;

        $cohortplugin = enrol_get_plugin('cohort');
        if ($cohortplugin) {
            $existing = $DB->get_record('enrol', [
                'courseid' => $courseid,
                'enrol' => 'cohort',
                'customint1' => $cohortid,
            ]);
            if (!$existing) {
                $cohortplugin->add_instance($course, [
                    'status' => ENROL_INSTANCE_ENABLED,
                    'customint1' => $cohortid,
                    'roleid' => $roleid,
                    'customint2' => 0,
                ]);
            }
            if (file_exists($CFG->dirroot . '/enrol/cohort/locallib.php')) {
                require_once($CFG->dirroot . '/enrol/cohort/locallib.php');
                if (function_exists('enrol_cohort_sync')) {
                    $trace = new \null_progress_trace();
                    enrol_cohort_sync($trace, $courseid);
                }
            }
            return true;
        }

        // Fallback: enrol each cohort member manually
        $members = $DB->get_records('cohort_members', ['cohortid' => $cohortid], '', 'userid');
        foreach ($members as $m) {
            $this->enroll_student_in_course((int)$m->userid, $courseid, $roleshortname);
        }
        return true;
    }

    /**
     * Enrol students and/or cohorts into a Moodle Course and optional CBT Exam roster.
     *
     * @param array $data [
     *   'course_id' => int,
     *   'course_name' => string,
     *   'cbt_id' => int,
     *   'cohort_ids' => int[],
     *   'user_ids' => int[],
     *   'room_code' => string,
     *   'seat_prefix' => string
     * ]
     * @return array
     */
    public function enroll_students_to_course_or_cbt(array $data): array {
        global $DB;

        $courseid = (int)($data['course_id'] ?? 0);
        $coursename = trim((string)($data['course_name'] ?? ''));
        $cbtid = (int)($data['cbt_id'] ?? 0);
        $cohortids = is_array($data['cohort_ids'] ?? null) ? $data['cohort_ids'] : [];
        $userids = is_array($data['user_ids'] ?? null) ? $data['user_ids'] : [];
        $roomcode = trim((string)($data['room_code'] ?? 'LAB-01')) ?: 'LAB-01';
        $seatprefix = trim((string)($data['seat_prefix'] ?? 'M')) ?: 'M';

        if ($courseid <= 1 && $cbtid > 0 && $DB->get_manager()->table_exists('cbt')) {
            try {
                $cbt = $DB->get_record('cbt', ['id' => $cbtid], 'id, course');
                if ($cbt && (int)$cbt->course > 1) {
                    $courseid = (int)$cbt->course;
                }
            } catch (\Throwable $e) {
                debugging('Failed to resolve course from cbt_id: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($courseid <= 1 && $coursename !== '') {
            $course = $DB->get_record('course', ['fullname' => $coursename], 'id')
                ?: $DB->get_record('course', ['shortname' => $coursename], 'id');
            if ($course && (int)$course->id > 1) {
                $courseid = (int)$course->id;
            }
        }

        $alluserids = [];
        foreach ($userids as $uid) {
            $id = (int)$uid;
            if ($id > 0) {
                $alluserids[$id] = true;
            }
        }

        $enrolledcohorts = 0;
        foreach ($cohortids as $cid) {
            $cohortid = (int)$cid;
            if ($cohortid <= 0) {
                continue;
            }
            if ($courseid > 1) {
                if ($this->enroll_cohort_in_course($cohortid, $courseid)) {
                    $enrolledcohorts++;
                }
            }
            $members = $DB->get_records('cohort_members', ['cohortid' => $cohortid], '', 'userid');
            foreach ($members as $m) {
                $alluserids[(int)$m->userid] = true;
            }
        }

        $enrolledusers = 0;
        $rosterrepo = null;
        if ($cbtid > 0 && class_exists('mod_cbt\\infrastructure\\persistence\\db_attempt_repository')) {
            $rosterrepo = new \mod_cbt\infrastructure\persistence\db_attempt_repository();
        }

        $seatidx = 1;
        foreach (array_keys($alluserids) as $uid) {
            if ($courseid > 1) {
                if ($this->enroll_student_in_course($uid, $courseid)) {
                    $enrolledusers++;
                }
            } else {
                $enrolledusers++;
            }

            if ($rosterrepo !== null && $cbtid > 0) {
                try {
                    $seat = $seatprefix . '-' . str_pad((string)$seatidx, 2, '0', STR_PAD_LEFT);
                    $rosterrepo->assign_roster_participant($cbtid, $uid, $roomcode, $seat);
                } catch (\Throwable $e) {
                    debugging('Roster assign skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            $seatidx++;
        }

        return [
            'success' => true,
            'course_id' => $courseid,
            'enrolled_users' => $enrolledusers,
            'enrolled_cohorts' => $enrolledcohorts,
            'message' => "Berhasil mendaftarkan {$enrolledusers} siswa ({$enrolledcohorts} cohort) ke course/ujian.",
        ];
    }

    /**
     * Get all students in a classroom/cohort, or all students if cohortid <= 0.
     *
     * @param int $cohortid
     * @return array
     */
    public function get_students_by_class(int $cohortid): array {
        if ($cohortid <= 0) {
            return $this->list_all_students();
        }

        global $DB;

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.password,
                       cm.cohortid AS class_id, c.name AS class_name
                  FROM {cohort_members} cm
                  JOIN {user} u ON u.id = cm.userid
             LEFT JOIN {cohort} c ON c.id = cm.cohortid
                 WHERE cm.cohortid = :cohortid AND u.deleted = 0
              ORDER BY u.firstname ASC, u.lastname ASC";

        $records = $DB->get_records_sql($sql, ['cohortid' => $cohortid]);
        $list = [];

        foreach ($records as $u) {
            $list[] = [
                'user_id' => (int)$u->id,
                'username' => $u->username,
                'full_name' => trim($u->firstname . ' ' . $u->lastname),
                'email' => $u->email,
                'password_hash' => (string)($u->password ?? ''),
                'class_id' => (int)($u->class_id ?? 0),
                'class_name' => (string)($u->class_name ?? ''),
            ];
        }

        return $list;
    }

    /**
     * List all active non-guest Moodle users (with optional cohort membership and resolved CBT role) for sync.
     *
     * @return array
     */
    public function list_all_students(): array {
        global $DB, $CFG;

        $records = $DB->get_records_select(
            'user',
            "deleted = 0 AND suspended = 0 AND username <> :guest",
            ['guest' => 'guest'],
            'id ASC',
            'id, username, firstname, lastname, email, password'
        );

        $siteadminids = [];
        $rawadmins = explode(',', (string)($CFG->siteadmins ?? ''));
        foreach ($rawadmins as $aid) {
            $id = (int)trim($aid);
            if ($id > 0) {
                $siteadminids[$id] = true;
            }
        }

        $rolemap = [];
        $userroleshorts = [];
        try {
            $roleassignments = $DB->get_records_sql(
                "SELECT ra.id, ra.userid, r.shortname, ctx.contextlevel
                   FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                   JOIN {context} ctx ON ctx.id = ra.contextid"
            );
            $flags = [];
            foreach ($roleassignments as $ra) {
                $uid = (int)$ra->userid;
                $short = strtolower(trim((string)$ra->shortname));
                $ctxlevel = (int)$ra->contextlevel;
                if ($short !== '') {
                    $userroleshorts[$uid][$short] = true;
                }
                if (!isset($flags[$uid])) {
                    $flags[$uid] = [
                        'teacher_role' => false,
                        'system_manager' => false,
                        'other_manager' => false,
                        'proctor' => false,
                    ];
                }
                if (in_array($short, ['coursecreator', 'editingteacher'], true)) {
                    $flags[$uid]['teacher_role'] = true;
                } else if ($short === 'manager') {
                    if ($ctxlevel === CONTEXT_SYSTEM) {
                        $flags[$uid]['system_manager'] = true;
                    } else {
                        $flags[$uid]['other_manager'] = true;
                    }
                } else if ($short === 'teacher') {
                    $flags[$uid]['proctor'] = true;
                }
            }
            foreach ($flags as $uid => $f) {
                // Non-siteadmin users with coursecreator or editingteacher are always TEACHER
                // (even if they also hold manager on a category/course/system).
                // Only pure system-context manager (without coursecreator/editingteacher) maps to ADMIN.
                if ($f['teacher_role']) {
                    $rolemap[$uid] = 'TEACHER';
                } else if ($f['system_manager']) {
                    $rolemap[$uid] = 'ADMIN';
                } else if ($f['other_manager']) {
                    $rolemap[$uid] = 'TEACHER';
                } else if ($f['proctor']) {
                    $rolemap[$uid] = 'PROCTOR';
                }
            }
        } catch (\Throwable $e) {
            debugging('Role lookup skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $memberships = $DB->get_records_sql(
            "SELECT cm.id, cm.userid, cm.cohortid AS class_id, c.name AS class_name
               FROM {cohort_members} cm
               JOIN {cohort} c ON c.id = cm.cohortid
              WHERE c.visible = 1
              ORDER BY cm.id ASC"
        );

        $usermap = [];
        foreach ($memberships as $m) {
            $uid = (int)$m->userid;
            if (!isset($usermap[$uid])) {
                $usermap[$uid] = [
                    'class_id' => (int)$m->class_id,
                    'class_name' => (string)$m->class_name,
                ];
            }
        }

        $list = [];
        foreach ($records as $u) {
            $uid = (int)$u->id;
            $role = 'STUDENT';
            if (isset($siteadminids[$uid]) || strtolower((string)$u->username) === 'admin' || (function_exists('is_siteadmin') && is_siteadmin($uid))) {
                $role = 'ADMIN';
            } else if (isset($rolemap[$uid])) {
                $role = $rolemap[$uid];
            }

            $list[] = [
                'user_id' => $uid,
                'username' => $u->username,
                'full_name' => trim($u->firstname . ' ' . $u->lastname),
                'email' => $u->email ?? '',
                'password_hash' => (string)($u->password ?? ''),
                'role' => $role,
                'moodle_roles' => implode(',', array_keys($userroleshorts[$uid] ?? [])),
                'class_id' => $usermap[$uid]['class_id'] ?? 0,
                'class_name' => $usermap[$uid]['class_name'] ?? '',
            ];
        }

        return $list;
    }
}

