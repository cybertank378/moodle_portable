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

        if ($username === '' || $firstname === '') {
            throw new \moodle_exception('invalidusernameorname', 'mod_cbt', '', null, 'Username/NIS dan Nama Lengkap wajib diisi.');
        }

        if ($email === '') {
            $email = $username . '@cbt.local';
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
            if ($lastname !== '') {
                $existing->lastname = $lastname;
            }
            $existing->email = $email;
            if ($password !== '') {
                $existing->password = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($suspended !== null) {
                $existing->suspended = $suspended;
            }
            $existing->timemodified = $now;
            $DB->update_record('user', $existing);
            $user_id = (int)$existing->id;
            $msg = "Akun siswa {$username} berhasil diperbarui.";
        } else {
            $user = new \stdClass();
            $user->auth = 'manual';
            $user->confirmed = 1;
            $user->suspended = $suspended ?? 0;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->username = $username;
            $user->password = password_hash($password !== '' ? $password : $username, PASSWORD_DEFAULT);
            $user->firstname = $firstname;
            $user->lastname = $lastname !== '' ? $lastname : '-';
            $user->email = $email;
            $user->lang = 'en';
            $user->timecreated = $now;
            $user->timemodified = $now;

            $user_id = (int)$DB->insert_record('user', $user);
            $msg = "Akun siswa {$username} berhasil dibuat.";
        }

        if ($replace_cohort) {
            $DB->delete_records('cohort_members', ['userid' => $user_id]);
        }

        // If class_id provided, add student to cohort
        if ($class_id > 0) {
            $this->add_member_to_cohort($class_id, $user_id);
        }

        return [
            'success' => true,
            'user_id' => $user_id,
            'message' => $msg,
        ];
    }

    /**
     * Create or update a classroom/group (cohort).
     *
     * @param string $name Class name e.g. "X RPL 1"
     * @param string $code Class code e.g. "X-RPL-1"
     * @param string $description
     * @param int $moodle_id Optional existing cohort id
     * @return array [ 'success' => bool, 'class_id' => int, 'message' => string ]
     */
    public function create_class_group(string $name, string $code = '', string $description = '', int $moodle_id = 0): array {
        global $DB;

        $name = trim($name);
        $code = trim($code !== '' ? $code : str_replace(' ', '-', strtoupper($name)));

        $existing = null;
        if ($moodle_id > 0) {
            $existing = $DB->get_record('cohort', ['id' => $moodle_id]);
        }
        if (!$existing) {
            $existing = $DB->get_record('cohort', ['name' => $name]);
        }

        if ($existing) {
            $existing->name = $name;
            $existing->idnumber = $code;
            $existing->description = $description;
            $existing->timemodified = time();
            $DB->update_record('cohort', $existing);
            return [
                'success' => true,
                'class_id' => (int)$existing->id,
                'message' => "Kelas {$name} berhasil diperbarui.",
            ];
        }

        $now = time();
        $cohort = new \stdClass();
        $cohort->contextid = 1; // System context
        $cohort->name = $name;
        $cohort->idnumber = $code;
        $cohort->description = $description;
        $cohort->descriptionformat = FORMAT_HTML;
        $cohort->visible = 1;
        $cohort->timecreated = $now;
        $cohort->timemodified = $now;

        $id = (int)$DB->insert_record('cohort', $cohort);

        return [
            'success' => true,
            'class_id' => $id,
            'message' => "Kelas {$name} berhasil dibuat.",
        ];
    }

    /**
     * List all classrooms/groups with student counts using portable DML queries.
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
                'description' => $r->description ?? '',
                'student_count' => $countmap[(int)$r->id] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Add student to classroom/cohort.
     *
     * @param int $cohortid
     * @param int $userid
     * @return bool
     */
    public function add_member_to_cohort(int $cohortid, int $userid): bool {
        global $DB;
        $exists = $DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid]);
        if ($exists) {
            return true;
        }

        $record = new \stdClass();
        $record->cohortid = $cohortid;
        $record->userid = $userid;
        $record->timeadded = time();

        return (bool)$DB->insert_record('cohort_members', $record);
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

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email,
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
                'class_id' => (int)($u->class_id ?? 0),
                'class_name' => (string)($u->class_name ?? ''),
            ];
        }

        return $list;
    }

    /**
     * List all active non-guest Moodle users (with optional cohort membership) for sync.
     *
     * @return array
     */
    public function list_all_students(): array {
        global $DB;

        $records = $DB->get_records_select(
            'user',
            "deleted = 0 AND suspended = 0 AND username <> :guest",
            ['guest' => 'guest'],
            'id ASC',
            'id, username, firstname, lastname, email'
        );

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
            $list[] = [
                'user_id' => $uid,
                'username' => $u->username,
                'full_name' => trim($u->firstname . ' ' . $u->lastname),
                'email' => $u->email ?? '',
                'class_id' => $usermap[$uid]['class_id'] ?? 0,
                'class_name' => $usermap[$uid]['class_name'] ?? '',
            ];
        }

        return $list;
    }
}

