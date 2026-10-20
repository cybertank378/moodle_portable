# Moodle Portable System Requirements Specification

## 1. Functional Requirements (FR)
- **FR-01 Single File Executable**: Seluruh komponen (Nginx, PHP, MariaDB, Moodle) harus terkemas dalam satu biner `.exe`.
- **FR-02 Zero-Installation Setup**: Aplikasi tidak boleh memerlukan proses instalasi installer tradisional (MSI/NSIS).
- **FR-03 Dynamic IP Resolution**: App harus dapat diakses oleh client jaringan LAN menggunakan IP address host yang berubah-ubah (DHCP).
- **FR-04 Silent Operation**: Aplikasi tidak boleh menampilkan jendela Command Prompt (CMD) saat berjalan.
- **FR-05 Automated Database & Application Provisioning**: Inisialisasi DB dan Moodle CLI harus berjalan otomatis pada eksekusi pertama.
- **FR-06 Background Cron Execution**: Moodle cron job harus dieksekusi secara periodik setiap 60 detik tanpa ketergantungan pada Windows Task Scheduler.

## 2. Non-Functional Requirements (NFR)
- **NFR-01 Performance**: Waktu boot awal (services ready) harus di bawah 5 detik setelah ekstraksi runtime selesai.
- **NFR-02 Memory Footprint**: Total RAM yang digunakan oleh Rust orchestrator, MariaDB, PHP-CGI, dan Nginx saat idle tidak boleh melebihi 400 MB.
- **NFR-03 Storage Footprint**: Ukuran file `.exe` rilis terkompresi tidak boleh melebihi 150 MB (setelah modul Moodle yang tidak perlu dibuang).
- **NFR-04 Security**: File data Moodle (`moodledata`) harus terisolasi di luar web root Nginx.