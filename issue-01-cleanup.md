# Issue 01: Pembersihan Repositori dan Restrukturisasi Direktori Proyek

**Label:** `refactor`, `cleanup`  
**Prioritas:** Tinggi  

## Deskripsi
Membersihkan file-file *legacy* (dokumentasi lama, skrip lama, modul Rust yang tidak terpakai) serta memastikan struktur folder portabel utama beserta file konfigurasi defaultnya sudah siap di dalam root proyek.

## Task List
- [x] Menghapus file dokumentasi lama: `PLANNING.md`, `REQUIREMENT.md`, `SKILL.md`, `app_icon.png`.
- [x] Menghapus folder skrip PowerShell lama: `scripts/`.
- [x] Menghapus modul Rust lama yang digantikan: 
  - `src/browser.rs`, `src/cron.rs`, `src/database.rs`, `src/lib.rs`, 
  - `src/lifecycle.rs`, `src/moodle.rs`, `src/network.rs`, 
  - `src/payload.rs`, `src/progress.rs`, `src/prune.rs`.
- [x] Menyiapkan struktur direktori dasar dan file konfigurasi default untuk:
  - `nginx/conf/nginx.conf`
  - `php/php.ini`
  - `moodle/config.php`
  - `mariadb/data/my.ini`
- [x] Memperbarui `.gitignore` agar file kontainer terenkripsi (`*.pak`), lisensi (`*.lic`), dan folder runtime tidak ikut ter-commit ke Git.