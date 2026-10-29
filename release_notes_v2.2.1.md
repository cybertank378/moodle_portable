# Aksaventra Portable Engine v2.2.1 (LMS Portable 64-bit)

Selamat datang di rilis **Aksaventra Portable Engine v2.2.1**! 

Pembaruan ini menambahkan **Auto-Hardware Recommendation Engine** (otomatis menyesuaikan performa hardware komputer saat di-klik 2 kali), **Validasi Umur Lisensi Maksimal 1 Tahun** dengan notifikasi peringatan kadaluarsa, serta penyempurnaan stabilitas konkurensi.

---

## 🌟 Sorotan Pembaruan (What's New in v2.2.1)

### 1. ⚡ Auto Hardware Profiling on Double-Click (Zero Configuration)
- **Deteksi Spesifikasi Host Otomatis**: Saat pengguna mengklik 2 kali `aksaventra_engine.exe`, engine langsung mendeteksi jumlah Core CPU dan total RAM fisik komputer host sebelum proses ekstraksi/unpack dimulai.
- **Auto-Tuning Tanpa Input**:
  - **`Eco`** (≤4GB RAM / Dual-Core): 8 Workers, 128M DB Buffer, 1024 Nginx Connections (Estimasi: 25 - 50 Siswa Ujian).
  - **`Balanced`** (8GB RAM / Quad-Core): 16 Workers, 256M DB Buffer, 2048 Nginx Connections (Estimasi: 50 - 100 Siswa Ujian).
  - **`High Performance`** (16GB RAM / 8-Core): 32 Workers, 1024M DB Buffer, 4096 Nginx Connections (Estimasi: 150 - 250 Siswa Ujian).
  - **`Extreme`** (≥32GB RAM / 12+ Core): 64 Workers, 2048M DB Buffer, 8192 Nginx Connections (Estimasi: 300 - 500+ Siswa Ujian).
- **Opsi Kustomisasi Manual (Opsional)**: Teknisi/sysadmin tetap dapat meng-override profil menggunakan CLI parameter `-c / --workers <JUMLAH>` atau `--profile <nama_profil>`.

### 2. 🔑 Validasi Umur Lisensi Maksimal 1 Tahun & Notifikasi Expired
- **Batas Maksimal 365 Hari**: Lisensi lokal dienkripsi dan dibatasi maksimal 1 tahun sejak tanggal aktivasi pertama kali (`now + 365 hari`).
- **Pengecekan Offline Rutin**: Setiap kali aplikasi dibuka, engine memvalidasi umur token dan menampilkan sisa hari aktif lisensi.
- **Notifikasi Expired Jelas**: Jika masa aktif telah berakhir, engine menampilkan banner peringatan bahwa lisensi telah kadaluarsa dan mengarahkan pengguna ke portal resmi `https://aksaventra.ai.id`.

### 3. 📂 100% Dynamic Relative Paths & Zero 503 FastCGI
- Bebas dari hardcoded absolute path; `extension_dir = "ext"` dan `$CFG->dataroot = dirname(__DIR__) . '/moodledata'` menjamin aplikasi dapat berjalan dari drive atau folder mana pun.
- Deteksi IP LAN host otomatis (`get_active_local_ip`) sehingga siswa dapat langsung mengakses via Wi-Fi/LAN serentak tanpa looping redirect.

### 4. 🛡️ JSON Payload Lisensi & Vault Fallback Key Resolution
- **Payload Request & Respon Terstandarisasi**: Engine otomatis mengirimkan `serial_key`, `hwid`, dan `engine_version` (`vX.Y.Z`) ke server lisensi, serta mendukung penuh struktur respon `valid`, `message`, `client_name`, `vault_secret`, dan `expires_at`.
- **Seamless Vault Decryption**: Mendukung resolusi fallback master key untuk memastikan kontainer brankas awal langsung dapat dibuka dan otomatis dienkripsi kembali menggunakan kunci lisensi instansi saat shutdown.

---

## 📦 Berkas Distribusi (Assets)
- **`aksaventra-portable-v2.2.1-windows-x64.zip`**: Paket arsip portabel siap pakai yang mencakup:
  - 🚀 `aksaventra_engine.exe` (Engine v2.2.1 terkompilasi biner asli Rust 64-bit)
  - 📦 `aksaventra_vault.pak` (Brankas kontainer terenkripsi AES-256-GCM + Zstandard)
  - 🔑 `serial_key.lic` (Template berkas lisensi resmi)
  - 📖 `README.md` (Panduan pengguna lengkap)
- **`aksaventra_engine.exe`**: Biner mandiri terpisah.

---

## 🚀 Cara Menjalankan
1. Ekstrak `aksaventra-portable-v2.2.1-windows-x64.zip` ke folder tujuan (misal `D:\AksaventraLMS` atau USB Flashdisk).
2. Klik 2 kali file **`aksaventra_engine.exe`**.
3. Sistem otomatis menerapkan profil hardware terbaik, membuka brankas, menyalakan web server, dan membuka browser LMS Anda secara otomatis!
