# Aksaventra Portable Engine v2.2.2 (LMS Portable 64-bit)

Selamat datang di rilis **Aksaventra Portable Engine v2.2.2**!

Rilis ini membawa fitur utama **Auto-Updater System** berbasis server `aksaventra.ai.id`, penyelarasan kontrak payload validasi lisensi JSON, serta mekanisme *vault fallback key resolution* untuk memastikan kelancaran dekripsi brankas.

---

## 🌟 Sorotan Pembaruan (What's New in v2.2.2)

### 1. 🔄 Sistem Pembaruan Otomatis (In-Engine Auto-Updater)
- **Seamless Atomic Hot-Swap**: Engine kini dapat memperbarui biner dirinya sendiri secara otomatis saat startup tanpa perlu pengguna mengunduh ulang berkas zip rilis secara manual.
- **Fail-Open & Non-Blocking**: Pengecekan versi dilakukan dengan batas timeout 4 detik. Jika server offline atau komputer tidak memiliki koneksi internet, engine langsung melanjutkan booting tanpa jeda.
- **Streaming Download & Progress Bar**: Pengunduhan biner versi baru dilakukan secara streaming disertai indikator kemajuan (*progress bar*) real-time.
- **Validasi Integritas SHA-256**: Setiap biner pembaruan diverifikasi menggunakan hash SHA-256 sebelum diterapkan untuk menjamin integritas dan keamanan dari berkas korup.
- **Opsi CLI Baru**:
  - `--check-update`: Memeriksa ketersediaan pembaruan mesin tanpa menyalakan server.
  - `--skip-update`: Melewati pengecekan pembaruan otomatis (dapat juga diset via environment variable `AKSAVENTRA_SKIP_UPDATE=1`).

### 2. 🛡️ Penyelarasan Format JSON Lisensi & Vault Fallback Key
- **Payload Request Terstandarisasi**: Mesin otomatis menyertakan `serial_key`, `hwid`, dan `engine_version` (`v2.2.2`) saat validasi ke server lisensi.
- **Respon Server Lengkap**: Parser mendukung field `valid`, `message`, `client_name`, `vault_secret`, dan `expires_at` (dengan parser timestamp fleksibel).
- **Fallback Master Key Resolution**: Menjamin kontainer `aksaventra_vault.pak` awal langsung dapat didekripsi dan kemudian disegel kembali menggunakan kunci resmi perangkat saat aplikasi dimatikan (`Ctrl+C`).

---

## 📦 Berkas Distribusi (Assets)
- **`aksaventra-portable-v2.2.2-windows-x64.zip`**: Paket arsip portabel siap pakai yang mencakup:
  - 🚀 `aksaventra_engine.exe` (Engine v2.2.2 terkompilasi biner asli Rust 64-bit)
  - 📦 `aksaventra_vault.pak` (Brankas kontainer terenkripsi AES-256-GCM + Zstandard)
  - 🔑 `serial_key.lic` (Template berkas lisensi resmi)
  - 📖 `README.md` (Panduan pengguna lengkap)
- **`aksaventra_engine.exe`**: Biner mandiri terpisah v2.2.2.

---

## 🚀 Cara Menjalankan
1. Unduh dan ekstrak `aksaventra-portable-v2.2.2-windows-x64.zip` ke folder tujuan (misal `D:\AksaventraLMS` atau USB Flashdisk).
2. Klik 2 kali file **`aksaventra_engine.exe`**.
3. Sistem otomatis menerapkan profil hardware terbaik, memeriksa pembaruan, membuka brankas, menyalakan web server, dan membuka browser LMS Anda secara otomatis!

