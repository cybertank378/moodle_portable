# Moodle Portable Project Roadmap & Planning

## Executive Summary
Moodle Portable adalah platform launcher berbasis Rust yang membungkus Nginx, PHP, MariaDB, dan Moodle yang ter-pruning ke dalam single executable file untuk lingkungan Windows offline/LAN.

## Phase 1: Core Engine & Runtime Extraction (Week 1)
- [ ] Desain struktur payload (Nginx, PHP-CGI, MariaDB, Pruned Moodle).
- [x] Embed payload zip menggunakan `include_bytes!` pada Rust binary.
- [x] Implementasi ekstraksi otomatis dan struktur folder runtime.
- [x] Penanganan silent execution (`CREATE_NO_WINDOW`) untuk seluruh daemon.

## Phase 2: Dynamic Networking & Automated Setup (Week 2)
- [ ] Deteksi IP address LAN dinamis saat boot.
- [x] Otomatisasi instalasi database & Moodle CLI (`admin/cli/install.php`).
- [x] Patching `config.php` Moodle secara terprogram untuk dynamic HTTP_HOST routing.
- [ ] Implementasi Moodle Cron Job runner di background thread.

## Phase 3: Reliability & System Integration (Week 3)
- [ ] Implementasi Signal Handling (Ctrl+C / Process Kill) untuk Graceful Shutdown MariaDB & Nginx.
- [ ] Penambahan Windows System Tray Icon (Start, Stop, Restart, Open Browser).
- [ ] Pengujian konkurensi koneksi LAN pada skenario bandwidth terbatas.

## Phase 4: Distribution & Packaging (Week 4)
- [ ] Penambahan ikon biner (`app_icon.ico`) via `winres`.
- [ ] Optimization build release (`opt-level = "z"`, LTO enabled).
- [ ] Penetration & compatibility test pada Windows 10/11 64-bit non-admin accounts.