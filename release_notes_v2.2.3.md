# Aksaventra Portable Engine v2.2.3 (LMS Portable 64-bit)

Selamat datang di rilis **Aksaventra Portable Engine v2.2.3**!

Rilis ini membawa penyempurnaan utama:
1. **Portabilitas Penuh & Penghapusan Penguncian HWID**: Engine dan token lisensi kini mendukung pemindahan media penyimpanan portabel (Flashdisk / SSD Eksternal) ke berbagai PC host / server tanpa pembatasan hardware mismatch.
2. **Isolasi Penuh Stack & Dynamic Paths Synchronization**: Penyesuaian terpadu path dinamis dan isolasi proses untuk Nginx, MariaDB, PHP-CGI, dan Moodle.
3. **Pembersihan Database & Optimasi CBT Moodle**: Pembersihan subplugin usang (`forumreport_summary`) dan penonaktifan fitur latar belakang yang berat untuk kestabilan ujian.
4. **Penyatuan Endpoint API via `AKSAVENTRA_API_URL`**: Single source of truth untuk seluruh endpoint lisensi dan auto-updater.
5. **Pelaporan Client Public IP Logging**: Deteksi dan pengiriman IP publik klien secara otomatis untuk keperluan logging dan audit dashboard.
6. **In-Engine Autonomous Auto-Updater**: Mekanisme streaming download dan hot-swap biner otomatis.

---

## 📦 Berkas Distribusi Resmi (Assets)
- **`aksaventra-portable-v2.2.3-windows-x64.zip`**: Paket arsip portabel siap pakai (mencakup engine v2.2.3, brankas `.pak`, lisensi `.lic`, dan README).
- **`aksaventra_engine.exe`**: Biner eksekusi mandiri v2.2.3 (64-bit).
- **`aksaventra_vault.pak`**: Brankas kontainer aplikasi terenkripsi (AES-256-GCM + Zstd).

### 🔑 Checksum Integritas Biner (SHA-256)
- **`aksaventra_engine.exe`**: `75136ade94bdf417d154062d3c0e9d827aefce179d93ff156089ab2c5431f76b`
- **`aksaventra_vault.pak`**: `5e140423e9f3af071d27ffbd2387f7e81c4d7289d3523f3f3a5e8e63dc806857`

---

## 🚀 Cara Menjalankan
1. Unduh dan ekstrak `aksaventra-portable-v2.2.3-windows-x64.zip` ke folder tujuan (misal `D:\AksaventraLMS` atau USB Flashdisk).
2. Klik 2 kali file **`aksaventra_engine.exe`**.
3. Sistem otomatis menerapkan profil hardware terbaik, memeriksa pembaruan, membuka brankas, menyalakan web server, dan membuka browser LMS Anda secara otomatis!

