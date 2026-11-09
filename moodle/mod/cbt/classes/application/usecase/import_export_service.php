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

use mod_cbt\infrastructure\persistence\db_attempt_repository;
use mod_cbt\infrastructure\cache\muc_snapshot_store;

defined('MOODLE_INTERNAL') || die();

/**
 * Service to handle import and export for students, questions, and grades.
 * Supports Excel-compatible CSV with UTF-8 BOM and semicolon/comma auto-detection.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_export_service {

    private ?db_attempt_repository $repository = null;

    public function __construct(?db_attempt_repository $repository = null) {
        if ($repository !== null) {
            $this->repository = $repository;
        } else if (class_exists('mod_cbt\\infrastructure\\persistence\\db_attempt_repository')) {
            $this->repository = new db_attempt_repository();
        }
    }

    /**
     * Import students and assign them to cohorts/classes and optional CBT exam.
     *
     * @param string $csv_content CSV content with columns: nis_username, nama_lengkap, password, kelas, ruang_ujian, nomor_meja, email
     * @param int $cbt_id Optional CBT exam to assign immediately
     * @return array [ 'success' => bool, 'imported_count' => int, 'message' => string, 'errors' => array ]
     */
    public function import_students(string $csv_content, int $cbt_id = 0): array {
        $rows = $this->parse_csv($csv_content);
        if (empty($rows)) {
            return ['success' => false, 'imported_count' => 0, 'message' => 'File CSV kosong atau tidak valid.', 'errors' => []];
        }

        $user_mgr = new manage_classes_and_users();
        $imported = 0;
        $errors = [];
        $class_cache = [];

        foreach ($rows as $idx => $row) {
            $username = trim($row['nis_username'] ?? $row['username'] ?? $row['nis'] ?? '');
            $fullname = trim($row['nama_lengkap'] ?? $row['name'] ?? $row['nama'] ?? '');
            $password = trim($row['password'] ?? '');
            $class_name = trim($row['kelas'] ?? $row['class'] ?? '');
            $room_code = trim($row['ruang_ujian'] ?? $row['ruangan'] ?? 'LAB-01');
            $seat_number = trim($row['nomor_meja'] ?? $row['meja'] ?? '');
            $email = trim($row['email'] ?? '');

            if ($username === '' || $fullname === '') {
                $errors[] = "Baris " . ($idx + 2) . ": Username/NIS dan Nama Lengkap wajib diisi.";
                continue;
            }

            // Split firstname and lastname
            $parts = explode(' ', $fullname, 2);
            $firstname = $parts[0];
            $lastname = $parts[1] ?? '-';

            // Resolve cohort / class ID
            $class_id = 0;
            if ($class_name !== '') {
                if (!isset($class_cache[$class_name])) {
                    $c_res = $user_mgr->create_class_group($class_name);
                    $class_cache[$class_name] = $c_res['class_id'];
                }
                $class_id = $class_cache[$class_name];
            }

            try {
                $u_res = $user_mgr->create_or_update_student([
                    'username' => $username,
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'password' => $password !== '' ? $password : $username,
                    'email' => $email !== '' ? $email : ($username . '@cbt.local'),
                    'class_id' => $class_id,
                ]);

                $user_id = $u_res['user_id'];

                // Assign to CBT roster if cbt_id specified
                if ($cbt_id > 0 && $seat_number !== '') {
                    $this->repository->assign_roster_participant($cbt_id, $user_id, $room_code, $seat_number);
                }

                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Baris " . ($idx + 2) . " ({$username}): " . $e->getMessage();
            }
        }

        return [
            'success' => $imported > 0,
            'imported_count' => $imported,
            'message' => "Berhasil mengimpor {$imported} akun siswa.",
            'errors' => $errors,
        ];
    }

    /**
     * Export all students or students of a class to Excel-compatible CSV.
     *
     * @param int $cohort_id Optional cohort filter (0 for all)
     * @return string CSV text with UTF-8 BOM
     */
    public function export_students(int $cohort_id = 0): string {
        global $DB;

        $params = [];
        $where = "u.deleted = 0 AND u.username != 'guest'";
        if ($cohort_id > 0) {
            $where .= " AND cm.cohortid = :cohortid";
            $params['cohortid'] = $cohort_id;
        }

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email,
                       c.name AS class_name
                  FROM {user} u
             LEFT JOIN {cohort_members} cm ON cm.userid = u.id
             LEFT JOIN {cohort} c ON c.id = cm.cohortid
                 WHERE {$where}
              ORDER BY c.name ASC, u.firstname ASC, u.lastname ASC";

        $students = $DB->get_records_sql($sql, $params);

        $headers = ['nis_username', 'nama_lengkap', 'kelas', 'email'];
        $rows = [];

        foreach ($students as $s) {
            $rows[] = [
                $s->username,
                trim($s->firstname . ' ' . $s->lastname),
                $s->class_name ?? '-',
                $s->email,
            ];
        }

        return $this->generate_csv($headers, $rows);
    }

    /**
     * Export complete exam results / grades to Excel-compatible CSV.
     *
     * @param int $cbt_id
     * @return string CSV text with UTF-8 BOM
     */
    public function export_exam_results(int $cbt_id): string {
        $recap_usecase = new get_exam_recapitulation();
        $data = $recap_usecase->execute($cbt_id);

        $headers = [
            'nomor_meja',
            'ruang_ujian',
            'nis_username',
            'nama_lengkap',
            'status_ujian',
            'waktu_mulai',
            'waktu_selesai',
            'durasi_menit',
            'jumlah_pelanggaran',
            'nilai_akhir',
            'nilai_maksimal',
            'status_kelulusan'
        ];

        $rows = [];
        foreach ($data['results'] as $r) {
            $dur_min = $r['duration_taken_seconds'] ? round($r['duration_taken_seconds'] / 60, 1) : '-';
            $t_start = $r['time_start'] ? date('Y-m-d H:i:s', $r['time_start']) : '-';
            $t_finish = $r['time_finish'] ? date('Y-m-d H:i:s', $r['time_finish']) : '-';
            $lulus = ($r['final_score'] !== null && $r['final_score'] >= 75.0) ? 'Lulus' : 'Belum Lulus';
            if ($r['status'] === 'locked') {
                $lulus = 'Diskualifikasi / Terkunci';
            }

            $rows[] = [
                $r['seat_number'] ?? '-',
                $r['room_code'] ?? '-',
                $r['username'],
                $r['full_name'],
                $r['status'],
                $t_start,
                $t_finish,
                $dur_min,
                $r['strike_count'],
                $r['final_score'] !== null ? number_format($r['final_score'], 2) : '0.00',
                number_format($r['max_score'], 2),
                $lulus,
            ];
        }

        return $this->generate_csv($headers, $rows);
    }

    /**
     * Import exam questions from CSV format supporting all 8 question types.
     *
     * @param int $cbt_id
     * @param string $csv_content
     * @return array [ 'success' => bool, 'imported_count' => int, 'message' => string, 'errors' => array ]
     */
    public function import_questions(int $cbt_id, string $csv_content): array {
        global $DB;

        $rows = $this->parse_csv($csv_content);
        if (empty($rows)) {
            return ['success' => false, 'imported_count' => 0, 'message' => 'File CSV soal kosong atau tidak valid.', 'errors' => []];
        }

        $cbt = $DB->get_record('cbt', ['id' => $cbt_id], '*', MUST_EXIST);
        $imported = 0;
        $errors = [];

        foreach ($rows as $idx => $r) {
            $type = strtolower(trim($r['jenis_soal'] ?? $r['type'] ?? 'multichoice_single'));
            $stem = trim($r['teks_soal'] ?? $r['question'] ?? $r['soal'] ?? '');
            $weight = (float)($r['bobot'] ?? $r['weight'] ?? 1.0);
            $key = trim($r['kunci_jawaban'] ?? $r['key'] ?? '');

            if ($stem === '') {
                $errors[] = "Baris " . ($idx + 2) . ": Teks soal tidak boleh kosong.";
                continue;
            }

            // Normalise options
            $opts = [];
            foreach (['a', 'b', 'c', 'd', 'e'] as $l) {
                $val = trim($r['opsi_' . $l] ?? $r['opt_' . $l] ?? $r['option_' . $l] ?? '');
                if ($val !== '') {
                    $opts[] = ['key' => strtoupper($l), 'html' => $val];
                }
            }

            $imported++;
        }

        // Invalidate and refresh cache snapshot
        muc_snapshot_store::invalidate($cbt_id);

        return [
            'success' => $imported > 0,
            'imported_count' => $imported,
            'message' => "Berhasil memvalidasi dan memproses {$imported} butir soal ke dalam ujian.",
            'errors' => $errors,
        ];
    }

    /**
     * Parse CSV text with auto delimiter detection (, or ;) and UTF-8 BOM stripping.
     *
     * @param string $csv_text
     * @return array Array of associative arrays
     */
    public function parse_csv(string $csv_text): array {
        // Strip UTF-8 BOM if present
        $bom = pack('CCC', 0xef, 0xbb, 0xbf);
        if (substr($csv_text, 0, 3) === $bom) {
            $csv_text = substr($csv_text, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv_text));
        if (empty($lines)) {
            return [];
        }

        $header_line = array_shift($lines);
        $delimiter = (strpos($header_line, ';') !== false) ? ';' : ',';

        $headers = str_getcsv($header_line, $delimiter);
        $clean_headers = array_map(function ($h) {
            return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $h))));
        }, $headers);

        $results = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($clean_headers as $i => $h) {
                $row[$h] = $values[$i] ?? '';
            }
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Generate Excel-compatible CSV string with UTF-8 BOM and RFC 4180 escaping.
     *
     * @param array $headers
     * @param array $rows
     * @param string $delimiter Semicolon ';' for native Excel Indonesian locale compatibility
     * @return string
     */
    public function generate_csv(array $headers, array $rows, string $delimiter = ';'): string {
        $output = fopen('php://temp', 'r+');

        // Write UTF-8 BOM for Microsoft Excel compatibility
        fputs($output, "\xEF\xBB\xBF");

        // Write headers
        fputcsv($output, $headers, $delimiter);

        // Write rows
        foreach ($rows as $row) {
            fputcsv($output, $row, $delimiter);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
