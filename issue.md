# Moodle Portable Issue Breakdown & Task Tracking

Dokumen ini memuat seluruh *Epic*, *Issue*, dan *Atomic Tasks* untuk pengembangan platform **Moodle Portable** (Portable Plug-and-Play WNMP + Pruned Moodle Launcher). Setiap issue dipetakan ke **Functional Requirements (FR)** dan **Non-Functional Requirements (NFR)** yang relevan.

---

## Epic 1: Core Engine & Runtime Extraction (Phase 1)

### Issue #01 - [Core] Payload Zip Embedding & Runtime Extraction Engine
* **Type**: Feature / Architecture
* **Priority**: P0 (Blocker)
* **Mapped Specs**: `FR-01` (Single File Executable), `NFR-03` (Storage Footprint)
* **Description**:
  Mengintegrasikan biner `payload.zip` (Nginx, PHP, MariaDB, Moodle) ke dalam biner Rust menggunakan `include_bytes!` serta membuat sistem ekstraksi otomatis ke folder `./runtime` saat aplikasi dijalankan pertama kali.

* **Tasks**:
  - [x] Tambahkan dependensi `zip = "0.6"` pada `Cargo.toml`.
  - [x] Implementasikan `static PAYLOAD_ZIP: &[u8] = include_bytes!("../payload.zip");`.
  - [x] Buat fungsi `extract_payload(target_dir: &Path)` untuk membongkar arsip zip secara murni di *memory/disk*.
  - [x] Implementasikan verifikasi keberadaan folder `./runtime` sebelum melakukan ekstraksi agar tidak melakukan *extract* ulang.

* **Acceptance Criteria**:
  - Jika folder `./runtime` tidak ada, file `.zip` terekstrak sepenuhnya dengan struktur direktori yang presisi.
  - Jika folder `./runtime` sudah ada, proses ekstraksi dilewati (*skipping* < 50ms).

---

### Issue #02 - [OS/Win32] Win32 Silent Subprocess Spawner Framework
* **Type**: Feature / Windows OS
* **Priority**: P0 (Blocker)
* **Mapped Specs**: `FR-04` (Silent Operation)
* **Description**:
  Seluruh child process (`mariadbd.exe`, `php-cgi.exe`, `nginx.exe`, `mysql.exe`, `php.exe`) memunculkan jendela Command Prompt (CMD) hitam saat dipanggil via `Command::new`. Tambahkan flag Win32 `CREATE_NO_WINDOW` untuk menghentikan popup konsol tersebut.

* **Tasks**:
  - [ ] Import `std::os::windows::process::CommandExt`.
  - [ ] Definisi konstanta `const CREATE_NO_WINDOW: u32 = 0x08000000;`.
  - [ ] Refactor seluruh pemanggilan `.spawn()` dan `.status()` agar menggunakan `.creation_flags(CREATE_NO_WINDOW)`.
  - [ ] Sediakan pengalihan `stdout(Stdio::null())` dan `stderr(Stdio::null())` pada seluruh proses.

* **Acceptance Criteria**:
  - Aplikasi berjalan sepenuhnya bersih tanpa popup jendela hitam/CMD di desktop pengguna, baik saat boot awal maupun saat *cron* berjalan.

---

### Issue #03 - [Security] Runtime Sandbox & Directory Isolation
* **Type**: Enhancement / Security
* **Priority**: P1 (High)
* **Mapped Specs**: `NFR-04` (Security & Data Isolation)
* **Description**:
  Direktori data sensitif Moodle (`moodledata`) harus berada di luar *web root* Nginx (`runtime/moodle`) untuk mencegah akses publik langsung ke berkas pengguna atau cache Moodle.

* **Tasks**:
  - [ ] Atur skema lokasi ekstraksi folder `moodledata` sejajar dengan folder `moodle` (`runtime/moodledata`).
  - [ ] Konfigurasi `nginx.conf` agar *root directory* hanya mengarah ke `../moodle`.
  - [ ] Tambahkan blok proteksi pada `nginx.conf` untuk memblokir akses ke file `.git`, `.env`, dan berkas sensitif lainnya.

* **Acceptance Criteria**:
  - Percobaan akses browser langsung ke path file internal `moodledata` mengembalikan status HTTP 404 / 403.

---

## Epic 2: Database, Provisioning & Dynamic Networking (Phase 2)

### Issue #04 - [Database] Automated MariaDB Initialization & Bootstrapping
* **Type**: Feature / Database
* **Priority**: P0 (Blocker)
* **Mapped Specs**: `FR-05` (Automated Provisioning), `NFR-01` (Boot Performance)
* **Description**:
  Mengotomatiskan booting daemon MariaDB dan membuat database `moodle` secara terprogram tanpa intervensi SQL manual dari pengguna.

* **Tasks**:
  - [ ] Buat fungsi `start_mariadb(base_dir: &str) -> Child` dengan path konfigurasi `my.ini`.
  - [ ] Buat fungsi `create_database(base_dir: &str)` menggunakan biner CLI `mysql.exe` dengan SQL query `CREATE DATABASE IF NOT EXISTS moodle`.
  - [ ] Tambahkan delay transisi (5 detik) untuk memastikan TCP port MariaDB `3306` siap menerima koneksi sebelum query dikirim.

* **Acceptance Criteria**:
  - Database `moodle` dengan charset `utf8mb4` dan collation `utf8mb4_unicode_ci` otomatis terbentuk di MariaDB pada *first run*.

---

### Issue #05 - [Moodle] Unattended CLI Installer & Dynamic Config Patching
* **Type**: Feature / Automation
* **Priority**: P0 (Blocker)
* **Mapped Specs**: `FR-03` (Dynamic IP), `FR-05` (Automated Provisioning)
* **Description**:
  Mengeksekusi installer CLI Moodle (`admin/cli/install.php`) secara otomatis dan mengganti variabel `$CFG->wwwroot` bawaan agar mendukung routing host dinamis.

* **Tasks**:
  - [ ] Implementasikan fungsi `install_moodle(base_dir: &str)` dengan argumen CLI lengkap (`--non-interactive`, `--agree-license`).
  - [ ] Buat fungsi `patch_moodle_config_for_dynamic_ip(base_dir: &str)`.
  - [ ] Modifikasi string `config.php` untuk mengganti statis `http://localhost` menjadi evaluasi runtime `$_SERVER['HTTP_HOST']`.

* **Acceptance Criteria**:
  - File `config.php` terbentuk secara otomatis.
  - Parameter `$CFG->wwwroot` berisi logika PHP dinamis yang mengikuti IP client.

---

### Issue #06 - [Network] Dynamic Host IP Resolution Engine
* **Type**: Feature / Networking
* **Priority**: P1 (High)
* **Mapped Specs**: `FR-03` (Dynamic IP Resolution)
* **Description**:
  Aplikasi harus dapat mendeteksi IP LAN aktif mesin host saat ini untuk keperluan pengujian dan pembukaan browser default.

* **Tasks**:
  - [ ] Implementasikan fungsi `get_local_ip() -> String` memanfaatkan probe koneksi `UdpSocket::bind("0.0.0.0:0")` ke gateway umum (`8.8.8.8:80`).
  - [ ] Sediakan *fallback* ke `127.0.0.1` jika PC berada dalam kondisi terisolasi tanpa antarmuka jaringan (offline total).

* **Acceptance Criteria**:
  - Fungsi mengembalikan alamat IP lokal aktif (misal `192.168.1.50`) jika terhubung ke LAN/DHCP, atau `127.0.0.1` jika offline.

---

### Issue #07 - [Cron] In-Process Background Cron Scheduler Thread
* **Type**: Feature / Automation
* **Priority**: P1 (High)
* **Mapped Specs**: `FR-06` (Background Cron Execution)
* **Description**:
  Moodle memerlukan eksekusi `admin/cli/cron.php` secara berkala untuk memproses tugas latar belakang (notifikasi, penilaian, pembersihan cache).

* **Tasks**:
  - [ ] Buat fungsi `start_moodle_cron(base_dir: String)` yang berjalan di dalam `thread::spawn`.
  - [ ] Implementasikan *infinite loop* dengan interval `thread::sleep(Duration::from_secs(60))`.
  - [ ] Panggil `php.exe admin/cli/cron.php` dengan flag `CREATE_NO_WINDOW`.

* **Acceptance Criteria**:
  - Task cron Moodle tereksekusi secara berkala setiap 60 detik di background thread tanpa membebankan main thread.

---

## Epic 3: Lifecycle Management & System Integration (Phase 3)

### Issue #08 - [Engine] Graceful Shutdown Manager & Signal Handler
* **Type**: Feature / Stability
* **Priority**: P0 (Blocker)
* **Mapped Specs**: `NFR-02` (Memory & Process Cleanliness)
* **Description**:
  Saat launcher ditutup, seluruh child process harus dihentikan secara teratur. Khusus MariaDB, perintah `shutdown` harus dikirimkan agar buffer InnoDB ter-flush ke disk untuk mencegah korupsi tabel.

* **Tasks**:
  - [ ] Tambahkan dependensi `ctrlc = "3.4"` di `Cargo.toml`.
  - [ ] Buat fungsi `stop_services_gracefully(base_dir: &str)`.
  - [ ] Jalankan `nginx.exe -s stop`.
  - [ ] Jalankan `mariadb-admin.exe -u root shutdown`.
  - [ ] Hentikan sisa proses `php-cgi.exe` via `taskkill`.
  - [ ] Pasang signal handler `ctrlc::set_handler` di fungsi `main()`.

* **Acceptance Criteria**:
  - MariaDB mati dengan *exit code 0* tanpa meninggalkan status *unclean shutdown* pada file log.

---

### Issue #09 - [UX] Automatic Default Browser Launcher
* **Type**: Feature / UX
* **Priority**: P2 (Low)
* **Mapped Specs**: `FR-02` (Zero-Installation Setup)
* **Description**:
  Membuka browser default sistem mengarah ke `http://localhost` atau IP LAN setelah seluruh service (Nginx, PHP, MariaDB) dipastikan siap.

* **Tasks**:
  - [ ] Buat fungsi `open_browser(url: &str)`.
  - [ ] Gunakan sub-proses `cmd /C start http://localhost` dengan `CREATE_NO_WINDOW`.
  - [ ] Panggil fungsi ini tepat setelah Nginx & PHP-CGI terkonfirmasi aktif.

* **Acceptance Criteria**:
  - Tab baru pada browser default otomatis terbuka dan menampilkan halaman depan Moodle begitu launcher diaktifkan.

---

## Epic 4: Optimization, Security & Packaging (Phase 4)

### Issue #10 - [Optimization] Moodle Core Pruning Script
* **Type**: Enhancement / Footprint
* **Priority**: P1 (High)
* **Mapped Specs**: `NFR-03` (Storage Footprint < 150MB)
* **Description**:
  Membuang modul-modul Moodle yang tidak diperlukan (seperti `mod_forum`, `mod_chat`, `mod_wiki`, `mod_workshop`) sebelum direktori disatukan ke dalam `payload.zip`.

* **Tasks**:
  - [ ] Buat skrip automasi pembersihan (`prune_moodle.ps1` atau `prune_moodle.sh`).
  - [ ] Hapus direktori `mod/forum`, `mod/chat`, `mod/wiki`, `mod/glossary`, `mod/workshop`.
  - [ ] Hapus folder pengujian `.git`, `node_modules`, dan berkas dokumentasi internal Moodle.

* **Acceptance Criteria**:
  - Ukuran folder Moodle berkurang minimal 35–45% dari ukuran asli sebelum di-zip.

---

### Issue #11 - [Build] Binary Icon & Resource Integration via Winres
* **Type**: Enhancement / Build System
* **Priority**: P2 (Low)
* **Mapped Specs**: `FR-01` (Single File Executable)
* **Description**:
  Menambahkan ikon `.ico` kustom pada berkas biner `.exe` hasil kompilasi agar aplikasi terlihat profesional.

* **Tasks**:
  - [ ] Tambahkan `winres = "0.1"` pada `[build-dependencies]` di `Cargo.toml`.
  - [ ] Buat file `build.rs` di root proyek.
  - [ ] Konfigurasi `winres::WindowsResource::new().set_icon("app_icon.ico")`.
  - [ ] Lakukan pengujian kompilasi rilis `cargo build --release`.

* **Acceptance Criteria**:
  - File `moodle_installer.exe` menampilkan ikon `app_icon.ico` di Windows File Explorer dan Task Manager.