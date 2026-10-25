# Issue 05: Orkestrasi Proses Layanan Background dan Siklus Hidup (`process.rs`, `main.rs`)

**Label:** `process-management`, `lifecycle`, `cli`  
**Bergantung pada:** Issue 02, 03, 04  

## Deskripsi
Mengelola proses eksekusi MariaDB, PHP-CGI, dan Nginx secara *headless* (tanpa jendela CMD) serta menangani siklus hidup aplikasi (*graceful shutdown* dan *re-encryption*).

## Task List
- [x] Mengonfigurasi penelusuran port kosong (menggunakan modul `network.rs`).
- [x] Menjalankan proses layanan secara berurutan dengan bendera `CREATE_NO_WINDOW`:
  - `mariadb/bin/mysqld.exe --defaults-file=mariadb/data/my.ini`
  - `php/php-cgi.exe -b 127.0.0.1:9000 -c php/php.ini`
  - `nginx/nginx.exe` (berada di dalam direktori kerja `nginx/`)
- [x] Membuka browser default secara otomatis ke URL aplikasi (misal: `http://127.0.0.1:8080`).
- [x] Menangani sinyal keluar aplikasi (`Ctrl+C`):
  - Menghentikan Nginx (`nginx -s stop`).
  - Mematikan proses `php-cgi.exe`.
  - Melakukan *shutdown* bersih MariaDB (`mysqladmin -u root shutdown`).
  - Memicu enkripsi ulang (`encrypt_all` / `seal_folders`) seluruh folder ke dalam brankas sebelum aplikasi benar-benar tertutup.
- [x] Menambahkan dukungan argumen CLI `--encrypt` / `--pack` untuk keperluan *packaging* awal oleh developer.