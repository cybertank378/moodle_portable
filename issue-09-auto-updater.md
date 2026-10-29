# Issue 09: Implementasi Sistem Auto-Updater Mesin Portabel (`updater.rs`)

**Label:** `updater`, `network`, `feature`  
**Bergantung pada:** Issue 02 (`security.rs`), Aksaventra Dashboard Updater API  
**Repositori Terkait:** [cybertank378/aksaventra_dashboard](https://github.com/cybertank378/aksaventra_dashboard)  

## 1. Deskripsi
Mengembangkan modul auto-updater mandiri di dalam engine (`src/updater.rs`) agar pengguna akhir (guru, proktor, administrator sekolah) tidak perlu mengunduh ulang seluruh arsip rilis zip secara manual setiap kali ada perbaikan bug atau rilis versi baru.

Engine akan secara otomatis melakukan handshake pengecekan versi ke endpoint server `aksaventra.ai.id` saat startup. Jika server mendeteksi versi mesin klien lebih rendah dari `latest_version`, engine akan mengunduh biner `aksaventra_engine.exe` terbaru secara atomik, memverifikasi integritas checksum SHA-256, dan memperbarui dirinya sendiri dengan mulus (*seamless update*).

---

## 2. Alur Kerja (Workflow)
```text
[Engine Startup]
       │
       ▼
[Cek Koneksi & Handshake Updater API] ──(Offline / Timeout)──► [Lanjut Eksekusi Versi Saat Ini]
       │ (200 OK)
       ▼
[Bandingkan Versi SemVer: Current vs Latest]
       │
       ├──(Versi Sama / Up-to-date)─────────────────────────► [Lanjut Eksekusi Normal]
       │
       ▼ (Versi Lebih Baru Tersedia)
[Tampilkan Dialog / Banner Update & Progress Bar]
       │
       ▼
[Streaming Download Biner Baru ke 'aksaventra_engine.tmp']
       │
       ▼
[Verifikasi Checksum SHA-256] ──(Checksum Mismatch)────────► [Hapus .tmp & Batalkan Update]
       │ (Valid)
       ▼
[Atomic Hot-Swap Binary Windows]
 (Rename self -> aksaventra_engine.old, Rename .tmp -> aksaventra_engine.exe)
       │
       ▼
[Restart Process / Spawn Binary Baru & Exit]
```

---

## 3. Spesifikasi Kontrak Payload API (`aksaventra.ai.id`)
- **Endpoint:** `GET /api/v1/updater/version` (atau `POST /api/v1/updater/check`)
- **Query / Body Parameter:**
  ```json
  {
    "client_version": "v2.2.1",
    "hwid": "1caee749-5928-4c89-a052-ca4f27af8c41",
    "serial_key": "AKSA-ENTERPRISE-2026-9F2A",
    "channel": "stable"
  }
  ```
- **Respon Server (JSON):**
  ```json
  {
    "status": "success",
    "has_update": true,
    "latest_version": "v2.2.2",
    "min_supported_version": "v2.0.0",
    "mandatory": false,
    "download_url": "https://aksaventra.ai.id/api/v1/updater/download/aksaventra_engine.exe",
    "checksum_sha256": "4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945",
    "release_notes": "Pembaruan penanganan lisensi otomatis dan perbaikan stabilitas vault.",
    "published_at": 1824792000
  }
  ```

---

## 4. Task List Implementasi (Engine Klien)
- [x] Buat modul baru `src/updater.rs` dan daftarkan di `src/main.rs`.
- [x] Implementasikan fungsi `check_for_updates(&client, current_version, api_url)` dengan timeout singkat (maksimal 3-5 detik) agar tidak menghambat startup offline.
- [x] Tambahkan pembanding versi Semantic Versioning (*SemVer comparison* `vX.Y.Z`).
- [x] Implementasikan pengunduhan biner `download_update_binary()` menggunakan `reqwest` stream dengan progress bar interaktif (`indicatif`).
- [x] Validasi integritas biner yang diunduh dengan algoritma hashing `sha2::Sha256` terhadap `checksum_sha256` dari server.
- [x] Implementasikan strategi penggantian biner atomik pada Windows:
  - Menggunakan teknik `self-replace` / rename biner aktif menjadi `aksaventra_engine.old` lalu menamai biner baru menjadi `aksaventra_engine.exe`.
  - Pembersihan berkas `*.old` otomatis saat startup berikutnya.
- [x] Berikan opsi flag CLI `--skip-update` atau `--check-update`.
- [x] Sediakan unit test untuk:
  - Parsing respon JSON updater.
  - Perbandingan versi SemVer.
  - Verifikasi checksum SHA-256.

