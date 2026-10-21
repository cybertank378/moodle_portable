# Moodle Portable (Aksaventra LMS Portable) 64-bit

[![Rust](https://img.shields.io/badge/Rust-1.85%2B-orange.svg)](https://www.rust-lang.org/)
[![Platform](https://img.shields.io/badge/Platform-Windows%20x64-blue.svg)]()
[![Release](https://img.shields.io/badge/Release-v0.1.0-brightgreen.svg)](https://github.com/cybertank378/moodle_portable/releases)
[![License](https://img.shields.io/badge/License-GPL--3.0-lightgrey.svg)]()

**Moodle Portable** adalah *single-executable launcher* mandiri berbasis bahasa pemrograman **Rust (64-bit)** untuk sistem operasi Microsoft Windows. Aplikasi ini membungkus stack WNMP lengkap secara portabel dan *plug-and-play* tanpa memerlukan konfigurasi manual rumit ataupun hak akses Administrator (UAC).

---

## 📋 Daftar Isi
1. [Fitur Utama](#-fitur-utama)
2. [Komponen Stack 64-bit](#-komponen-stack-64-bit)
3. [Kebutuhan Sistem (Prerequisites)](#-kebutuhan-sistem-prerequisites)
4. [Penjelasan `payload.zip` (1 KB vs 207 MB)](#-penjelasan-payloadzip-1-kb-vs-207-mb)
5. [Panduan Langkah demi Langkah](#-panduan-langkah-demi-langkah)
   - [Langkah 1: Kloning Repositori](#langkah-1-kloning-repositori)
   - [Langkah 2: Rakit `payload.zip` Riil](#langkah-2-rakit-payloadzip-riil)
   - [Langkah 3: Kompilasi Executable (Release Build)](#langkah-3-kompilasi-executable-release-build)
   - [Langkah 4: Jalankan Aplikasi](#langkah-4-jalankan-aplikasi)
   - [Langkah 5: Akses Moodle Melalui Browser](#langkah-5-akses-moodle-melalui-browser)
6. [Opsi Perintah CLI (Command Line Options)](#-opsi-perintah-cli-command-line-options)
7. [Penghentian Layanan Aman (Graceful Shutdown)](#-penghentian-layanan-aman-graceful-shutdown)
8. [Struktur Kode Sumber](#-struktur-kode-sumber)
9. [Lisensi](#-lisensi)

---

## 🌟 Fitur Utama

- **Zero-Configuration & Portable**: Berjalan langsung dari flashdisk, hard disk eksternal, atau folder lokal tanpa perlu menginstal server web terpisah.
- **Dual-Mode Payload Provisioning**:
  - *Online Mode*: Mengunduh paket runtime resmi saat koneksi internet aktif.
  - *Offline Mode*: Bekerja luring penuh menggunakan berkas lokal `offline_payload.zip` dan file penanda `placeholder.txt`.
- **Visual Installation Progress Bar**: Menampilkan indikator kemajuan instalasi (0% s/d 100%) dengan 5 tahapan berurutan secara dinamis pada konsol.
- **Custom Installation Directory**: Mendukung pemilihan direktori target instalasi via argumen CLI `--install-dir <PATH>` atau variabel lingkungan `MOODLE_INSTALL_DIR`.
- **Dynamic LAN IP Routing**: Akses instan dari jaringan lokal (Wi-Fi/LAN) tanpa konfigurasi DNS berkat dynamic HTTP_HOST patcher.
- **Keamanan & Isolasi Direktori (NFR-04)**: Direktori sensitif `moodledata` terisolasi ketat di luar direktori web root Nginx.
- **Background Cron Scheduler**: Thread latar belakang mandiri yang mengeksekusi cron Moodle setiap 60 detik tanpa memblokir thread utama.
- **Graceful Shutdown Manager**: Menangani sinyal `Ctrl + C` secara tertib untuk mematikan Nginx, melakukan flush buffer database MariaDB, dan mematikan worker PHP secara aman.
- **Ukuran Ramping (Pruning Engine)**: Skrip pemangkasan internal membuang test suites, behat, dan berkas non-esensial Moodle sehingga menghemat lebih dari **56 MB**.

---

## 📦 Komponen Stack 64-bit

Seluruh modul dan executable beroperasi pada arsitektur **murni 64-bit (x86_64 / AMD64)**:

| Komponen | Versi | Sumber Resmi |
| :--- | :--- | :--- |
| **Moodle Core** | v5.0.11 | [Moodle.org Official Stable 500](https://packaging.moodle.org/stable500/moodle-5.0.11.zip) |
| **PHP Runtime** | 8.2.12 x64 | [PHP for Windows (Win32-vs16-x64)](https://windows.php.net/downloads/releases/archives/php-8.2.12-Win32-vs16-x64.zip) |
| **Nginx Server** | 1.31.7 | [Nginx.org Official Windows Build](https://nginx.org/download/nginx-1.31.7.zip) |
| **MariaDB** | 13.0.2 x64 | [MariaDB Winx64 Mirror](https://linux.domainesia.com/mariadb///mariadb-13.0.2/winx64-packages/mariadb-13.0.2-winx64.zip) |

---

## 💻 Kebutuhan Sistem (Prerequisites)

### Untuk Menjalankan Aplikasi (End-User):
- **Sistem Operasi**: Windows 10 atau Windows 11 (64-bit).
- **Arsitektur**: x86_64 / AMD64.
- **RAM**: Minimal 2 GB (Disarankan 4 GB+).
- **Ruang Disk Bebas**: Minimal 1 GB.
- **Hak Akses**: Standard User (tidak membutuhkan hak Administrator).

### Untuk Membangun dari Sumber / Build from Source (Developer):
- **Rust Toolchain**: Versi 1.85+ (Edition 2024), target `x86_64-pc-windows-msvc`.
- **PowerShell**: Windows PowerShell 5.1+ atau PowerShell 7+ (`pwsh`).
- **cURL**: Bawaan Windows 10/11.
- **Git for Windows**.

---

## 💡 Penjelasan `payload.zip` (1 KB vs 207 MB)

> [!IMPORTANT]
> **Mengapa file `payload.zip` yang ada di Git hanya berukuran ~1 KB (204 bytes)?**
> 
> Berkas arsip runtime WNMP riil yang lengkap berukuran **207.38 MB**.
> **GitHub memiliki batasan ukuran file maksimal 100 MB**, sehingga berkas 207 MB ditolak jika di-commit langsung ke Git.
> 
> Berkas `payload.zip` berukuran 1 KB di repository adalah **berkas stub/placeholder** yang memastikan proyek dapat langsung dikloning dan lulus pengujian unit (`cargo test`).
> 
> Untuk merakit `payload.zip` riil berukuran **207.38 MB**, Anda cukup menjalankan skrip otomatis [`scripts/build_payload.ps1`](file:///d:/Project/moodle_installer/scripts/build_payload.ps1) seperti yang dijelaskan pada langkah berikut.

---

## 🚀 Panduan Langkah demi Langkah

### Langkah 1: Kloning Repositori
Buka PowerShell dan kloning repositori proyek ini:
```powershell
git clone https://github.com/cybertank378/moodle_portable.git
cd moodle_installer
```

---

### Langkah 2: Rakit `payload.zip` Riil
Jalankan skrip otomasi builder:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\build_payload.ps1
```

Skrip ini akan secara otomatis:
1. Mengunduh komponen resmi WNMP stack ke folder cache `.cache_downloads`.
2. Mengekstrak dan menyusun struktur folder: `moodle/`, `php/`, `nginx/`, `mariadb/`, dan `moodledata/`.
3. Mengonfigurasi `php.ini` dengan ekstensi wajib Moodle (`curl`, `intl`, `mbstring`, `mysqli`, `opcache`, `sodium`, `zip`).
4. Memangkas modul pengujian Moodle (`scripts/prune_moodle.ps1`) untuk menghemat 56 MB ruang penyimpanan.
5. Mengemas seluruh hasil staging menjadi berkas riil **`payload.zip` berukuran 207.38 MB**.

---

### Langkah 3: Kompilasi Executable (Release Build)
Kompilasi launcher ke dalam biner teroptimasi 64-bit:
```powershell
cargo build --release
```

Berkas biner yang dihasilkan akan berada di:
```text
.\target\x86_64-pc-windows-msvc\release\moodle_installer.exe
```

---

### Langkah 4: Jalankan Aplikasi
Jalankan executable biner yang telah dikompilasi:
```powershell
.\target\x86_64-pc-windows-msvc\release\moodle_installer.exe
```

Indikator visual kemajuan tahapan akan ditampilkan di layar:
```text
==========================================================
          Moodle Portable 64-bit Engine Initializing      
==========================================================
Target Installation Directory: ./runtime
[=>                          ]   5% [Stage 1/5] Payload Provisioning - Detecting network connectivity...
[====>                       ]  15% [Stage 1/5] Payload Provisioning - Provisioning runtime payload...
[=======>                    ]  25% [Stage 1/5] Payload Provisioning - Extracted components successfully.
[=========>                  ]  35% [Stage 2/5] Sandbox Setup & Pruning - Configuring unified installation paths...
[============>               ]  45% [Stage 2/5] Sandbox Setup & Pruning - Writing secure Nginx sandbox configuration...
[===============>            ]  55% [Stage 3/5] MariaDB Database Bootstrap - Configuring MariaDB paths and buffer pool...
[===================>        ]  70% [Stage 3/5] MariaDB Database Bootstrap - MariaDB configuration ready.
[======================>     ]  80% [Stage 4/5] Moodle CLI Installation - Verifying Moodle web root and dynamic routing...
[=========================>  ]  90% [Stage 4/5] Moodle CLI Installation - Moodle environment verified.
[==========================> ]  95% [Stage 5/5] Services Launch & Ready - Checking background daemons readiness...
[============================] 100% [Stage 5/5] Services Launch & Ready - Installation & runtime verification complete!
```

---

### Langkah 5: Akses Moodle Melalui Browser
Setelah inisialisasi selesai, buka browser web Anda dan arahkan ke alamat berikut:

1. **Akses Lokal (di komputer yang sama)**:
   ```text
   http://localhost
   ```
2. **Akses Jaringan LAN (dari perangkat lain / HP / laptop siswa dalam satu Wi-Fi)**:
   ```text
   http://<ALAMAT-IP-KOMPUTER-ANDA>
   ```
   *(Contoh: `http://192.168.1.15`)*

3. **Akun Administrator Default**:
   - **Username**: `admin`
   - **Password**: `Admin123!`

---

## ⚙️ Opsi Perintah CLI (Command Line Options)

Moodle Portable mendukung argumen baris perintah kustom:

```bash
moodle_installer.exe [OPTIONS]
```

### Opsi Tersedia:
| Flag | Parameter | Deskripsi |
| :--- | :--- | :--- |
| `-d`, `--install-dir` | `<PATH>` | Menentukan lokasi direktori target instalasi kustom (default: `./runtime`). |
| `-t`, `--target` | `<PATH>` | Alias untuk `--install-dir`. |
| `-h`, `--help` | - | Menampilkan petunjuk opsi penggunaan CLI. |

### Contoh Penggunaan Direktori Kustom:
```powershell
# Mengarahkan instalasi runtime ke drive D:
.\moodle_installer.exe --install-dir "D:\MoodlePortable"

# Atau menggunakan flag singkat:
.\moodle_installer.exe -d "D:\LMS"
```

### Variabel Lingkungan:
Anda juga dapat mengatur variabel lingkungan sistem `MOODLE_INSTALL_DIR`:
```powershell
$env:MOODLE_INSTALL_DIR = "E:\Sekolah_Moodle"
.\moodle_installer.exe
```

---

## 🛑 Penghentian Layanan Aman (Graceful Shutdown)

Untuk mematikan seluruh servis secara aman tanpa merusak integritas database MariaDB:
- Tekan **`Ctrl + C`** pada jendela terminal Moodle Portable.
- Engine akan menjalankan prosedur penghentian berurutan:
  1. Mengirim perintah `nginx -s quit` ke web server.
  2. Melakukan *flush tables* dan menghentikan daemon MariaDB via `mariadb-admin shutdown`.
  3. Menghentikan proses worker PHP-CGI FastCGI.

---

## 📂 Struktur Kode Sumber

```text
moodle_installer/
├── scripts/
│   ├── build_payload.ps1    # Skrip otomatisasi download, konfigurasi, & build payload.zip
│   └── prune_moodle.ps1     # Skrip pemangkas modul non-esensial Moodle
├── src/
│   ├── browser.rs           # Peluncur browser default otomatis
│   ├── cron.rs              # In-process background cron job scheduler
│   ├── database.rs          # MariaDB bootstrap, my.ini generator, & TCP probe
│   ├── lib.rs               # Library root & embedded payload zip extractor
│   ├── lifecycle.rs         # Graceful shutdown manager (Ctrl+C signal handling)
│   ├── main.rs              # Entry point CLI executable & startup orchestration
│   ├── moodle.rs            # Unattended CLI installer & dynamic wwwroot patcher
│   ├── network.rs           # Fast host LAN IP resolution & internet connection probe
│   ├── paths.rs             # Unified installation & runtime paths management
│   ├── payload.rs           # Dual-mode payload provisioning (online vs offline)
│   ├── process.rs           # Win32 CREATE_NO_WINDOW silent process spawner
│   ├── progress.rs          # Visual progress bar & CLI argument parsing
│   ├── prune.rs             # Logika pemangkasan ukuran direktori Moodle
│   └── security.rs          # Sandbox runtime isolation & secure nginx.conf generator
├── app_icon.ico             # Ikon biner resmi aplikasi Windows
├── build.rs                 # Integrasi resource metadata Windows PE (winres)
├── Cargo.toml               # Konfigurasi dependensi proyek Rust
└── README.md                # Dokumentasi lengkap proyek
```

---

## 📄 Lisensi
Proyek ini dilisensikan di bawah ketentuan lisensi **GNU General Public License v3.0 (GPL-3.0)**.
Moodle adalah merek dagang terdaftar milik Moodle Pty Ltd.
