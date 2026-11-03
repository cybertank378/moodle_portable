# 🗺️ Architecture & Engineering Planning: `mod_cbt` (Moodle Activity Module)

## 1. Tujuan Arsitektural
Membangun Activity Module mandiri bernama `mod_cbt` di Moodle yang berfungsi sebagai **High-Concurrency Headless Exam Engine**. Berbeda dengan `mod_quiz` standar yang merender HTML secara monolitik dan membebani tabel `mdl_question_attempt_steps` di setiap perpindahan halaman, `mod_cbt` mendelegasikan seluruh *rendering* ke Next.js 16 dan menangani penyimpanan jawaban melalui mekanisme **Idempotent Batch Upsert** serta **Static JSON Exam Snapshot**.

## 2. Prinsip Arsitektur (Hexagonal / Clean Architecture di Moodle)
Kode di dalam `mod/cbt/classes/` dipisahkan menjadi tiga lapisan tegas:
1. **Domain Layer (`classes/domain/`):**
   - `entity/exam_session.php`: Mengelola aturan bisnis sesi ujian, kalkulasi *server time delta*, dan status penguncian (*strike count*).
   - `entity/token_rotator.php`: Algoritma pembangkit token 6-karakter berbasis HMAC-SHA256 dengan *time-window* (TOTP-style) atau *manual override*.
   - `service/grading_engine.php`: Kalkulator skor murni untuk Pilihan Ganda (MC), Pilihan Ganda Kompleks (MCMA), Menjodohkan, dan Isian Singkat tanpa ketergantungan pada *UI renderer* Moodle.
2. **Application Layer (`classes/application/`):**
   - `usecase/start_exam_usecase.php`: Memvalidasi token ruang, mengikat *Device Fingerprint*, dan menghasilkan *signed JWT/Session Token*.
   - `usecase/sync_answers_usecase.php`: Menangani antrean jawaban (*batch payload*) dari IndexedDB Next.js secara *atomic*.
   - `usecase/proctor_action_usecase.php`: Eksekusi perintah pengawas (*Force Submit*, *Reset Device Lock*, *Add Time Extension*, *Unlock Strike*).
3. **Infrastructure Layer (`classes/infrastructure/` & `classes/external/`):**
   - `repository/moodle_cbt_repository.php`: Implementasi query menggunakan `$DB` Moodle dengan optimasi *index* dan transaksi terisolasi.
   - `cache/exam_snapshot_cache.php`: Mengambil dan menyimpan struktur soal yang telah di-serialize ke Moodle Universal Cache (MUC) / File Cache agar 1.000 siswa yang membuka soal bersamaan melakukan **0 Query ke tabel Bank Soal**.
   - `external/*.php`: Kelas penghubung (`\core_external\external_api`) untuk komunikasi REST API dengan Next.js 16.

## 3. Struktur Direktori `mod/cbt/`
```text
mod/cbt/
├── classes/
│   ├── domain/
│   │   ├── entity/
│   │   │   ├── exam_attempt.php
│   │   │   └── strike_policy.php
│   │   └── service/
│   │       ├── token_generator.php
│   │       └── score_calculator.php
│   ├── application/
│   │   └── usecase/
│   │       ├── authenticate_attempt.php
│   │       ├── batch_sync_answers.php
│   │       ├── finalize_attempt.php
│   │       └── execute_proctor_command.php
│   ├── infrastructure/
│   │   ├── persistence/
│   │   │   └── db_attempt_repository.php
│   │   └── cache/
│   │       └── muc_snapshot_store.php
│   ├── external/
│   │   ├── get_exam_manifest.php
│   │   ├── sync_batch_answers.php
│   │   ├── report_violation.php
│   │   ├── submit_exam.php
│   │   └── proctor_control.php
│   └── event/
│       ├── attempt_started.php
│       ├── attempt_locked.php
│       └── attempt_submitted.php
├── db/
│   ├── install.xml
│   ├── upgrade.php
│   ├── services.php
│   ├── access.php
│   └── caches.php
├── lang/en/cbt.php
├── tests/
│   ├── domain_score_calculator_test.php
│   └── external_sync_batch_test.php
├── index.php
├── lib.php
├── mod_form.php
├── version.php
└── view.php
```
