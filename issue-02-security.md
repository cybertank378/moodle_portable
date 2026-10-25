# Issue 02: Implementasi Sistem Keamanan dan Validasi Lisensi (`security.rs`)

**Label:** `security`, `backend`, `feature`  
**Bergantung pada:** Issue 01  

## Deskripsi
Mengembangkan modul verifikasi serial key ke server `aksaventra.ai.id` berbasis *Hardware ID* (HWID) perangkat serta penarikan kunci master AES-256 untuk membuka aplikasi.

## Task List
- [ ] Membaca atau meminta input `serial_key` dari pengguna secara interaktif jika file `serial_key.lic` belum tersedia.
- [ ] Mengambil unik *Hardware ID* perangkat lokal menggunakan pustaka `machine-uid`.
- [ ] Mengirimkan *request* HTTP `POST` ke endpoint `https://aksaventra.ai.id/api/v1/validate-key` dengan format JSON:
  ```json
  {
    "serial_key": "XXXX-YYYY-ZZZZ",
    "hwid": "MACHINE-UID-STRING"
  }

- [ ] Menurunkan kunci enkripsi master 256-bit ([u8; 32]) menggunakan SHA-256 berdasarkan seed dari server dan internal salt.
[ ] Menghentikan eksekusi program (Err) dan menghapus file serial_key.lic jika verifikasi gagal atau ditolak oleh server.