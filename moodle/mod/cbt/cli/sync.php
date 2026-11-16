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
 * Direct High-Performance Database Synchronization Bridge for Aksaventra CBT.
 * Synchronizes Moodle classes (cohorts), students (mdl_user), and CBT exams (mdl_cbt)
 * directly into the MariaDB aksaventra_cbt database used by Next.js.
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

global $DB, $CFG;

function generate_uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

try {
    $dbhost = !empty($CFG->dbhost) ? $CFG->dbhost : '127.0.0.1';
    $dbport = !empty($CFG->dboptions['dbport']) ? (int)$CFG->dboptions['dbport'] : 3306;
    $dbuser = !empty($CFG->dbuser) ? $CFG->dbuser : 'root';
    $dbpass = isset($CFG->dbpass) ? $CFG->dbpass : '';

    $dsn_server = "mysql:host={$dbhost};port={$dbport};charset=utf8mb4";
    $pdo_server = new PDO($dsn_server, $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Ensure database aksaventra_cbt exists
    $pdo_server->exec("CREATE DATABASE IF NOT EXISTS `aksaventra_cbt` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $dsn_cbt = "mysql:host={$dbhost};port={$dbport};dbname=aksaventra_cbt;charset=utf8mb4";
    $pdo = new PDO($dsn_cbt, $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Ensure core Prisma tables exist if not yet migrated
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ClassGroup` (
            `id` VARCHAR(191) NOT NULL PRIMARY KEY,
            `moodleId` INT NULL UNIQUE,
            `name` VARCHAR(191) NOT NULL UNIQUE,
            `code` VARCHAR(191) NULL,
            `description` TEXT NULL,
            `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `User` (
            `id` VARCHAR(191) NOT NULL PRIMARY KEY,
            `moodleUserId` INT NULL UNIQUE,
            `username` VARCHAR(191) NOT NULL UNIQUE,
            `fullName` VARCHAR(191) NOT NULL,
            `passwordHash` VARCHAR(191) NOT NULL,
            `role` ENUM('ADMIN', 'TEACHER', 'PROCTOR', 'STUDENT') NOT NULL DEFAULT 'STUDENT',
            `className` VARCHAR(191) NULL,
            `classGroupId` VARCHAR(191) NULL,
            `roomCode` VARCHAR(191) NULL,
            `isActive` TINYINT(1) NOT NULL DEFAULT 1,
            `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            INDEX `User_role_roomCode_idx` (`role`, `roomCode`),
            CONSTRAINT `User_classGroupId_fkey` FOREIGN KEY (`classGroupId`) REFERENCES `ClassGroup` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `Exam` (
            `id` VARCHAR(191) NOT NULL PRIMARY KEY,
            `moodleCbtId` INT NOT NULL UNIQUE,
            `title` VARCHAR(191) NOT NULL,
            `courseName` VARCHAR(191) NOT NULL,
            `durationSeconds` INT NOT NULL DEFAULT 7200,
            `maxStrikes` INT NOT NULL DEFAULT 3,
            `sebRequired` TINYINT(1) NOT NULL DEFAULT 0,
            `sebConfigKey` VARCHAR(191) NULL,
            `activeToken` VARCHAR(10) NOT NULL,
            `tokenExpiresAt` DATETIME(3) NOT NULL,
            `snapshotHash` VARCHAR(191) NULL,
            `questionsJson` JSON NOT NULL,
            `answerKeysJson` JSON NOT NULL,
            `timeOpen` DATETIME(3) NOT NULL,
            `timeClose` DATETIME(3) NOT NULL,
            `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `Attempt` (
            `id` VARCHAR(191) NOT NULL PRIMARY KEY,
            `moodleAttemptId` INT NULL UNIQUE,
            `examId` VARCHAR(191) NOT NULL,
            `userId` VARCHAR(191) NOT NULL,
            `status` ENUM('IN_PROGRESS', 'LOCKED', 'SUBMITTED', 'ABANDONED') NOT NULL DEFAULT 'IN_PROGRESS',
            `startedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `finishedAt` DATETIME(3) NULL,
            `extraTimeSeconds` INT NOT NULL DEFAULT 0,
            `strikeCount` INT NOT NULL DEFAULT 0,
            `deviceHash` VARCHAR(128) NOT NULL,
            `ipAddress` VARCHAR(45) NOT NULL,
            `questionOrder` JSON NOT NULL,
            `answersJson` JSON NOT NULL,
            `syncVersion` INT NOT NULL DEFAULT 0,
            `finalScore` DOUBLE NULL,
            `syncedToMoodle` TINYINT(1) NOT NULL DEFAULT 0,
            `lastHeartbeatAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY `Attempt_examId_userId_key` (`examId`, `userId`),
            INDEX `Attempt_examId_status_idx` (`examId`, `status`),
            CONSTRAINT `Attempt_examId_fkey` FOREIGN KEY (`examId`) REFERENCES `Exam` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `Attempt_userId_fkey` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ViolationLog` (
            `id` VARCHAR(191) NOT NULL PRIMARY KEY,
            `attemptId` VARCHAR(191) NOT NULL,
            `eventType` ENUM('TAB_BLUR', 'FULLSCREEN_EXIT', 'DEVTOOLS_OPEN', 'PROCTOR_UNLOCK', 'PROCTOR_RESET_DEVICE', 'TIME_EXTEND', 'FORCE_SUBMIT') NOT NULL,
            `details` JSON NULL,
            `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            INDEX `ViolationLog_attemptId_createdAt_idx` (`attemptId`, `createdAt`),
            CONSTRAINT `ViolationLog_attemptId_fkey` FOREIGN KEY (`attemptId`) REFERENCES `Attempt` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 1. SINKRONISASI KELAS (COHORT)
    $cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber, description');
    $cohort_map = []; // moodle cohort id => ClassGroup UUID
    $class_count = 0;

    $stmt_cg_select = $pdo->prepare("SELECT id FROM `ClassGroup` WHERE `moodleId` = ? OR `name` = ? LIMIT 1");
    $stmt_cg_update = $pdo->prepare("UPDATE `ClassGroup` SET `moodleId` = ?, `name` = ?, `code` = ?, `description` = ?, `updatedAt` = NOW(3) WHERE `id` = ?");
    $stmt_cg_insert = $pdo->prepare("INSERT INTO `ClassGroup` (`id`, `moodleId`, `name`, `code`, `description`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3))");

    foreach ($cohorts as $c) {
        $name = trim($c->name);
        $code = !empty($c->idnumber) ? trim($c->idnumber) : $name;
        $desc = strip_tags((string)($c->description ?? ''));

        $stmt_cg_select->execute([(int)$c->id, $name]);
        $row = $stmt_cg_select->fetch();

        if ($row) {
            $cg_id = $row['id'];
            $stmt_cg_update->execute([(int)$c->id, $name, $code, $desc, $cg_id]);
        } else {
            $cg_id = generate_uuid_v4();
            $stmt_cg_insert->execute([$cg_id, (int)$c->id, $name, $code, $desc]);
        }
        $cohort_map[(int)$c->id] = [
            'id' => $cg_id,
            'name' => $name,
        ];
        $class_count++;
    }

    // 2. SINKRONISASI SISWA & PENGGUNA (MDL_USER)
    $users = $DB->get_records_select(
        'user',
        'deleted = 0 AND id > 1',
        null,
        'id ASC',
        'id, username, firstname, lastname, email, password, suspended'
    );

    // Ambil seluruh membership cohort sekaligus
    $cohort_members_raw = $DB->get_records_sql("SELECT cm.id, cm.cohortid, cm.userid FROM {cohort_members} cm");
    $user_cohort_map = [];
    foreach ($cohort_members_raw as $cm) {
        $user_cohort_map[(int)$cm->userid] = (int)$cm->cohortid;
    }

    $stmt_user_select = $pdo->prepare("SELECT id FROM `User` WHERE `moodleUserId` = ? OR `username` = ? LIMIT 1");
    $stmt_user_update = $pdo->prepare("
        UPDATE `User`
           SET `moodleUserId` = ?,
               `username` = ?,
               `fullName` = ?,
               `passwordHash` = ?,
               `role` = ?,
               `className` = ?,
               `classGroupId` = ?,
               `isActive` = ?,
               `updatedAt` = NOW(3)
         WHERE `id` = ?
    ");
    $stmt_user_insert = $pdo->prepare("
        INSERT INTO `User` (`id`, `moodleUserId`, `username`, `fullName`, `passwordHash`, `role`, `className`, `classGroupId`, `roomCode`, `isActive`, `createdAt`, `updatedAt`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'R1', ?, NOW(3), NOW(3))
    ");

    $user_count = 0;
    foreach ($users as $u) {
        $uid = (int)$u->id;
        $uname = trim($u->username);
        $fullname = trim($u->firstname . ' ' . $u->lastname);
        if ($fullname === '') {
            $fullname = $uname;
        }

        // Tentukan Role
        $role = 'STUDENT';
        if (is_siteadmin($uid) || $uname === 'admin') {
            $role = 'ADMIN';
        }

        // Dapatkan Kelas
        $classGroupId = null;
        $className = null;
        if (isset($user_cohort_map[$uid]) && isset($cohort_map[$user_cohort_map[$uid]])) {
            $classGroupId = $cohort_map[$user_cohort_map[$uid]]['id'];
            $className = $cohort_map[$user_cohort_map[$uid]]['name'];
        }

        $isActive = ($u->suspended == 0) ? 1 : 0;
        $pwd = !empty($u->password) ? $u->password : password_hash('Aksaventra@2026', PASSWORD_DEFAULT);

        $stmt_user_select->execute([$uid, $uname]);
        $urow = $stmt_user_select->fetch();

        if ($urow) {
            $user_uuid = $urow['id'];
            $stmt_user_update->execute([$uid, $uname, $fullname, $pwd, $role, $className, $classGroupId, $isActive, $user_uuid]);
        } else {
            $user_uuid = generate_uuid_v4();
            $stmt_user_insert->execute([$user_uuid, $uid, $uname, $fullname, $pwd, $role, $className, $classGroupId, $isActive]);
        }
        $user_count++;
    }

    // 3. SINKRONISASI SESI UJIAN (CBT)
    $cbt_count = 0;
    $dbman = $DB->get_manager();
    if ($dbman->table_exists('cbt')) {
        $cbts = $DB->get_records_sql("
            SELECT c.id, c.name, c.intro, c.course, c.timelimit, c.maxstrikes, c.active_token, c.timeopen, c.timeclose,
                   co.fullname AS coursename
              FROM {cbt} c
              JOIN {course} co ON co.id = c.course
             ORDER BY c.id ASC
        ");

        $stmt_exam_select = $pdo->prepare("SELECT id FROM `Exam` WHERE `moodleCbtId` = ? LIMIT 1");
        $stmt_exam_update = $pdo->prepare("
            UPDATE `Exam`
               SET `title` = ?,
                   `courseName` = ?,
                   `durationSeconds` = ?,
                   `maxStrikes` = ?,
                   `activeToken` = ?,
                   `tokenExpiresAt` = ?,
                   `timeOpen` = ?,
                   `timeClose` = ?,
                   `questionsJson` = ?,
                   `answerKeysJson` = ?,
                   `updatedAt` = NOW(3)
             WHERE `id` = ?
        ");
        $stmt_exam_insert = $pdo->prepare("
            INSERT INTO `Exam` (`id`, `moodleCbtId`, `title`, `courseName`, `durationSeconds`, `maxStrikes`, `sebRequired`, `activeToken`, `tokenExpiresAt`, `questionsJson`, `answerKeysJson`, `timeOpen`, `timeClose`, `createdAt`, `updatedAt`)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))
        ");

        // Ambil bank soal Moodle untuk dijadikan soal ujian jika tersedia
        $questions_list = [];
        $answer_keys_map = [];

        if ($dbman->table_exists('question')) {
            $raw_questions = $DB->get_records_sql("
                SELECT q.id, q.name, q.questiontext, q.qtype, q.defaultmark
                  FROM {question} q
                 WHERE q.parent = 0
                 ORDER BY q.id ASC
                 LIMIT 100
            ");
            $slot = 1;
            foreach ($raw_questions as $rq) {
                $answers = $DB->get_records('question_answers', ['question' => $rq->id], 'id ASC');
                $opts = [];
                $labels = ['A', 'B', 'C', 'D', 'E'];
                $idx = 0;
                $correctKey = 'A';

                foreach ($answers as $ans) {
                    $key = $labels[$idx] ?? (string)($idx + 1);
                    $opts[] = [
                        'key' => $key,
                        'html' => trim((string)$ans->answer),
                    ];
                    if ((float)$ans->fraction > 0) {
                        $correctKey = $key;
                    }
                    $idx++;
                }

                $questions_list[] = [
                    'slot' => $slot,
                    'questionId' => (int)$rq->id,
                    'type' => (string)$rq->qtype,
                    'stemHtml' => (string)$rq->questiontext,
                    'options' => $opts,
                ];
                $answer_keys_map[(string)$rq->id] = $correctKey;
                $slot++;
            }
        }

        $now_dt = new DateTime();
        $default_open = (clone $now_dt)->format('Y-m-d H:i:s.000');
        $default_close = (clone $now_dt)->modify('+30 days')->format('Y-m-d H:i:s.000');
        $default_token_expiry = (clone $now_dt)->modify('+7 days')->format('Y-m-d H:i:s.000');

        foreach ($cbts as $c) {
            $cbt_id = (int)$c->id;
            $title = trim($c->name);
            $coursename = trim($c->coursename);
            $duration = (int)($c->timelimit ?: 7200);
            $maxstrikes = (int)($c->maxstrikes ?: 3);
            $token = !empty($c->active_token) ? trim($c->active_token) : '123456';

            $t_open = !empty($c->timeopen) ? (new DateTime("@{$c->timeopen}"))->format('Y-m-d H:i:s.000') : $default_open;
            $t_close = !empty($c->timeclose) ? (new DateTime("@{$c->timeclose}"))->format('Y-m-d H:i:s.000') : $default_close;

            $q_json = json_encode($questions_list);
            $ak_json = json_encode($answer_keys_map);

            $stmt_exam_select->execute([$cbt_id]);
            $erow = $stmt_exam_select->fetch();

            if ($erow) {
                $exam_uuid = $erow['id'];
                $stmt_exam_update->execute([$title, $coursename, $duration, $maxstrikes, $token, $default_token_expiry, $t_open, $t_close, $q_json, $ak_json, $exam_uuid]);
            } else {
                $exam_uuid = generate_uuid_v4();
                $stmt_exam_insert->execute([$exam_uuid, $cbt_id, $title, $coursename, $duration, $maxstrikes, $token, $default_token_expiry, $q_json, $ak_json, $t_open, $t_close]);
            }
            $cbt_count++;
        }
    }

    $summary = [
        'success' => true,
        'message' => 'Sinkronisasi master data Moodle ke Aksaventra CBT berhasil.',
        'counts' => [
            'classes' => $class_count,
            'students' => $user_count,
            'exams' => $cbt_count,
        ],
        'timestamp' => time(),
    ];

    cli_writeln(json_encode($summary, JSON_PRETTY_PRINT));
} catch (Throwable $e) {
    fwrite(STDERR, "Error Sinkronisasi: " . $e->getMessage() . "\n");
    exit(1);
}
