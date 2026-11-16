# Aksaventra Portable LMS & CBT Engine (Official Releases)

[![Platform](https://img.shields.io/badge/Platform-Windows%20x64%20(10%2F11)-blue.svg)]()
[![Latest Release](https://img.shields.io/badge/Release-v2.2.4-brightgreen.svg)](https://github.com/cybertank378/aksaventra-releases/releases/latest)
[![Security](https://img.shields.io/badge/Encryption-AES--256--GCM%20%2B%20ZstdMT-purple.svg)]()
[![Architecture](https://img.shields.io/badge/Stack-Rust%20%7C%20Next.js%2016%20%7C%20Moodle%20%7C%20Nginx%20%7C%20MariaDB-orange.svg)]()
[![Website](https://img.shields.io/badge/Website-aksaventra.ai.id-orange.svg)](https://aksaventra.ai.id)

Repositori resmi distribusi berkas rilis, dokumentasi teknis, dan panduan instalasi **Aksaventra Portable LMS & CBT Engine v2.2.4** untuk Microsoft Windows (64-bit).

Aksaventra mengemas seluruh tumpukan server modern (**Nginx Web Server**, **PHP 8.2 FastCGI Multi-Worker Pool**, **MariaDB Database Server**, **Next.js 16 CBT Frontend**, dan **Moodle LMS Backend**) ke dalam satu kontainer brankas portabel terenkripsi tingkat militer (**`aksaventra_vault.pak`**) dengan algoritma **AES-256-GCM** dan kompresi mutakhir **Zstandard Multi-Thread (`zstdmt`)**.

Anda **tidak perlu menginstal XAMPP, WampServer, Docker, Node.js, Git, atau dependensi eksternal lainnya**, serta **tidak memerlukan hak Administrator (UAC)**. Cukup unduh, ekstrak, dan jalankan!

---

## 🌟 Fitur Utama & Pembaruan v2.2.4

### 1. 🔄 Sinkronisasi Data Dua Arah Real-Time (Moodle LMS ⟷ Next.js CBT)
- **Moodle Event Observers (`\core\event\*`)**: Mendeteksi penambahan, pembaruan, dan penghapusan data secara *real-time* (Siswa, Kelas/Cohort, Mata Pelajaran/Course, Sesi Ujian CBT, dan Bank Soal) dan langsung memicu webhook sinkronisasi ke portal CBT.
- **REST WebService `mod_cbt_sync_master`**: Menyediakan integrasi API terpusat untuk *pull/push* master data kurikulum dan bank soal.
- **Direct Database Sync Bridge (`cli/sync.php`)**: Jembatan sinkronisasi performa tinggi yang otomatis mengeksekusi rekonsiliasi data antara database Moodle (`moodle`) dan database CBT (`aksaventra_cbt`) saat engine menyala dan secara periodik di background.

### 2. 🛡️ Windows Job Object Kernel-Level Process Termination
- Engine Rust mendaftarkan dirinya ke objek Job Windows dengan flag `JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`.
- **Nol Proses Menggantung (No Zombie/Stuck Executables)**: Ketika jendela konsol engine ditutup (baik via `[X]`, `Ctrl+C`, maupun Task Manager), Windows OS secara otomatis menghentikan (*force terminate*) seluruh cabang proses anak (`node.exe`, `php-cgi.exe`, `nginx.exe`, `mariadb.exe`, `mysqld.exe`). Tidak ada lagi galat file terkunci (`EBUSY`).

### 3. ⏱️ Daemon Background Cron & Ad-Hoc Task Worker Terintegrasi
- Berjalan otomatis di *background thread* independen (`moodle-cron-worker`) setiap 60 detik.
- Menjalankan `admin/cli/cron.php`, `admin/cli/adhoc_task.php --execute --force`, dan `mod/cbt/cli/sync.php` secara hening (*headless/silent*).
- **Auto-Drain Ad-Hoc Migration**: Mengatasi peringatan tertundanya migrasi bank soal (`\mod_qbank\task\transfer_question_categories` dan `\mod_qbank\task\transfer_questions`).

### 4. 🌐 Dynamic Nginx Router Fallback (`r.php` & ESM Support)
- Mendukung penuh arsitektur router Moodle 4.5/5.x berbasis Slim Framework dan penyajian modul JavaScript ECMAScript (`esm_controller::serve`).
- Otomatis mengarahkan *URL rewriting fallback* ke `/r.php$is_args$args`, mencegah galat `Exception - Named route does not exist for name`.

### 5. 🚀 Peluncuran Multi-Tab Otomatis di Browser
Saat engine selesai menginisialisasi seluruh layanan:
- **Tab 1**: Moodle LMS Backend (`http://localhost:8080`)
- **Tab 2**: Aksaventra CBT Dashboard (`http://localhost:3000`) sebagai antarmuka utama aktif di layar depan.

### 6. 🔐 Kompatibilitas Windows 11 & Bypass SmartScreen (1-Klik)
- Disertakan skrip peluncur otomatis **`Buka_Aksaventra.bat`** dan utilitas **`Izinkan_SmartScreen.bat`** untuk menghapus atribut *Mark-of-the-Web (Zone.Identifier)*.
- Biner `aksaventra_engine.exe` ditandatangani secara digital dengan sertifikat resmi SHA256 (`aksaventra.cer`) dan manifest Windows 10/11.

---

## 📦 Berkas Distribusi Resmi (Assets Release)

| Berkas | Ukuran | Deskripsi |
| :--- | :--- | :--- |
| **`aksaventra-portable-v2.2.4-windows-x64.zip`** | ~281 MB | Paket distribusi lengkap siap pakai (mencakup engine, brankas, batch launcher, sertifikat, dan README). |
| **`aksaventra_engine.exe`** | ~4.48 MB | Biner eksekusi mesin mandiri (Rust 64-bit). |
| **`aksaventra_vault.pak`** | ~279 MB | Brankas kontainer aplikasi terenkripsi (36.400+ berkas terkompresi Zstd). |
| **`Buka_Aksaventra.bat`** | 1 KB | Peluncur instan 1-klik yang otomatis membuka blokir keamanan SmartScreen. |
| **`Izinkan_SmartScreen.bat`** | 1.2 KB | Utilitas unblock keamanan Windows Defender / Smart App Control. |
| **`aksaventra.cer`** | 854 B | Sertifikat tanda tangan digital resmi Aksaventra Technology Indonesia. |

---

## 🚀 Panduan Penggunaan Cepat (Quick Start)

### Langkah 1: Unduh Paket Rilis
Unduh berkas arsip zip resmi:
👉 **[Unduh aksaventra-portable-v2.2.4-windows-x64.zip](https://github.com/cybertank378/aksaventra-releases/releases/latest)**

### Langkah 2: Ekstrak Berkas
Ekstrak isi berkas zip ke folder pilihan Anda (misalnya di `D:\AksaventraLMS` atau langsung pada SSD/Flashdisk USB Eksternal):

```text
D:\AksaventraLMS\
├── aksaventra_engine.exe
├── aksaventra_vault.pak
├── Buka_Aksaventra.bat
├── Izinkan_SmartScreen.bat
├── aksaventra.cer
├── serial_key.lic (opsional jika lisensi diinput manual)
└── README.md
```

> [!IMPORTANT]
> Berkas `aksaventra_vault.pak` adalah kontainer biner terenkripsi. **JANGAN diekstrak atau diubah secara manual**. Engine akan mendekripsi komponen ke ruang kerja terlindungi (`.aksaventra_rt`) secara transparan.

### Langkah 3: Jalankan Aplikasi
1. Cukup klik ganda pada **`Buka_Aksaventra.bat`** (atau jalankan `aksaventra_engine.exe`).
2. Engine akan otomatis membuka blokir SmartScreen, mendeteksi spesifikasi CPU/RAM, menyalakan MariaDB, PHP FastCGI, Nginx, dan Node.js CBT.
3. Browser Anda akan terbuka otomatis ke dashboard CBT dan Moodle LMS!

---

## 🔑 Akun Bawaan (Default Credentials)

### 1. Akun Administrator Sistem (CBT & Moodle):
- **URL**: `http://localhost:3000` atau `http://localhost:8080`
- **Username**: `admin`
- **Password**: `Admin123!` (atau password yang telah Anda konfigurasikan)

### 2. Akun Siswa (Student Portal):
- Masuk ke portal login CBT di `http://localhost:3000/student/login` menggunakan NIS/Username siswa yang telah terdaftar di Moodle.

---

## 📶 Akses Jaringan Lokal / Wi-Fi (Ruang Ujian Siswa)

Aksaventra Portable Engine secara otomatis mendeteksi alamat IP jaringan aktif (LAN/Wi-Fi):
1. Sambungkan laptop server dan gawai siswa (Laptop, Chromebook, Tablet, atau HP Android/iOS) ke Access Point / Router Wi-Fi yang sama (tidak memerlukan koneksi internet).
2. Lihat alamat jaringan yang tertera pada konsol engine saat berjalan, contoh:
   ```text
   Akses CBT Jaringan (LAN/Wi-Fi) : http://192.168.1.50:3000
   Akses Moodle LMS Jaringan      : http://192.168.1.50:8080
   ```
3. Siswa cukup membuka browser dan memasukkan URL tersebut untuk langsung mengikuti ujian secara interaktif.

---

## 🛑 Cara Menutup Aplikasi dengan Aman

Untuk menjaga agar database dan perubahan data siswa tetap utuh dan terenkripsi kembali:
1. Kembali ke jendela konsol terminal engine.
2. Tekan **`Ctrl + C`**.
3. Sistem *Graceful Shutdown* akan:
   - Menghentikan background cron worker.
   - Menghentikan Nginx, Node.js, dan PHP FastCGI worker pool.
   - Melakukan `mysqladmin shutdown` pada MariaDB agar tabel InnoDB bersih.
   - Mengamankan dan mengenkripsi ulang seluruh perubahan data ke dalam `aksaventra_vault.pak`.

---

## 💻 Parameter Baris Perintah CLI (Advanced)

```text
Penggunaan: aksaventra_engine.exe [PILIHAN]

Pilihan:
  -c, --concurrency, --workers <N>   Jumlah worker PHP FastCGI (default: auto rekomendasi hardware)
  --profile <PROFIL>                 Profil performa: eco | normal | high | extreme
  -p, --port <PORT>                  Tentukan port web server Moodle khusus (default: 8080)
  --encrypt, --pack                  Kemas folder mentah payload ke dalam brankas terenkripsi (.pak)
  --skip-browser                     Jangan buka peramban secara otomatis saat engine aktif
  --check-update                     Periksa ketersediaan pembaruan mesin tanpa menyalakan server
  --skip-update                      Lewati pengecekan pembaruan otomatis
  -h, --help                         Tampilkan bantuan perintah
```

### Profil Tuning Hardware:
- **Eco**: 4 PHP Workers, 128MB Buffer Pool, 1.024 Connections (Kapasitas: ~50 Siswa Ujian Serentak)
- **Normal**: 8 PHP Workers, 256MB Buffer Pool, 2.048 Connections (Kapasitas: ~100 Siswa Ujian Serentak)
- **High (Optimal)**: 16-32 PHP Workers, 512M-1024M Buffer Pool, 4.096 Connections (Kapasitas: ~250 Siswa Ujian Serentak)
- **Extreme**: 64 PHP Workers, 2048M Buffer Pool, 8.192 Connections (Kapasitas: ~500 Siswa Ujian Serentak)

---

## ❓ Tanya Jawab & Pemecahan Masalah (FAQ)

### Q: Mengapa muncul peringatan Windows Defender / SmartScreen saat pertama kali dibuka?
**Jawab:** Karena berkas diunduh langsung dari internet, Windows menandainya dengan *Mark-of-the-Web*. Gunakan **`Buka_Aksaventra.bat`** atau klik kanan pada `aksaventra_engine.exe` -> *Properties* -> centang kotak **Unblock (Buka Blokir)** -> klik *OK*.

### Q: Port 8080 atau 3000 bentrok dengan aplikasi lain?
**Jawab:** Engine dilengkapi fitur *Network Port Discovery* otomatis yang akan mencari port terdekat berikutnya yang kosong jika port default sedang digunakan oleh aplikasi lain. Anda juga dapat menentukan port secara manual via parameter `-p <PORT>`.

### Q: Apakah data siswa dan nilai ujian hilang jika komputer mati mendadak?
**Jawab:** Tidak. Data operasional tersimpan secara transparan di ruang kerja disk MariaDB. Saat engine dinyalakan kembali, engine akan melanjutkan data tersebut dan menyinkronkannya kembali.

---

*Portal Resmi Lisensi & Dukungan Teknis: [https://aksaventra.ai.id](https://aksaventra.ai.id)*
*Hak Cipta © 2026 Aksaventra Technology Indonesia. All rights reserved.*
