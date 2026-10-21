# Moodle Portable (Aksaventra LMS Portable) 64-bit

[![Rust](https://img.shields.io/badge/Rust-1.85%2B-orange.svg)](https://www.rust-lang.org/)
[![Platform](https://img.shields.io/badge/Platform-Windows%20x64-blue.svg)]()
[![Release](https://img.shields.io/badge/Release-v0.2.0--beta.1-brightgreen.svg)](https://github.com/cybertank378/moodle_portable/releases/tag/v0.2.0-beta.1)
[![License](https://img.shields.io/badge/License-GPL--3.0-lightgrey.svg)]()

**Moodle Portable** adalah aplikasi Learning Management System (LMS) berbasis **Moodle 5.0** yang dirancang agar dapat langsung berjalan secara portabel pada Microsoft Windows (64-bit). 

Seluruh tumpukan server (**Nginx**, **PHP 8.2**, **MariaDB**, dan **Moodle Core**) sudah dikemas menjadi satu kesatuan mandiri. Anda **tidak perlu menginstal XAMPP, WampServer, Docker, ataupun perangkat lunak tambahan lainnya**, dan **tidak membutuhkan hak Administrator (UAC)**.

---

## 📋 Daftar Isi
1. [Panduan Pengguna Akhir (End-User Quick Start)](#-panduan-pengguna-akhir-end-user-quick-start)
   - [Langkah 1: Unduh Paket Rilis](#langkah-1-unduh-paket-rilis)
   - [Langkah 2: Ekstrak Berkas Zip](#langkah-2-ekstrak-berkas-zip)
   - [Langkah 3: Jalankan `moodle_installer.exe`](#langkah-3-jalankan-moodle_installerexe)
   - [Langkah 4: Akses LMS Melalui Browser](#langkah-4-akses-lms-melalui-browser)
   - [Langkah 5: Cara Menutup Aplikasi dengan Aman](#langkah-5-cara-menutup-aplikasi-dengan-aman)
2. [Akses Multi-Perangkat (HP / Laptop Siswa via Wi-Fi/LAN)](#-akses-multi-perangkat-hp--laptop-siswa-via-wi-filan)
3. [Portabilitas & Pencadangan (Backup ke Flashdisk)](#-portabilitas--pencadangan-backup-ke-flashdisk)
4. [Tanya Jawab & Pemecahan Masalah (Troubleshooting)](#-tanya-jawab--pemecahan-masalah-troubleshooting)
5. [Fitur Unggulan](#-fitur-unggulan)
6. [Komponen Stack 64-bit](#-komponen-stack-64-bit)
7. [Kebutuhan Sistem (System Requirements)](#-kebutuhan-sistem-system-requirements)
8. [Opsi Baris Perintah CLI (Tingkat Lanjut)](#-opsi-baris-perintah-cli-tingkat-lanjut)
9. [Panduan Pengembang (Build from Source)](#-panduan-pengembang-build-from-source)
10. [Struktur Kode Sumber & Lisensi](#-struktur-kode-sumber--lisensi)

---

## 🚀 Panduan Pengguna Akhir (End-User Quick Start)

Panduan ini ditujukan bagi guru, pengajar, proktor ujian, administrator sekolah, atau pengguna umum yang ingin langsung menjalankan Moodle tanpa ribet.

### Langkah 1: Unduh Paket Rilis
Kunjungi halaman rilis resmi kami di GitHub:
👉 **[Unduh Moodle Portable v0.2.0-beta.1](https://github.com/cybertank378/moodle_portable/releases/tag/v0.2.0-beta.1)**

Pilih dan unduh berkas arsip zip:
- **`moodle-portable-v0.2.0-beta.1-windows-x64.zip`** (berisi launcher, wadah terenkripsi `payload.pak`, dan dokumentasi).

> [!TIP]
> Anda juga dapat mengunduh berkas `moodle_installer.exe` dan `payload.pak` secara terpisah, asalkan keduanya disimpan dalam folder yang sama.

---

### Langkah 2: Ekstrak Berkas Zip
1. Klik kanan pada berkas `moodle-portable-v0.2.0-beta.1-windows-x64.zip` yang telah diunduh.
2. Pilih **Extract All...** (Ekstrak Semua).
3. Tentukan lokasi folder tujuan (misal: di `D:\MoodlePortable`, Desktop, atau langsung di flashdisk eksternal).
4. Klik **Extract**.

---

### Langkah 3: Jalankan `moodle_installer.exe`
1. Buka folder hasil ekstraksi.
2. Klik dua kali pada file **`moodle_installer.exe`**.
3. Jendela konsol akan muncul dan menampilkan visual *progress bar* kemajuan inisialisasi:

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

Active Installation Components:
  - Moodle Web Root:    ./runtime/moodle
  - Moodledata Storage: ./runtime/moodledata
  - Nginx Server:       ./runtime/nginx
  - PHP 8.2 Runtime:    ./runtime/php
  - MariaDB Database:   ./runtime/mariadb
==========================================================
```

> [!NOTE]
> - Ekstraksi awal hanya berlangsung sekali (sekitar 15-30 detik tergantung kecepatan disk Anda).
> - Pada peluncuran berikutnya, engine otomatis mendeteksi folder runtime yang sudah ada dan langsung aktif dalam hitungan detik.

---

### Langkah 4: Akses LMS Melalui Browser
Setelah proses inisialisasi selesai:
1. Browser default Anda akan terbuka secara otomatis ke alamat:
   ```text
   http://localhost
   ```
2. Anda akan diarahkan ke halaman login Moodle.
3. Masuk menggunakan akun **Administrator Bawaan**:
   - **Username**: `admin`
   - **Password**: `Admin123!`

> [!IMPORTANT]
> **Saran Keamanan**: Segera ubah kata sandi administrator setelah login pertama kali melalui menu *Profile -> Change password*.

---

### Langkah 5: Cara Menutup Aplikasi dengan Aman
Untuk menjaga agar data ujian, materi, dan database tidak rusak atau korup:
1. Kembali ke jendela konsol/terminal `moodle_installer.exe`.
2. Tekan kombinasi tombol **`Ctrl + C`**.
3. Sistem *Graceful Shutdown Manager* akan otomatis:
   - Menghentikan web server Nginx secara teratur.
   - Menyimpan seluruh antrean data (*buffer flush*) dan mematikan MariaDB secara bersih.
   - Menghentikan proses worker PHP.
4. Jendela terminal akan tertutup dengan aman.

---

## 📱 Akses Multi-Perangkat (HP / Laptop Siswa via Wi-Fi/LAN)

Moodle Portable dilengkapi dengan fitur **Dynamic LAN IP Routing**. Guru/admin dapat menjalankan aplikasi di laptop server, dan seluruh siswa dapat membuka ujian atau materi belajar dari ponsel pintar atau laptop mereka masing-masing.

### Cara Menghubungkan Siswa:
1. Pastikan laptop server dan perangkat siswa terhubung ke **jaringan Wi-Fi atau hotspot yang sama**.
2. Periksa alamat IP lokal laptop server Anda (bisa dilihat di output terminal Moodle atau ketik `ipconfig` di Command Prompt).
   - Misal alamat IP laptop Anda adalah: `192.168.1.15`
3. Beritahukan alamat tersebut kepada siswa. Siswa cukup membuka browser di HP (Google Chrome, Safari, Firefox) dan mengetik:
   ```text
   http://192.168.1.15
   ```
4. Siswa langsung dapat masuk, membaca modul, atau mengerjakan kuis/ujian tanpa perlu menginstal aplikasi apapun!

---

## 💾 Portabilitas & Pencadangan (Backup ke Flashdisk)

Salah satu keunggulan terbesar Moodle Portable adalah **True Portability**:
- Seluruh data kursus, akun pengguna, bank soal, dan berkas unggahan tersimpan di dalam folder `runtime/` (`runtime/mariadb/data` dan `runtime/moodledata`).
- Jika Anda ingin memindahkan sistem ke komputer/laptop lain:
  1. Hentikan aplikasi dengan `Ctrl + C`.
  2. Salin seluruh folder aplikasi (beserta folder `runtime/` di dalamnya) ke flashdisk eksternal.
  3. Colokkan flashdisk ke komputer sekolah/kantor lain, lalu jalankan `moodle_installer.exe`.
  4. Semua data kursus, nilai, dan akun siswa tetap utuh 100%!

---

## ❓ Tanya Jawab & Pemecahan Masalah (Troubleshooting)

### Q: Muncul jendela Windows Defender Firewall saat pertama kali dijalankan?
> **Jawab**: Klik tombol **Allow Access** (Izinkan Akses) untuk jaringan privat. Ini diperlukan agar Nginx dan MariaDB dapat menerima koneksi dari HP siswa di jaringan Wi-Fi lokal.

### Q: Mengapa siswa tidak bisa membuka web dari HP mereka?
> **Solusi**:
> 1. Pastikan laptop server dan HP siswa terhubung ke satu Wi-Fi yang sama.
> 2. Pastikan Wi-Fi router tidak mengaktifkan fitur *Client Isolation* / *AP Isolation* (yang membatasi komunikasi antar perangkat).
> 3. Periksa apakah Windows Firewall di laptop Anda memblokir Port 80. Tambahkan izin port 80 pada *Inbound Rules* Windows Firewall jika diperlukan.

### Q: Port 80 bentrok (Port 80 already in use)?
> **Solusi**: Pastikan tidak ada aplikasi web server lain (seperti Apache XAMPP, IIS bawaan Windows, atau Skype lama) yang sedang berjalan di komputer Anda. Hentikan aplikasi tersebut sebelum menjalankan Moodle Portable.

### Q: Bolehkah folder `payload.pak` dihapus setelah instalasi selesai?
> **Jawab**: Setelah folder `runtime` terbentuk, aplikasi menggunakan file di dalam `runtime`. Namun, kami menyarankan tetap menyimpan `payload.pak` sebagai cadangan instalasi ulang jika Anda ingin mereset LMS ke kondisi awal.

---

## 🌟 Fitur Unggulan

- **Zero-Configuration & Portable**: Berjalan langsung tanpa instalasi installer msi/setup yang mengotori registri Windows.
- **Wadah Biner Terenkripsi Anti-Tamper (`payload.pak`)**:
  - Menggantikan berkas zip polos dengan format biner berkepala `AKSA`.
  - Tidak dapat dibuka atau diubah secara sembarangan oleh utilitas arsip umum (WinRAR, 7-Zip, File Explorer).
  - Dilengkapi verifikasi integritas cryptographic SHA-256 (jika diubah 1 byte sekalipun, engine menolak ekstraksi demi keamanan data).
  - Didekripsi langsung ke memori RAM (*in-memory streaming*), tidak meninggalkan file zip mentah di hard disk.
- **Dual-Mode Payload Provisioning**:
  - *Online Mode*: Mengunduh paket runtime resmi saat koneksi internet aktif.
  - *Offline Mode*: Bekerja luring penuh tanpa internet menggunakan berkas lokal `payload.pak`.
- **Pre-Initialized Database & Zero Missing Packages**:
  - Seluruh modul aktivitas resmi Moodle (Forum, Assignment, Quiz, Wiki, SCORM, dll.) lengkap 100%.
  - Tabel sistem MariaDB telah diinisialisasi otomatis dari awal.
- **Visual Progress Bar**: Menampilkan indikator kemajuan proses instalasi (0% s/d 100%) secara bertahap pada konsol.
- **Dynamic LAN IP Routing**: Menyesuaikan routing secara dinamis sehingga LMS dapat langsung diakses oleh perangkat lain di jaringan LAN.
- **Background Cron Scheduler**: Thread internal mengeksekusi cron Moodle otomatis setiap 60 detik tanpa memblokir sistem.
- **Graceful Shutdown**: Prosedur penutupan tertib via sinyal `Ctrl + C` untuk mencegah korupsi tabel InnoDB MariaDB.

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

## 💻 Kebutuhan Sistem (System Requirements)

### Untuk Menjalankan Aplikasi (End-User):
- **Sistem Operasi**: Windows 10 atau Windows 11 (64-bit).
- **Arsitektur CPU**: x86_64 / AMD64.
- **RAM**: Minimal 2 GB (Disarankan 4 GB+ agar performa maksimal).
- **Ruang Disk Bebas**: Minimal 1 GB.
- **Hak Akses**: Standard User (tidak membutuhkan hak Administrator).

### Untuk Membangun dari Sumber (Developer):
- **Rust Toolchain**: Versi 1.85+ (Edition 2024), target `x86_64-pc-windows-msvc`.
- **PowerShell**: Windows PowerShell 5.1+ atau PowerShell 7+ (`pwsh`).
- **Git for Windows**.

---

## ⚙️ Opsi Baris Perintah CLI (Tingkat Lanjut)

Untuk administrator yang ingin mengotomasi instalasi atau menentukan direktori tertentu via terminal:

```bash
moodle_installer.exe [OPTIONS]
```

### Opsi Tersedia:
| Flag | Parameter | Deskripsi |
| :--- | :--- | :--- |
| `-d`, `--install-dir` | `<PATH>` | Menentukan lokasi direktori target instalasi kustom (default: `./runtime`). |
| `-t`, `--target` | `<PATH>` | Alias untuk `--install-dir`. |
| `--pack-container` | `<ZIP> <PAK>` | Mengemas berkas `.zip` biasa menjadi wadah biner terenkripsi anti-tamper `.pak`. |
| `--verify-container` | `<PAK>` | Memverifikasi tanda tangan header dan keaslian checksum SHA-256 wadah `.pak`. |
| `-h`, `--help` | - | Menampilkan petunjuk opsi penggunaan CLI. |

### Contoh Penggunaan:
```powershell
# Menentukan folder instalasi di drive D:
.\moodle_installer.exe --install-dir "D:\LMS_Sekolah"

# Menggunakan variabel lingkungan:
$env:MOODLE_INSTALL_DIR = "E:\PortableLMS"
.\moodle_installer.exe

# Memverifikasi integritas payload.pak:
.\moodle_installer.exe --verify-container "payload.pak"
```

---

## 🛠️ Panduan Pengembang (Build from Source)

Jika Anda adalah seorang pengembang yang ingin mengompilasi biner sendiri:

### 1. Kloning Repositori
```powershell
git clone https://github.com/cybertank378/moodle_portable.git
cd moodle_installer
```

### 2. Rakit `payload.pak` Lengkap
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\build_payload.ps1
```
Skrip ini akan mengunduh paket resmi WNMP, menyusun konfigurasi `php.ini`, melakukan pre-inisialisasi MariaDB sistem, memvalidasi 24 titik kelengkapan modul, dan mengemasnya ke dalam wadah terenkripsi `payload.pak` (209.1 MB).

### 3. Kompilasi Executable Rust
```powershell
cargo build --release
```
Biner hasil kompilasi akan berada di: `target\x86_64-pc-windows-msvc\release\moodle_installer.exe`.

### 4. Menjalankan Pengujian (Testing)
```powershell
cargo test
```
Seluruh 62 unit test dipastikan lulus 100% hijau.

---

## 📂 Struktur Kode Sumber & Lisensi

```text
moodle_installer/
├── scripts/
│   ├── build_payload.ps1    # Skrip builder otomatis WNMP, pre-init MariaDB, audit kelengkapan, & generator .pak
│   └── prune_moodle.ps1     # Skrip pembersih berkas pengembang dan CI non-esensial Moodle
├── src/
│   ├── browser.rs           # Peluncur browser default otomatis
│   ├── container.rs         # Wadah biner terenkripsi AKSA .pak & anti-tamper SHA-256
│   ├── cron.rs              # In-process background cron job scheduler
│   ├── database.rs          # MariaDB bootstrap, my.ini generator, & TCP probe
│   ├── lib.rs               # Library root & embedded payload zip/pak extractor
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
├── app_icon.ico             # Ikon biner resmi aplikasi Windows PE
├── build.rs                 # Integrasi resource metadata Windows PE (winres)
├── Cargo.toml               # Konfigurasi dependensi proyek Rust (zip, ctrlc, sha2)
└── README.md                # Dokumentasi lengkap proyek
```

### Lisensi
Proyek ini dilisensikan di bawah ketentuan lisensi **GNU General Public License v3.0 (GPL-3.0)**.
Moodle adalah merek dagang terdaftar milik Moodle Pty Ltd.
