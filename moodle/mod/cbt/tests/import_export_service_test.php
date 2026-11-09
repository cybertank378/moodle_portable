<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_cbt\tests;

if (!defined('MOODLE_INTERNAL')) {
    define('MOODLE_INTERNAL', true);
}

require_once(__DIR__ . '/../classes/application/usecase/import_export_service.php');

use mod_cbt\application\usecase\import_export_service;

class import_export_service_test {
    public static function run(): void {
        echo "Testing import_export_service...\n";

        // 1. Test parse_csv with semicolon delimiter & BOM
        $service = new import_export_service();
        $sampleSiswaFile = __DIR__ . '/../../../../templates/sample_import_siswa.csv';
        if (!file_exists($sampleSiswaFile)) {
            throw new \Exception("File $sampleSiswaFile not found");
        }
        $rawSiswa = file_get_contents($sampleSiswaFile);
        $rowsSiswa = $service->parse_csv($rawSiswa);

        assert(count($rowsSiswa) === 5, "Expected 5 student rows, got " . count($rowsSiswa));
        assert(isset($rowsSiswa[0]['nis_username']), "Header nis_username should exist");
        assert($rowsSiswa[0]['nis_username'] === '1001', "First student nis should be 1001");
        assert($rowsSiswa[0]['kelas'] === 'X-RPL-1', "First student class should be X-RPL-1");
        assert($rowsSiswa[0]['nomor_meja'] === 'A-01', "First student seat should be A-01");
        echo "  [PASS] Student CSV parsing verified\n";

        // 2. Test parse_csv with questions
        $sampleSoalFile = __DIR__ . '/../../../../templates/sample_import_soal.csv';
        if (!file_exists($sampleSoalFile)) {
            throw new \Exception("File $sampleSoalFile not found");
        }
        $rawSoal = file_get_contents($sampleSoalFile);
        $rowsSoal = $service->parse_csv($rawSoal);

        assert(count($rowsSoal) === 8, "Expected 8 question rows, got " . count($rowsSoal));
        
        $expectedTypes = [
            'multichoice_single',
            'multichoice_multi',
            'truefalse',
            'shortanswer',
            'numerical',
            'match',
            'gapselect',
            'essay'
        ];
        $actualTypes = array_column($rowsSoal, 'jenis_soal');
        assert($actualTypes === $expectedTypes, "All 8 question types must be present in order");
        echo "  [PASS] Question CSV parsing (all 8 question types) verified\n";

        // 3. Test parse_csv with exam recap
        $sampleRekapFile = __DIR__ . '/../../../../templates/sample_rekap_nilai.csv';
        if (!file_exists($sampleRekapFile)) {
            throw new \Exception("File $sampleRekapFile not found");
        }
        $rawRekap = file_get_contents($sampleRekapFile);
        $rowsRekap = $service->parse_csv($rawRekap);

        assert(count($rowsRekap) === 5, "Expected 5 recap rows, got " . count($rowsRekap));
        assert($rowsRekap[0]['status_kelulusan'] === 'LULUS', "Expected status_kelulusan LULUS");
        echo "  [PASS] Recap CSV parsing verified\n";

        echo "All import_export_service tests passed successfully!\n";
    }
}

import_export_service_test::run();

