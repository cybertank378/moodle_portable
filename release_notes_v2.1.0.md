# Aksaventra Portable Engine v2.1.0 Release Notes

**Aksaventra Portable Engine v2.1.0** menghadirkan pembaruan besar pada lapisan keamanan otentikasi lisensi, integrasi API live dengan sistem manajemen lisensi `aksaventra.ai.id`, pengikatan perangkat keras (*Hardware ID Locking*), penyimpanan *Signed Local Token* terenkripsi untuk eksekusi offline yang aman, serta penghapusan total mode demo offline.

---

## 🚀 Fitur Baru & Peningkatan Utama

### 1. Integrasi Validasi Lisensi Live API (`/api/v1/validate`)
- Endpoint validasi utama diarahkan ke `https://aksaventra.ai.id/api/v1/validate` dengan dukungan *fallback* otomatis ke endpoint cadangan (`/api/v1/validate-key` dan `/api/v1/engine/validate`).
- Payload menyertakan `serial_key`, `hwid`, dan `engine_version` (`2.1.0`).
- Mendukung override dinamis melalui *environment variable* `AKSAVENTRA_API_URL` untuk kebutuhan pengujian lokal staging/development.

### 2. Pengikatan Perangkat Keras (*Hardware ID / HWID Locking*)
- Setiap aktivasi lisensi baru akan secara otomatis mengikat Serial Key dengan Machine ID lokal (`HWID`).
- Jika serial key yang sama digunakan pada mesin/perangkat keras lain tanpa reset otorisasi, engine akan menerima `HTTP 403 Forbidden: Hardware Mismatch` dan memblokir pembukaan aplikasi.

### 3. Berkas Lisensi Terenkripsi Lokal (*Signed Encrypted Token*)
- Setelah validasi online pertama kali berhasil (HTTP 200 OK), engine menyimpan berkas token terenkripsi lokal **`aksaventra.token`**.
- Token dienkripsi menggunakan **AES-256-GCM** dengan kunci turunan dari HWID mesin lokal (`derive_key(HWID, SECRET_SALT)`).
- Dilengkapi dengan *cryptographic signature* (SHA-256) untuk mencegah *tampering* atau manipulasi data lisensi.
- Pada peluncuran berikutnya (*Subsequent Launches*), engine membaca dan memverifikasi token secara lokal (**Valid Offline / Cached Mode**) sehingga aplikasi dapat digunakan tanpa koneksi internet berkelanjutan.

### 4. Penghapusan Menyeluruh Mode Demo (*Demo Mode Elimination*)
- Menghapus seluruh bypass demo offline (`DEMO-AKSAVENTRA-2026`) dan konstanta rahasia demo dari biner engine.
- Seluruh aktivasi mewajibkan serial key resmi dari penyedia layanan `https://aksaventra.ai.id`.

### 5. Penanganan Respon Server yang Spesifik & Informatif
- **HTTP 200 OK**: Lisensi terverifikasi, menampilkan nama instansi terdaftar, dan menyimpan cache token lokal.
- **HTTP 403**: Pesan jelas bahwa Serial Key terikat pada perangkat keras lain beserta instruksi reset lisensi.
- **HTTP 401 / 404**: Pesan jelas bahwa Serial Key tidak ditemukan, kadaluarsa, atau dinonaktifkan.

---

## 📦 Berkas Distribusi
- `aksaventra-portable-v2.1.0-windows-x64.zip` (Paket kompresi lengkap siap pakai)
- `aksaventra_engine.exe` (Biner engine mandiri Windows 64-bit v2.1.0)
- `aksaventra_vault.pak` (Kontainer brankas terenkripsi AES-256-GCM + ZstdMT)
- `serial_key.lic` (Berkas lisensi perangkat)
