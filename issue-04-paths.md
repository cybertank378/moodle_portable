# Issue 04: Penyelarasan Jalur Portabel dan Konfigurasi Otomatis (`paths.rs`, `moodle.rs`, `database.rs`)

**Label:** `config`, `portability`, `refactor`  
**Bergantung pada:** Issue 03  

## Deskripsi
Membuat sistem dinamis yang mendeteksi jalur absolut aplikasi saat berjalan dan memperbarui berkas konfigurasi layanan secara otomatis agar 100% portabel.

## Task List
- [ ] Mengembangkan fungsi deteksi root direktori dinamis menggunakan `std::env::current_exe()`.
- [ ] Membuat helper konversi path Windows (`\`) ke format POSIX (`/`).
- [ ] Mengimplementasikan fungsi *patching* otomatis saat runtime untuk file-file konfigurasi:
  - `nginx/conf/nginx.conf`: Menyesuaikan direktori `root` ke folder `moodle` dan rute `fastcgi_pass`.
  - `php/php.ini`: Menyesuaikan `extension_dir` dan `session.save_path`.
  - `moodle/config.php`: Menyesuaikan `$CFG->wwwroot` (port lokal dinamis) dan `$CFG->dataroot`.
  - `mariadb/data/my.ini`: Menyesuaikan `basedir` dan `datadir`.