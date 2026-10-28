# Aksaventra Portable Engine v2.2 (LMS Portable 64-bit)

[![Rust](https://img.shields.io/badge/Rust-1.85%2B-orange.svg)](https://www.rust-lang.org/)
[![Platform](https://img.shields.io/badge/Platform-Windows%20x64-blue.svg)]()
[![Release](https://img.shields.io/badge/Release-v2.2.0-brightgreen.svg)](https://github.com/cybertank378/moodle_portable/releases/tag/v2.2.0)
[![License](https://img.shields.io/badge/License-GPL--3.0-lightgrey.svg)]()

**Aksaventra Portable Engine v2.2** adalah platform Learning Management System (LMS) portabel berbasis web mandiri untuk Microsoft Windows (64-bit). 

Seluruh tumpukan server (**Nginx Web Server**, **PHP 8.2 FastCGI Multi-Process**, **MariaDB Database Server**, dan **Moodle LMS Core**) dikemas ke dalam satu kontainer brankas terenkripsi (**`aksaventra_vault.pak`**) dengan perlindungan enkripsi militer **AES-256-GCM** dan kompresi multi-threaded **Zstandard (`zstdmt`)**.

Anda **tidak perlu menginstal XAMPP, WampServer, Docker, atau software pihak ketiga lainnya**, dan **tidak memerlukan hak Administrator (UAC)**.

---

## 📋 Daftar Isi
1. [Panduan Pengguna Akhir (Quick Start)](#-panduan-pengguna-akhir-quick-start)
   - [Langkah 1: Unduh Paket Rilis v2.2.0](#langkah-1-unduh-paket-rilis-v220)
   - [Langkah 2: Persiapan Berkas Aplikasi](#langkah-2-persiapan-berkas-aplikasi)
   - [Langkah 3: Jalankan `aksaventra_engine.exe`](#langkah-3-jalankan-aksaventra_engineexe)
   - [Langkah 4: Akses LMS Melalui Browser](#langkah-4-akses-lms-melalui-browser)
   - [Langkah 5: Cara Menutup Aplikasi dengan Aman](#langkah-5-cara-menutup-aplikasi-dengan-aman)
2. [Sistem Lisensi & Hardware ID (HWID)](#-sistem-lisensi--hardware-id-hwid)
3. [Arsitektur & Keunggulan Akselerasi v2.2](#-arsitektur--keunggulan-akselerasi-v22)
4. [Akses Multi-Perangkat (HP / Laptop Siswa via Wi-Fi/LAN)](#-akses-multi-perangkat-hp--laptop-siswa-via-wi-filan)
5. [Portabilitas & Pencadangan (Backup ke Flashdisk)](#-portabilitas--pencadangan-backup-ke-flashdisk)
6. [Tanya Jawab & Pemecahan Masalah (Troubleshooting)](#-tanya-jawab--pemecahan-masalah-troubleshooting)
7. [Opsi Baris Perintah CLI (Tingkat Lanjut)](#-opsi-baris-perintah-cli-tingkat-lanjut)
8. [Panduan Pengembang (Build from Source)](#-panduan-pengembang-build-from-source)
9. [Struktur Kode Sumber & Lisensi](#-struktur-kode-sumber--lisensi)

---

## 🚀 Panduan Pengguna Akhir (Quick Start)

Panduan ini ditujukan bagi guru, pengajar, proktor ujian, administrator sekolah, atau pengguna umum yang ingin langsung menjalankan aplikasi LMS tanpa setup rumit.

### Langkah 1: Unduh Paket Rilis v2.2.0
Kunjungi halaman rilis resmi kami di GitHub:
👉 **[Unduh Aksaventra Portable Engine v2.2.0](https://github.com/cybertank378/moodle_portable/releases/tag/v2.2.0)**

Paket distribusi mencakup:
- 🚀 **`aksaventra_engine.exe`** (Engine peluncur mandiri berbasis Rust)
- 📦 **`aksaventra_vault.pak`** (Kontainer brankas terenkripsi AES-256-GCM + ZstdMT)
- 🔑 **`serial_key.lic`** (Berkas lisensi resmi perangkat Anda)

---

### Langkah 2: Persiapan Berkas Aplikasi
Pastikan ketiga berkas di atas berada di dalam **satu folder yang sama** (misalnya di `D:\AksaventraLMS` atau langsung pada Flashdisk USB):

```text
AksaventraLMS/
├── aksaventra_engine.exe
├── aksaventra_vault.pak
└── serial_key.lic
```

> [!WARNING]
> Berkas `aksaventra_vault.pak` adalah kontainer biner terotentikasi. **JANGAN dibuka, diubah, atau diekstrak secara manual**. Engine `aksaventra_engine.exe` akan mendekripsi dan memverifikasi integritasnya secara otomatis ke ruang kerja terlindungi (`.aksaventra_rt`).

---

### Langkah 3: Jalankan `aksaventra_engine.exe`
1. Klik dua kali pada file **`aksaventra_engine.exe`**.
2. Jendela konsol akan muncul menampilkan lisensi, inisialisasi port, dan indikator kemajuan (*progress bar* real-time):

```text
======================================================
          AKSAVENTRA PORTABLE ENGINE v2.0             
       Powered by Rust | aksaventra.ai.id             
======================================================
[AKSAVENTRA] Machine ID (HWID): 4a12...89ef
[AKSAVENTRA] Berkas Lisensi Lokal Ditemukan (aksaventra.token)
[AKSAVENTRA] Verifikasi Signature & HWID Lokal: Valid (Offline/Cached Mode).
[AKSAVENTRA] Lisensi Aktif: AKSA-XXXX-YYYY (Terdaftar atas nama: Universitas Aksaventra)
[VAULT] Membuka enkripsi kontainer aplikasi dan mengekstrak komponen...
[UNSEAL] [00:00:15] [########################################] 30904/30904 (100%) ETA: 00:00:00 | Selesai mengekstrak seluruh berkas.
[ENGINE] Menyalakan MariaDB Database Server (Port 3306)...
  [OK] MariaDB Database Server siap menerima koneksi.
  [OK] Database 'moodle' (utf8mb4_unicode_ci) terverifikasi & siap.
[ENGINE] Menyalakan PHP FastCGI (Port 9000)...
  [OK] PHP FastCGI Daemon aktif.
[ENGINE] Menyalakan Nginx Web Server (Port 8080)...
  [OK] Nginx Web Server aktif.
======================================================
   AKSAVENTRA PORTABLE LMS TELAH AKTIF & BERJALAN     
======================================================
  Akses Lokal (Server):  http://127.0.0.1:8080
  Akses Jaringan (LAN):  http://192.168.1.50:8080
  Status Layanan:        MariaDB, PHP FastCGI, Nginx (Aktif)
======================================================
  Tekan Ctrl+C untuk menutup dan mengamankan data.     
======================================================
```

> [!NOTE]
> - Ekstraksi awal didukung akselerasi multi-core CPU (Rayon parallel batching), selesai dalam hitungan detik.
> - Pada peluncuran berikutnya, engine otomatis mendeteksi komponen runtime yang sudah ada dan langsung aktif dalam **< 1 detik** (*Fast-Boot Cache*).

---

### Langkah 4: Akses LMS Melalui Browser
Setelah proses inisialisasi selesai:
1. Browser default Anda akan terbuka secara otomatis ke alamat LMS aktif:
   ```text
   http://127.0.0.1:8080 atau http://<IP-LOKAL>:8080
   ```
2. Anda akan langsung melihat beranda LMS Moodle. Masuk menggunakan akun **Administrator Bawaan**:
   - **Username**: `admin`
   - **Password**: `Admin123!`

---

### Langkah 5: Cara Menutup Aplikasi dengan Aman
Untuk menjaga agar data ujian, materi, dan database tidak rusak atau korup:
1. Kembali ke jendela terminal `aksaventra_engine.exe`.
2. Tekan kombinasi tombol **`Ctrl + C`**.
3. Sistem *Graceful Shutdown Manager* akan otomatis:
   - Menghentikan web server Nginx secara teratur.
   - Menghentikan proses worker PHP FastCGI.
   - Menyimpan seluruh antrean data (*buffer flush*) dan mematikan MariaDB secara bersih.
   - Mengenkripsi kembali seluruh perubahan data ke dalam kontainer vault.

---

## 🔑 Sistem Lisensi & Hardware ID (HWID)

Aksaventra Engine v2.2 dilengkapi dengan sistem otentikasi lisensi terikat perangkat keras (*hardware-locked*) dan penandatanganan kriptografis (*signed token*):

1. **Aktivasi Pertama Kali (Online Handshake)**:
   - Serial Key diverifikasi bersama Machine ID / Hardware ID (`HWID`) unik perangkat ke endpoint `https://aksaventra.ai.id/api/v1/validate`.
   - Server mengunci HWID ke Serial Key di database dan mengembalikan respon berlisensi (*signed response*).
   - Engine secara otomatis membuat berkas token terenkripsi lokal **`aksaventra.token`** yang dienkripsi menggunakan AES-256-GCM berbasis HWID perangkat.

2. **Eksekusi Mandiri (Offline / Cached Mode)**:
   - Pada peluncuran berikutnya, engine membaca `aksaventra.token` lokal, mendekripsi, dan memverifikasi integritas *digital signature* serta kecocokan HWID perangkat tanpa perlu selalu terhubung ke internet.
   - Jika berkas dipindahkan ke komputer lain, engine mendeteksi *Hardware Mismatch* (HTTP 403) dan menolak membuka brankas untuk melindungi hak cipta dan keamanan instansi.

---

## ⚡ Arsitektur & Keunggulan Akselerasi v2.2

- **Zero HTTP 503 Multi-Process FastCGI**:
  - `php-cgi.exe` dijalankan dengan worker pool multi-proses (`PHP_FCGI_CHILDREN = 16`, `PHP_FCGI_MAX_REQUESTS = 10000`) dan Nginx FastCGI buffer kapasitas tinggi (128k/256k/512k). Menghilangkan bottleneck dan error 503 saat Moodle memuat puluhan aset CSS/JS paralel secara bersamaan.
- **Dynamic Active LAN/Wi-Fi IP Binding**:
  - Engine otomatis mendeteksi IP jaringan lokal aktif komputer server (`get_active_local_ip`) dan langsung mengonfigurasi Moodle `$CFG->wwwroot` secara dinamis serta Nginx vhost (`listen {port}; server_name _;`). Akses via `127.0.0.1` maupun IP LAN berfungsi serentak tanpa looping redirect.
- **100% Dynamic Relative Paths (True Portability)**:
  - Seluruh konfigurasi sistem (`php.ini`, `config.php`, `my.ini`, `nginx.conf`) menggunakan path dinamis dan relatif (`extension_dir = "ext"`, `$CFG->dataroot = dirname(__DIR__) . '/moodledata'`). Bebas dari hardcoded drive path sehingga dapat dipindahkan ke flashdisk/drive apa pun.
- **Multi-Threaded Vault Packaging (`zstdmt` Level 1)**:
  - Mengompresi puluhan ribu berkas ke dalam wadah `aksaventra_vault.pak` menggunakan multi-core CPU secara paralel (~59 detik).
- **Parallel Batch Extraction (Rayon Multi-Threading)**:
  - Ekstraksi batch 2.000 berkas secara paralel ke SSD/NVMe dengan queue depth tinggi, memangkas waktu unseal dari hitungan menit menjadi hitungan detik.
- **Directory Deduplication**:
  - Pelacakan struktur direktori dengan `HashSet<PathBuf>` yang mengeliminasi puluhan ribu pemanggilan syscall `CreateDirectoryW` berulang pada Windows NTFS.
- **Dynamic Port Discovery**:
  - Jika port default (8080) sedang digunakan aplikasi lain, engine otomatis mendeteksi dan mengalihkan ke port bebas berikutnya (8081, 8082, dst.) tanpa error bentrok.
- **Headless Win32 Process Orchestrator**:
  - Seluruh daemon (Nginx, PHP, MariaDB) dijalankan tanpa jendela CMD yang mengganggu (`CREATE_NO_WINDOW`).
- **Automated MariaDB Bootstrap**:
  - Inisialisasi otomatis `mariadb-install-db.exe` dan pembuatan database `moodle` (`utf8mb4_unicode_ci`) pada saat startup dengan connection limit optimal (250 connections).

---

## 📱 Akses Multi-Perangkat (HP / Laptop Siswa via Wi-Fi/LAN)

1. Pastikan laptop server dan perangkat siswa terhubung ke **jaringan Wi-Fi atau hotspot yang sama**.
2. Engine akan otomatis menampilkan alamat IP Jaringan (LAN) Anda di konsol pada saat dinyalakan, contoh:
   ```text
   Akses Jaringan (LAN): http://192.168.1.50:8080
   ```
3. Beritahukan alamat tersebut kepada siswa:
   ```text
   http://192.168.1.50:8080
   ```
4. Siswa langsung dapat masuk dan mengerjakan ujian/kuis dari peramban HP mereka tanpa perlu menginstal aplikasi tambahan.

---

## 💾 Portabilitas & Pencadangan (Backup ke Flashdisk)

- Seluruh data kursus, akun pengguna, bank soal, dan berkas unggahan tersimpan di dalam ruang kerja `.aksaventra_rt` (`.aksaventra_rt/mariadb/data` dan `.aksaventra_rt/moodledata`).
- Saat aplikasi ditutup dengan `Ctrl + C`, seluruh perubahan diamankan kembali ke dalam kontainer.
- Anda dapat menyalin folder aplikasi ke flashdisk USB dan membawanya ke komputer lain tanpa kehilangan data.

---

## ⚙️ Opsi Baris Perintah CLI (Tingkat Lanjut)

```text
Penggunaan: aksaventra_engine.exe [PILIHAN]

Pilihan:
  -c, --concurrency, --workers <N>   Jumlah worker PHP FastCGI (default: auto rekomendasi hardware)
  --profile <PROFIL>                 Profil performa: eco | normal | high | extreme
  -p, --port <PORT>                  Tentukan port lokal khusus (default: 8080)
  --encrypt, --pack                  Kemas folder mentah ke dalam brankas terenkripsi (.pak)
  --skip-browser                     Jangan buka peramban secara otomatis
  -h, --help                         Tampilkan bantuan ini
```

Contoh:
```powershell
# Menjalankan dengan profiling otomatis mendeteksi RAM & CPU komputer host (Default):
.\aksaventra_engine.exe

# Mengoptimalkan untuk Ujian Akbar (profil High Performance / 32 workers):
.\aksaventra_engine.exe --profile high

# Menentukan jumlah worker eksplisit (misal 48 worker PHP FastCGI):
.\aksaventra_engine.exe -c 48

# Menjalankan pada port khusus 8888 tanpa membuka browser otomatis:
.\aksaventra_engine.exe -p 8888 --skip-browser

# Mengemas ulang berkas sumber ke dalam aksaventra_vault.pak:
.\aksaventra_engine.exe --pack
```

---

## 🛠️ Panduan Pengembang (Build from Source)

### Kebutuhan:
- **Rust Toolchain**: 1.85+ (Edition 2021/2024), target `x86_64-pc-windows-msvc`.
- **Git for Windows**.

### 1. Kloning Repositori
```powershell
git clone https://github.com/cybertank378/moodle_portable.git
cd moodle_installer
```

### 2. Kompilasi Release Binary
```powershell
cargo build --release --bin aksaventra_engine
```
Biner hasil kompilasi akan berada di `target\x86_64-pc-windows-msvc\release\aksaventra_engine.exe`.

### 3. Menjalankan Pengujian Unit
```powershell
cargo test --bin aksaventra_engine
```
Seluruh 22 unit test dipastikan lulus 100% hijau.

---

## 📂 Struktur Kode Sumber & Lisensi

```text
moodle_installer/
├── src/
│   ├── container.rs     # Brankas terenkripsi AES-256-GCM, ZstdMT, & Rayon parallel extraction
│   ├── hardware.rs      # Deteksi spesifikasi host (CPU/RAM) & dynamic concurrency profiling
│   ├── main.rs          # CLI entry point, startup orchestration, & graceful shutdown
│   ├── network.rs       # Network port discovery & socket binding probe
│   ├── paths.rs         # Path normalizer POSIX & portable dynamic configuration patcher
│   ├── process.rs       # Headless daemon orchestrator (MariaDB, PHP FastCGI, Nginx)
│   └── security.rs      # License verification (Online API & Signed Local Token), HWID binding
├── Cargo.toml           # Konfigurasi dependensi Rust (tokio, rayon, zstd, aes-gcm, indicatif)
├── aksaventra_vault.pak # Kontainer brankas data terenkripsi 
├── serial_key.lic       # Berkas lisensi resmi
└── README.md            # Dokumentasi lengkap
```

### Lisensi
Proyek ini dilisensikan di bawah ketentuan lisensi **GNU General Public License v3.0 (GPL-3.0)**.
