# Aksaventra Portable Engine v2.2.0 (LMS Portable 64-bit)

Selamat datang di rilis **Aksaventra Portable Engine v2.2.0**! Pembaruan ini menghadirkan perombakan arsitektur portabilitas penuh (100% Dynamic Relative Paths), deteksi IP LAN/Wi-Fi otomatis untuk akses jaringan multi-perangkat tanpa jeda konfigurasi, serta eliminasi tuntas masalah HTTP Error 503 pada Moodle.

---

## 🌟 Sorotan Pembaruan (What's New in v2.2.0)

### 1. 📂 100% Dynamic Relative Paths (True Portability)
- **Eliminasi Hardcoded Absolute Path**: Seluruh jejak path statis seperti `D:/Project/moodle_installer/` telah diaudit dan diganti 100% dengan path relatif dinamis.
- **PHP Extension Directory**: `extension_dir = "ext"` di `php.ini` dan `src/paths.rs`. PHP Windows otomatis menyelesaikan folder `ext` relatif terhadap posisi `php-cgi.exe` berada, sehingga dapat berjalan di sembarang huruf drive (C:, D:, E:, Flashdisk USB) tanpa konfigurasi manual.
- **Moodle Dataroot Dinamis**: `$CFG->dataroot = dirname(__DIR__) . '/moodledata'` di `moodle/config.php` dan `src/paths.rs`.
- **MariaDB & Nginx Relative Setup**: MariaDB `basedir="."` & `datadir="./data"`, Nginx Virtual Host menggunakan relative root dan dynamic include.

### 2. 🌐 Dynamic Active LAN/Wi-Fi IP Auto-Binding
- **Deteksi Otomatis IP Aktif Host**: Engine mendeteksi antarmuka jaringan utama lokal yang aktif (`get_active_local_ip`).
- **Dynamic `$CFG->wwwroot` Moodle**: Menggunakan `$_SERVER['HTTP_HOST']` dengan fallback otomatis ke IP lokal aktif. Akses melalui `localhost`, `127.0.0.1`, maupun IP LAN (`http://192.168.x.x:8080`) bekerja secara mulus tanpa bentrok atau looping redirect.
- **Nginx Dual-Interface Listen**: Nginx dikonfigurasi `listen {port}; server_name _;` sehingga dapat diakses oleh HP/laptop siswa di jaringan Wi-Fi yang sama tanpa perlu setting firewall/IP manual.

### 3. ⚡ Eliminasi Total HTTP Error 503 (High-Concurrency Architecture)
- **Multi-Process PHP FastCGI Worker Pool**: Menjalankan `php-cgi.exe` dengan worker pool multi-proses dan `PHP_FCGI_MAX_REQUESTS = 10000`, menangani puluhan sub-request aset CSS/JS paralel Moodle tanpa antrean putus.
- **High-Throughput FastCGI Buffers**: Nginx FastCGI buffer dinaikkan menjadi `buffer_size 128k`, `buffers 16 128k`, `busy_buffers_size 256k`, `temp_file_write_size 512k`.
- **Optimal MariaDB Connection Pool**: MariaDB dikonfigurasi adaptif dengan `max_allowed_packet = 64M`, `connect_timeout = 30`, `wait_timeout = 600`.

### 4. 🎛️ Auto Hardware Recommendation & Dynamic Concurrency Scaling
- **Deteksi Spesifikasi Host Otomatis**: Engine mendeteksi jumlah Core CPU dan total RAM fisik komputer host sebelum proses unpack dan inisialisasi server.
- **Rekomendasi Profil Otomatis**:
  - **`Eco`** (≤4GB RAM / Dual-Core): 8 Workers, 128M DB Buffer, 1024 Nginx Connections (Estimasi: 25 - 50 Siswa Ujian).
  - **`Balanced`** (8GB RAM / Quad-Core): 16 Workers, 256M DB Buffer, 2048 Nginx Connections (Estimasi: 50 - 100 Siswa Ujian).
  - **`High Performance`** (16GB RAM / 8-Core): 32 Workers, 1024M DB Buffer, 4096 Nginx Connections (Estimasi: 150 - 250 Siswa Ujian).
  - **`Extreme`** (≥32GB RAM / 12+ Core): 64 Workers, 2048M DB Buffer, 8192 Nginx Connections (Estimasi: 300 - 500+ Siswa Ujian).
- **Opsi Kustomisasi Fleksibel**: Pengguna dapat mengubah alokasi daya tampung menggunakan parameter baris perintah `-c, --workers <JUMLAH>` atau `--profile <eco|normal|high|extreme>` sebelum aplikasi dijalankan.

---

## 📦 Berkas Distribusi (Assets)
- **`aksaventra-portable-v2.2.0-windows-x64.zip`**: Paket arsip siap pakai yang mencakup:
  - 🚀 `aksaventra_engine.exe` (Engine v2.2.0 terkompilasi biner asli Rust 64-bit)
  - 📦 `aksaventra_vault.pak` (Brankas kontainer terenkripsi AES-256-GCM + Zstandard)
  - 🔑 `serial_key.lic` (Template berkas lisensi resmi)
  - 📖 `README.md` (Panduan pengguna lengkap)
- **`aksaventra_engine.exe`**: Biner mandiri terpisah.

---

## 🚀 Cara Menjalankan
1. Ekstrak `aksaventra-portable-v2.2.0-windows-x64.zip` ke folder tujuan (misal `D:\AksaventraLMS` atau USB Flashdisk).
2. Jalankan `aksaventra_engine.exe`.
3. Masuk ke Moodle menggunakan peramban di komputer server atau lewat HP siswa di jaringan Wi-Fi yang sama sesuai alamat IP yang ditampilkan di konsol.
