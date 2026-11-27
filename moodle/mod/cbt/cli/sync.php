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
 * directly into the MariaDB / MySQL or PostgreSQL aksaventra_cbt database used by Next.js.
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

function clean_moodle_question_html(string $raw_html, string $filearea = 'questiontext', int $itemid = 0): string {
    global $DB;
    $html = trim($raw_html);
    if ($html === '') {
        return '';
    }

    // 1. Resolve @@PLUGINFILE@@/<filename> into inline data:<mimetype>;base64,... from Moodle file storage
    if (str_contains($html, '@@PLUGINFILE@@/') && $itemid > 0 && function_exists('get_file_storage')) {
        try {
            $fs = get_file_storage();
            $file_records = $DB->get_records_select(
                'files',
                "component = 'question' AND filearea = ? AND itemid = ? AND filename <> '.'",
                [$filearea, $itemid],
                'id ASC',
                'id, filename, mimetype, filesize'
            );
            foreach ($file_records as $frec) {
                $fname = (string)$frec->filename;
                if ($fname === '' || (int)$frec->filesize > 2097152) {
                    continue;
                }
                $stored = $fs->get_file_by_id((int)$frec->id);
                if ($stored) {
                    $content = $stored->get_content();
                    if ($content !== false && $content !== '') {
                        $mime = !empty($frec->mimetype) ? (string)$frec->mimetype : 'image/jpeg';
                        $data_uri = 'data:' . $mime . ';base64,' . base64_encode($content);
                        $html = str_replace(
                            ['@@PLUGINFILE@@/' . $fname, '@@PLUGINFILE@@/' . rawurlencode($fname)],
                            $data_uri,
                            $html
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            // Ignore file storage resolution errors
        }
    }

    // 2. Convert <br>, </p>, </div> to newlines and strip noisy wrapper tags (<div>, <span>, <p>, <font>)
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/(?:p|div|li|h[1-6])>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<!--[\s\S]*?-->/', '', $html) ?? $html;
    $html = preg_replace('/<\/?(?:div|span|p|font|o:p|center|section|article)(?:\s[^>]*)?>/i', '', $html) ?? $html;

    // 3. Clean obsolete attributes on <img> tags (border="0", align="bottom")
    $html = preg_replace('/\s+(?:border|align|hspace|vspace)="[^"]*"/i', '', $html) ?? $html;

    // 4. Decode HTML entities (&nbsp;, &amp;, &deg;, etc.)
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace("\xC2\xA0", ' ', $html);

    // 5. Collapse excessive whitespace per line
    $lines = array_map(fn($l) => trim((string)(preg_replace('/[ \t]+/', ' ', $l) ?? $l)), explode("\n", $html));
    $html = trim((string)(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? implode("\n", $lines)));

    return $html;
}

try {
    $cbt_driver = getenv('CBT_DB_DRIVER') ?: (!empty($CFG->dbtype) ? $CFG->dbtype : 'mariadb');
    $is_pgsql = in_array(strtolower(trim((string)$cbt_driver)), ['pgsql', 'postgres', 'postgresql'], true);

    $dbhost = getenv('CBT_DB_HOST') ?: (!empty($CFG->dbhost) ? $CFG->dbhost : '127.0.0.1');
    $default_port = $is_pgsql ? 5432 : 3306;
    $dbport = getenv('CBT_DB_PORT')
        ? (int)getenv('CBT_DB_PORT')
        : (!empty($CFG->dboptions['dbport']) ? (int)$CFG->dboptions['dbport'] : $default_port);
    $dbuser = getenv('CBT_DB_USER') ?: (!empty($CFG->dbuser) ? $CFG->dbuser : ($is_pgsql ? 'postgres' : 'root'));
    $dbpass = getenv('CBT_DB_PASS') !== false ? getenv('CBT_DB_PASS') : (isset($CFG->dbpass) ? $CFG->dbpass : '');
    $cbt_dbname = getenv('CBT_DB_NAME') ?: 'aksaventra_cbt';

    $pdo_opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if ($is_pgsql) {
        $maint_db = !empty($CFG->dbname) ? $CFG->dbname : 'postgres';
        $pdo_server = new PDO("pgsql:host={$dbhost};port={$dbport};dbname={$maint_db}", $dbuser, $dbpass, $pdo_opts);

        $stmt_check_db = $pdo_server->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
        $stmt_check_db->execute([$cbt_dbname]);
        if (!$stmt_check_db->fetch()) {
            $safe_dbname = str_replace('"', '""', $cbt_dbname);
            $pdo_server->exec("CREATE DATABASE \"{$safe_dbname}\" ENCODING 'UTF8'");
        }

        $pdo = new PDO("pgsql:host={$dbhost};port={$dbport};dbname={$cbt_dbname}", $dbuser, $dbpass, $pdo_opts);

        // Ensure PostgreSQL enums and Prisma tables exist if not yet migrated
        $pdo->exec("
            DO \$\$ BEGIN
                CREATE TYPE \"UserRole\" AS ENUM ('ADMIN', 'TEACHER', 'PROCTOR', 'STUDENT');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END \$\$;
        ");
        $pdo->exec("
            DO \$\$ BEGIN
                CREATE TYPE \"AttemptStatus\" AS ENUM ('IN_PROGRESS', 'LOCKED', 'SUBMITTED', 'ABANDONED');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END \$\$;
        ");
        $pdo->exec("
            DO \$\$ BEGIN
                CREATE TYPE \"ViolationEventType\" AS ENUM ('TAB_BLUR', 'FULLSCREEN_EXIT', 'DEVTOOLS_OPEN', 'PROCTOR_UNLOCK', 'PROCTOR_RESET_DEVICE', 'TIME_EXTEND', 'FORCE_SUBMIT');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END \$\$;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS \"Subject\" (
                \"id\" VARCHAR(191) NOT NULL PRIMARY KEY,
                \"moodleCourseId\" INTEGER NULL UNIQUE,
                \"name\" VARCHAR(191) NOT NULL UNIQUE,
                \"shortName\" VARCHAR(191) NULL,
                \"description\" VARCHAR(191) NULL,
                \"isVisible\" BOOLEAN NOT NULL DEFAULT TRUE,
                \"createdAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"updatedAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            );
        ");
        $pdo->exec("ALTER TABLE \"Subject\" ADD COLUMN IF NOT EXISTS \"isVisible\" BOOLEAN NOT NULL DEFAULT TRUE;");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS \"Question\" (
                \"id\" VARCHAR(191) NOT NULL PRIMARY KEY,
                \"moodleQuestionId\" INTEGER NULL UNIQUE,
                \"code\" VARCHAR(191) NOT NULL UNIQUE,
                \"subject\" VARCHAR(191) NOT NULL,
                \"gradeLevel\" VARCHAR(191) NOT NULL,
                \"type\" VARCHAR(191) NOT NULL,
                \"stem\" TEXT NOT NULL,
                \"imageUrl\" VARCHAR(191) NULL,
                \"points\" DOUBLE PRECISION NOT NULL DEFAULT 1,
                \"tolerance\" DOUBLE PRECISION NULL,
                \"optionsJson\" JSONB NOT NULL,
                \"explanation\" TEXT NULL,
                \"createdAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"updatedAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            );
            CREATE INDEX IF NOT EXISTS \"Question_subject_gradeLevel_idx\" ON \"Question\" (\"subject\", \"gradeLevel\");
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS \"ClassGroup\" (
                \"id\" VARCHAR(191) NOT NULL PRIMARY KEY,
                \"moodleId\" INTEGER NULL UNIQUE,
                \"name\" VARCHAR(191) NOT NULL UNIQUE,
                \"code\" VARCHAR(191) NULL,
                \"description\" TEXT NULL,
                \"createdAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"updatedAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS \"User\" (
                \"id\" VARCHAR(191) NOT NULL PRIMARY KEY,
                \"moodleUserId\" INTEGER NULL UNIQUE,
                \"username\" VARCHAR(191) NOT NULL UNIQUE,
                \"fullName\" VARCHAR(191) NOT NULL,
                \"passwordHash\" VARCHAR(191) NOT NULL,
                \"role\" \"UserRole\" NOT NULL DEFAULT 'STUDENT',
                \"className\" VARCHAR(191) NULL,
                \"classGroupId\" VARCHAR(191) NULL,
                \"roomCode\" VARCHAR(191) NULL,
                \"isActive\" BOOLEAN NOT NULL DEFAULT TRUE,
                \"createdAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"updatedAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                CONSTRAINT \"User_classGroupId_fkey\" FOREIGN KEY (\"classGroupId\") REFERENCES \"ClassGroup\" (\"id\") ON DELETE SET NULL ON UPDATE CASCADE
            );
            CREATE INDEX IF NOT EXISTS \"User_role_roomCode_idx\" ON \"User\" (\"role\", \"roomCode\");
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS \"Exam\" (
                \"id\" VARCHAR(191) NOT NULL PRIMARY KEY,
                \"moodleCbtId\" INTEGER NOT NULL UNIQUE,
                \"title\" VARCHAR(191) NOT NULL,
                \"courseName\" VARCHAR(191) NOT NULL,
                \"durationSeconds\" INTEGER NOT NULL DEFAULT 7200,
                \"maxStrikes\" INTEGER NOT NULL DEFAULT 3,
                \"sebRequired\" BOOLEAN NOT NULL DEFAULT FALSE,
                \"sebConfigKey\" VARCHAR(191) NULL,
                \"activeToken\" VARCHAR(10) NOT NULL,
                \"tokenExpiresAt\" TIMESTAMP(3) NOT NULL,
                \"snapshotHash\" VARCHAR(191) NULL,
                \"questionsJson\" JSONB NOT NULL,
                \"answerKeysJson\" JSONB NOT NULL,
                \"timeOpen\" TIMESTAMP(3) NOT NULL,
                \"timeClose\" TIMESTAMP(3) NOT NULL,
                \"createdAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"updatedAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS \"Attempt\" (
                \"id\" VARCHAR(191) NOT NULL PRIMARY KEY,
                \"moodleAttemptId\" INTEGER NULL UNIQUE,
                \"examId\" VARCHAR(191) NOT NULL,
                \"userId\" VARCHAR(191) NOT NULL,
                \"status\" \"AttemptStatus\" NOT NULL DEFAULT 'IN_PROGRESS',
                \"startedAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"finishedAt\" TIMESTAMP(3) NULL,
                \"extraTimeSeconds\" INTEGER NOT NULL DEFAULT 0,
                \"strikeCount\" INTEGER NOT NULL DEFAULT 0,
                \"deviceHash\" VARCHAR(128) NOT NULL,
                \"ipAddress\" VARCHAR(45) NOT NULL,
                \"questionOrder\" JSONB NOT NULL,
                \"answersJson\" JSONB NOT NULL,
                \"syncVersion\" INTEGER NOT NULL DEFAULT 0,
                \"finalScore\" DOUBLE PRECISION NULL,
                \"syncedToMoodle\" BOOLEAN NOT NULL DEFAULT FALSE,
                \"lastHeartbeatAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"createdAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                \"updatedAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                CONSTRAINT \"Attempt_examId_userId_key\" UNIQUE (\"examId\", \"userId\"),
                CONSTRAINT \"Attempt_examId_fkey\" FOREIGN KEY (\"examId\") REFERENCES \"Exam\" (\"id\") ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT \"Attempt_userId_fkey\" FOREIGN KEY (\"userId\") REFERENCES \"User\" (\"id\") ON DELETE CASCADE ON UPDATE CASCADE
            );
            CREATE INDEX IF NOT EXISTS \"Attempt_examId_status_idx\" ON \"Attempt\" (\"examId\", \"status\");
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS \"ViolationLog\" (
                \"id\" VARCHAR(191) NOT NULL PRIMARY KEY,
                \"attemptId\" VARCHAR(191) NOT NULL,
                \"eventType\" \"ViolationEventType\" NOT NULL,
                \"details\" JSONB NULL,
                \"createdAt\" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                CONSTRAINT \"ViolationLog_attemptId_fkey\" FOREIGN KEY (\"attemptId\") REFERENCES \"Attempt\" (\"id\") ON DELETE CASCADE ON UPDATE CASCADE
            );
            CREATE INDEX IF NOT EXISTS \"ViolationLog_attemptId_createdAt_idx\" ON \"ViolationLog\" (\"attemptId\", \"createdAt\");
        ");
    } else {
        $dsn_server = "mysql:host={$dbhost};port={$dbport};charset=utf8mb4";
        $pdo_server = new PDO($dsn_server, $dbuser, $dbpass, $pdo_opts);

        // Ensure database aksaventra_cbt exists
        $safe_dbname = str_replace('`', '``', $cbt_dbname);
        $pdo_server->exec("CREATE DATABASE IF NOT EXISTS `{$safe_dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dsn_cbt = "mysql:host={$dbhost};port={$dbport};dbname={$cbt_dbname};charset=utf8mb4";
        $pdo = new PDO($dsn_cbt, $dbuser, $dbpass, $pdo_opts);

        // Ensure core Prisma tables exist if not yet migrated
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `Subject` (
                `id` VARCHAR(191) NOT NULL PRIMARY KEY,
                `moodleCourseId` INT NULL UNIQUE,
                `name` VARCHAR(191) NOT NULL UNIQUE,
                `shortName` VARCHAR(191) NULL,
                `description` VARCHAR(191) NULL,
                `isVisible` TINYINT(1) NOT NULL DEFAULT 1,
                `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $stmt_has_is_visible = $pdo->query("SHOW COLUMNS FROM `Subject` LIKE 'isVisible'");
        if (!$stmt_has_is_visible->fetch()) {
            $pdo->exec("ALTER TABLE `Subject` ADD COLUMN `isVisible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `description`");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `Question` (
                `id` VARCHAR(191) NOT NULL PRIMARY KEY,
                `moodleQuestionId` INT NULL UNIQUE,
                `code` VARCHAR(191) NOT NULL UNIQUE,
                `subject` VARCHAR(191) NOT NULL,
                `gradeLevel` VARCHAR(191) NOT NULL,
                `type` VARCHAR(191) NOT NULL,
                `stem` TEXT NOT NULL,
                `imageUrl` VARCHAR(191) NULL,
                `points` DOUBLE NOT NULL DEFAULT 1,
                `tolerance` DOUBLE NULL,
                `optionsJson` JSON NOT NULL,
                `explanation` TEXT NULL,
                `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                INDEX `Question_subject_gradeLevel_idx` (`subject`, `gradeLevel`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

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
    }

    // Cross-database SQL prepare helper (converts MySQL backticks and NOW(3) for PostgreSQL)
    $prepare_sql = function (string $sql) use ($pdo, $is_pgsql): PDOStatement {
        if ($is_pgsql) {
            $sql = str_replace(['`', 'NOW(3)'], ['"', 'CURRENT_TIMESTAMP(3)'], $sql);
        }
        return $pdo->prepare($sql);
    };

    // 0. SINKRONISASI MATA PELAJARAN (COURSE -> Subject, termasuk course tersembunyi untuk sinkronisasi dua arah)
    $courses = $DB->get_records_select('course', 'id > 1', null, 'fullname ASC', 'id, fullname, shortname, summary, visible');
    $course_count = 0;
    $stmt_sub_select = $prepare_sql("SELECT `id` FROM `Subject` WHERE `moodleCourseId` = ? OR `name` = ? LIMIT 1");
    $stmt_sub_name_check = $prepare_sql("SELECT `id` FROM `Subject` WHERE `name` = ? AND `id` <> ? LIMIT 1");
    $stmt_sub_update = $prepare_sql("UPDATE `Subject` SET `moodleCourseId` = ?, `name` = ?, `shortName` = ?, `description` = ?, `isVisible` = ?, `updatedAt` = NOW(3) WHERE `id` = ?");
    $stmt_sub_insert = $prepare_sql("INSERT INTO `Subject` (`id`, `moodleCourseId`, `name`, `shortName`, `description`, `isVisible`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, ?, ?, ?, NOW(3), NOW(3))");

    foreach ($courses as $course) {
        $cid = (int)$course->id;
        $cname = mb_substr(trim((string)$course->fullname), 0, 190);
        if ($cname === '') {
            continue;
        }
        $cshort = mb_substr(trim((string)($course->shortname ?? '')), 0, 190) ?: null;
        $cdesc = mb_substr(trim(strip_tags((string)($course->summary ?? ''))), 0, 190) ?: null;
        $cvis = ((int)($course->visible ?? 1) === 1) ? 1 : 0;

        $stmt_sub_select->execute([$cid, $cname]);
        $srow = $stmt_sub_select->fetch();
        if ($srow) {
            $stmt_sub_name_check->execute([$cname, $srow['id']]);
            if ($stmt_sub_name_check->fetch()) {
                $suffix = $cshort ? " ({$cshort})" : " (#{$cid})";
                $cname = mb_substr($cname, 0, 190 - mb_strlen($suffix)) . $suffix;
            }
            $stmt_sub_update->execute([$cid, $cname, $cshort, $cdesc, $cvis, $srow['id']]);
        } else {
            $stmt_sub_insert->execute([generate_uuid_v4(), $cid, $cname, $cshort, $cdesc, $cvis]);
        }
        $course_count++;
    }

    // 1. SINKRONISASI KELAS (COHORT)
    $cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber, description');
    $cohort_map = []; // moodle cohort id => ['id' => ClassGroup UUID, 'name' => string]
    $class_name_map = []; // lower(className) => ['id' => ClassGroup UUID, 'name' => string]
    $class_count = 0;

    $stmt_cg_select = $prepare_sql("SELECT `id` FROM `ClassGroup` WHERE `moodleId` = ? OR `name` = ? LIMIT 1");
    $stmt_cg_select_by_name = $prepare_sql("SELECT `id`, `name` FROM `ClassGroup` WHERE `name` = ? LIMIT 1");
    $stmt_cg_update = $prepare_sql("UPDATE `ClassGroup` SET `moodleId` = ?, `name` = ?, `code` = ?, `description` = ?, `updatedAt` = NOW(3) WHERE `id` = ?");
    $stmt_cg_insert = $prepare_sql("INSERT INTO `ClassGroup` (`id`, `moodleId`, `name`, `code`, `description`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3))");
    $stmt_cg_insert_auto = $prepare_sql("INSERT INTO `ClassGroup` (`id`, `moodleId`, `name`, `code`, `description`, `createdAt`, `updatedAt`) VALUES (?, NULL, ?, ?, ?, NOW(3), NOW(3))");

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
        $entry = [
            'id' => $cg_id,
            'name' => $name,
        ];
        $cohort_map[(int)$c->id] = $entry;
        $class_name_map[mb_strtolower($name)] = $entry;
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

    $role_map = [];
    try {
        $role_rows = $DB->get_records_sql("
            SELECT ra.id, ra.userid, r.shortname, r.archetype, ctx.contextlevel
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
              JOIN {context} ctx ON ctx.id = ra.contextid
        ");
        $role_flags = [];
        foreach ($role_rows as $rr) {
            $ruid = (int)$rr->userid;
            $rshort = strtolower(trim((string)$rr->shortname));
            $rarch = strtolower(trim((string)($rr->archetype ?? '')));
            $ctxlevel = (int)$rr->contextlevel;
            if (!isset($role_flags[$ruid])) {
                $role_flags[$ruid] = [
                    'teacher_role' => false,
                    'system_manager' => false,
                    'other_manager' => false,
                    'proctor' => false,
                ];
            }
            // Moodle standard roles -> Aksaventra CBT roles:
            // - Teacher (editingteacher) & Course creator (coursecreator) -> TEACHER
            // - Non-editing teacher (teacher) -> PROCTOR (Pengawas)
            // - Manager (manager) -> ADMIN (system context) / TEACHER (course or category context)
            // - Student (student) -> STUDENT (default)
            if (in_array($rshort, ['coursecreator', 'editingteacher'], true)
                || in_array($rarch, ['coursecreator', 'editingteacher'], true)) {
                $role_flags[$ruid]['teacher_role'] = true;
            } else if ($rshort === 'manager' || $rarch === 'manager') {
                if ($ctxlevel === CONTEXT_SYSTEM) {
                    $role_flags[$ruid]['system_manager'] = true;
                } else {
                    $role_flags[$ruid]['other_manager'] = true;
                }
            } else if ($rshort === 'teacher' || $rarch === 'teacher') {
                $role_flags[$ruid]['proctor'] = true;
            }
        }
        foreach ($role_flags as $ruid => $f) {
            if ($f['teacher_role']) {
                $role_map[$ruid] = 'TEACHER';
            } else if ($f['system_manager']) {
                $role_map[$ruid] = 'ADMIN';
            } else if ($f['other_manager']) {
                $role_map[$ruid] = 'TEACHER';
            } else if ($f['proctor']) {
                $role_map[$ruid] = 'PROCTOR';
            }
        }
    } catch (Throwable $e) {
        $role_map = [];
    }

    $stmt_user_select = $prepare_sql("SELECT `id`, `passwordHash` FROM `User` WHERE `moodleUserId` = ? OR `username` = ? LIMIT 1");
    $stmt_user_update = $prepare_sql("
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
    $stmt_user_insert = $prepare_sql("
        INSERT INTO `User` (`id`, `moodleUserId`, `username`, `fullName`, `passwordHash`, `role`, `className`, `classGroupId`, `roomCode`, `isActive`, `createdAt`, `updatedAt`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))
    ");

    $hash_algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $user_count = 0;
    foreach ($users as $u) {
        $uid = (int)$u->id;
        $uname = trim($u->username);
        $firstname = trim((string)($u->firstname ?? ''));
        $lastname = trim((string)($u->lastname ?? ''));

        // Tentukan Role terlebih dahulu agar pemetaan nama & kelas sesuai tipe akun
        $role = 'STUDENT';
        if (is_siteadmin($uid) || strtolower($uname) === 'admin') {
            $role = 'ADMIN';
        } else if (isset($role_map[$uid])) {
            $role = $role_map[$uid];
        }

        // Pemetaan Nama & Kelas:
        // - Untuk STUDENT: firstname -> fullName, lastname -> kelas (ClassGroup / className), fallback ke cohort jika lastname kosong/'-'
        // - Untuk ADMIN/TEACHER/PROCTOR: gabungkan firstname + lastname sebagai fullName
        $classGroupId = null;
        $className = null;

        if ($role === 'STUDENT') {
            $fullname = $firstname !== '' ? $firstname : ($lastname !== '' && $lastname !== '-' ? $lastname : $uname);

            if ($lastname !== '' && $lastname !== '-') {
                $classKey = mb_strtolower($lastname);
                if (!isset($class_name_map[$classKey])) {
                    $stmt_cg_select_by_name->execute([$lastname]);
                    $cg_existing = $stmt_cg_select_by_name->fetch();
                    if ($cg_existing) {
                        $class_name_map[$classKey] = [
                            'id' => $cg_existing['id'],
                            'name' => $cg_existing['name'],
                        ];
                    } else {
                        $new_cg_id = generate_uuid_v4();
                        $stmt_cg_insert_auto->execute([$new_cg_id, $lastname, $lastname, "Kelas {$lastname}"]);
                        $class_name_map[$classKey] = [
                            'id' => $new_cg_id,
                            'name' => $lastname,
                        ];
                        $class_count++;
                    }
                }
                $classGroupId = $class_name_map[$classKey]['id'];
                $className = $class_name_map[$classKey]['name'];
            } else if (isset($user_cohort_map[$uid]) && isset($cohort_map[$user_cohort_map[$uid]])) {
                $classGroupId = $cohort_map[$user_cohort_map[$uid]]['id'];
                $className = $cohort_map[$user_cohort_map[$uid]]['name'];
            }
        } else {
            $fullname = trim($firstname . ($lastname !== '' && $lastname !== '-' ? ' ' . $lastname : ''));
            if ($fullname === '') {
                $fullname = $uname;
            }
        }

        $roomCode = ($role === 'STUDENT') ? 'LAB-01' : null;
        $isActive = ($u->suspended == 0) ? 1 : 0;
        $pwd = !empty($u->password) ? $u->password : password_hash('Aksaventra@2026', $hash_algo);

        $stmt_user_select->execute([$uid, $uname]);
        $urow = $stmt_user_select->fetch();

        if ($urow) {
            $user_uuid = $urow['id'];
            $existing_hash = (string)($urow['passwordHash'] ?? '');
            if (str_starts_with($existing_hash, '$argon2') && !str_starts_with((string)$pwd, '$argon2')) {
                $pwd = $existing_hash;
            }
            $stmt_user_update->execute([$uid, $uname, $fullname, $pwd, $role, $className, $classGroupId, $isActive, $user_uuid]);
        } else {
            $user_uuid = generate_uuid_v4();
            $stmt_user_insert->execute([$user_uuid, $uid, $uname, $fullname, $pwd, $role, $className, $classGroupId, $roomCode, $isActive]);
        }
        $user_count++;
    }

    // 2.5. SINKRONISASI BANK SOAL MOODLE 5 (mdl_question + mdl_question_versions -> Question)
    $dbman = $DB->get_manager();
    $question_count = 0;
    $questions_list = [];
    $answer_keys_map = [];

    if ($dbman->table_exists('question')) {
        $hasVersioning = $dbman->table_exists('question_versions')
            && $dbman->table_exists('question_bank_entries');
        $hasAnswersTable = $dbman->table_exists('question_answers');
        $hasNumericalTable = $dbman->table_exists('question_numerical');
        $hasMcOptionsTable = $dbman->table_exists('qtype_multichoice_options');

        // Build map of question category id -> course fullname for Subject labeling
        $cat_subject_map = [];
        if ($dbman->table_exists('question_categories')) {
            try {
                $cat_rows = $DB->get_records_sql("
                    SELECT qc.id, ctx.contextlevel, ctx.instanceid
                      FROM {question_categories} qc
                      JOIN {context} ctx ON ctx.id = qc.contextid
                ");
                $course_names = [];
                foreach ($courses as $co) {
                    $course_names[(int)$co->id] = trim((string)$co->fullname);
                }
                $cm_course_map = [];
                if ($dbman->table_exists('course_modules')) {
                    $cms = $DB->get_records('course_modules', null, '', 'id, course');
                    foreach ($cms as $cm) {
                        $cm_course_map[(int)$cm->id] = (int)$cm->course;
                    }
                }
                foreach ($cat_rows as $cr) {
                    $cid = 0;
                    if ((int)$cr->contextlevel === CONTEXT_COURSE) {
                        $cid = (int)$cr->instanceid;
                    } else if ((int)$cr->contextlevel === CONTEXT_MODULE && isset($cm_course_map[(int)$cr->instanceid])) {
                        $cid = $cm_course_map[(int)$cr->instanceid];
                    }
                    if ($cid > 0 && isset($course_names[$cid]) && $course_names[$cid] !== '') {
                        $cat_subject_map[(int)$cr->id] = $course_names[$cid];
                    }
                }
            } catch (Throwable $e) {
                $cat_subject_map = [];
            }
        }

        if ($hasVersioning) {
            $raw_questions = $DB->get_records_sql("
                SELECT q.id, q.qtype, q.name, q.questiontext, q.questiontextformat,
                       q.defaultmark, q.generalfeedback, qbe.idnumber AS code,
                       qbe.questioncategoryid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE q.parent = 0
                   AND qv.status = 'ready'
                   AND qv.version = (
                       SELECT MAX(v2.version)
                         FROM {question_versions} v2
                        WHERE v2.questionbankentryid = qbe.id
                   )
                 ORDER BY q.id DESC
            ", [], 0, 500);
        } else {
            $raw_questions = $DB->get_records_sql("
                SELECT q.id, q.qtype, q.name, q.questiontext, q.questiontextformat,
                       q.defaultmark, q.generalfeedback
                  FROM {question} q
                 WHERE q.parent = 0
                 ORDER BY q.id DESC
            ", [], 0, 500);
        }

        $stmt_q_select = $prepare_sql("SELECT `id` FROM `Question` WHERE `moodleQuestionId` = ? OR `code` = ? LIMIT 1");
        $stmt_q_code_check = $prepare_sql("SELECT `id` FROM `Question` WHERE `code` = ? AND `id` <> ? LIMIT 1");
        $stmt_q_update = $prepare_sql("
            UPDATE `Question`
               SET `moodleQuestionId` = ?,
                   `code` = ?,
                   `subject` = ?,
                   `type` = ?,
                   `stem` = ?,
                   `points` = ?,
                   `tolerance` = ?,
                   `optionsJson` = ?,
                   `explanation` = ?,
                   `updatedAt` = NOW(3)
             WHERE `id` = ?
        ");
        $stmt_q_insert = $prepare_sql("
            INSERT INTO `Question` (`id`, `moodleQuestionId`, `code`, `subject`, `gradeLevel`, `type`, `stem`, `points`, `tolerance`, `optionsJson`, `explanation`, `createdAt`, `updatedAt`)
            VALUES (?, ?, ?, ?, 'UMUM', ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))
        ");

        $labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $slot = 1;

        foreach ($raw_questions as $rq) {
            $qid = (int)$rq->id;
            $points = (float)(!empty($rq->defaultmark) ? $rq->defaultmark : 1.0);
            $answers = $hasAnswersTable
                ? $DB->get_records('question_answers', ['question' => $qid], 'id ASC')
                : [];

            $opts_for_db = [];
            $opts_for_exam = [];
            $idx = 0;
            $positiveCount = 0;
            $correctKeys = [];
            $primaryCorrectKey = 'A';
            $primaryAnswerId = 0;
            $primaryAnswerText = '';
            $bestFraction = -1.0;

            foreach ($answers as $ans) {
                $key = $labels[$idx] ?? (string)($idx + 1);
                $frac = (float)$ans->fraction;
                $isCorrect = $frac > 0.0;
                $clean_ans = clean_moodle_question_html((string)$ans->answer, 'answer', (int)$ans->id);
                if ($isCorrect) {
                    $positiveCount++;
                    $correctKeys[] = $key;
                }
                if ($frac > $bestFraction) {
                    $bestFraction = $frac;
                    $primaryCorrectKey = $key;
                    $primaryAnswerId = (int)$ans->id;
                    $primaryAnswerText = trim(strip_tags($clean_ans));
                }

                $opts_for_db[] = [
                    'id' => 'moodle-ans-' . $ans->id,
                    'label' => $key,
                    'text' => $clean_ans,
                    'fraction' => $frac,
                    'isCorrect' => $isCorrect,
                ];
                $opts_for_exam[] = [
                    'key' => $key,
                    'html' => $clean_ans,
                ];
                $idx++;
            }

            // Detect MCMA vs single choice
            $effectiveType = (string)$rq->qtype;
            if ($effectiveType === 'multichoice') {
                $isSingle = true;
                if ($hasMcOptionsTable) {
                    try {
                        $mcopt = $DB->get_record('qtype_multichoice_options', ['questionid' => $qid]);
                        if ($mcopt && isset($mcopt->single)) {
                            $isSingle = ((int)$mcopt->single === 1);
                        } else if ($positiveCount > 1) {
                            $isSingle = false;
                        }
                    } catch (Throwable $e) {
                        $isSingle = ($positiveCount <= 1);
                    }
                } else if ($positiveCount > 1) {
                    $isSingle = false;
                }
                if (!$isSingle) {
                    $effectiveType = 'mcma';
                }
            }

            // Fetch tolerance for numerical questions from mdl_question_numerical
            $tolerance = null;
            if ($rq->qtype === 'numerical' && $hasNumericalTable) {
                try {
                    $numrec = null;
                    if ($primaryAnswerId > 0) {
                        $numrec = $DB->get_record('question_numerical', ['answer' => $primaryAnswerId]);
                    }
                    if (!$numrec) {
                        $numrec = $DB->get_record('question_numerical', ['question' => $qid], '*', IGNORE_MULTIPLE);
                    }
                    $tolerance = ($numrec && isset($numrec->tolerance) && is_numeric($numrec->tolerance))
                        ? (float)$numrec->tolerance
                        : 0.0;
                } catch (Throwable $e) {
                    $tolerance = 0.0;
                }
            }

            $qcode = (isset($rq->code) && trim((string)$rq->code) !== '')
                ? mb_substr(trim((string)$rq->code), 0, 180)
                : ('MDL-Q-' . $qid);
            $qsubject = (isset($rq->questioncategoryid) && isset($cat_subject_map[(int)$rq->questioncategoryid]))
                ? $cat_subject_map[(int)$rq->questioncategoryid]
                : 'Umum';
            $clean_stem = clean_moodle_question_html((string)$rq->questiontext, 'questiontext', $qid);
            $qexplanation = clean_moodle_question_html((string)($rq->generalfeedback ?? ''), 'generalfeedback', $qid) ?: null;
            $optionsJson = json_encode($opts_for_db, JSON_UNESCAPED_UNICODE);

            $stmt_q_select->execute([$qid, $qcode]);
            $qrow = $stmt_q_select->fetch();

            if ($qrow) {
                $stmt_q_code_check->execute([$qcode, $qrow['id']]);
                if ($stmt_q_code_check->fetch()) {
                    $qcode = 'MDL-Q-' . $qid;
                }
                $stmt_q_update->execute([
                    $qid,
                    $qcode,
                    $qsubject,
                    $effectiveType,
                    $clean_stem,
                    $points,
                    $tolerance,
                    $optionsJson,
                    $qexplanation,
                    $qrow['id'],
                ]);
            } else {
                $stmt_q_insert->execute([
                    generate_uuid_v4(),
                    $qid,
                    $qcode,
                    $qsubject,
                    $effectiveType,
                    $clean_stem,
                    $points,
                    $tolerance,
                    $optionsJson,
                    $qexplanation,
                ]);
            }
            $question_count++;

            // Also prepare snapshot payload for Exam sync (cap at 100 items per exam)
            if ($slot <= 100) {
                $questions_list[] = [
                    'slot' => $slot,
                    'questionId' => $qid,
                    'type' => $effectiveType,
                    'points' => $points,
                    'tolerance' => $tolerance,
                    'stemHtml' => $clean_stem,
                    'options' => $opts_for_exam,
                ];
                if ($effectiveType === 'mcma') {
                    $answer_keys_map[(string)$qid] = !empty($correctKeys) ? $correctKeys : [$primaryCorrectKey];
                } else if ($effectiveType === 'numerical') {
                    $answer_keys_map[(string)$qid] = [
                        'answer' => is_numeric($primaryAnswerText) ? (float)$primaryAnswerText : 0.0,
                        'tolerance' => $tolerance ?? 0.0,
                    ];
                } else {
                    $answer_keys_map[(string)$qid] = $primaryCorrectKey;
                }
                $slot++;
            }
        }
    }

    // 3. SINKRONISASI SESI UJIAN (CBT)
    $cbt_count = 0;
    if ($dbman->table_exists('cbt')) {
        $cbts = $DB->get_records_sql("
            SELECT c.id, c.name, c.intro, c.course, c.timelimit, c.maxstrikes, c.active_token, c.timeopen, c.timeclose,
                   co.fullname AS coursename
              FROM {cbt} c
              JOIN {course} co ON co.id = c.course
             ORDER BY c.id ASC
        ");

        $stmt_exam_select = $prepare_sql("SELECT `id` FROM `Exam` WHERE `moodleCbtId` = ? LIMIT 1");
        $stmt_exam_update = $prepare_sql("
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
        $stmt_exam_insert = $prepare_sql("
            INSERT INTO `Exam` (`id`, `moodleCbtId`, `title`, `courseName`, `durationSeconds`, `maxStrikes`, `sebRequired`, `activeToken`, `tokenExpiresAt`, `questionsJson`, `answerKeysJson`, `timeOpen`, `timeClose`, `createdAt`, `updatedAt`)
            VALUES (?, ?, ?, ?, ?, ?, FALSE, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))
        ");

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
            'courses' => $course_count,
            'classes' => $class_count,
            'students' => $user_count,
            'questions' => $question_count,
            'exams' => $cbt_count,
        ],
        'timestamp' => time(),
    ];

    cli_writeln(json_encode($summary, JSON_PRETTY_PRINT));
} catch (Throwable $e) {
    fwrite(STDERR, "Error Sinkronisasi: " . $e->getMessage() . "\n");
    exit(1);
}
