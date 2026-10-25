# Issue 03: Implementasi Kontainer Enkripsi Terproteksi (`container.rs`)

**Label:** `security`, `encryption`, `core`  
**Bergantung pada:** Issue 02  

## Deskripsi
Membangun mekanisme *seal* (enkripsi) dan *unseal* (dekripsi) untuk 5 folder utama (`nginx`, `php`, `moodle`, `moodledata`, `mariadb`) menggunakan AES-256-GCM dan kompresi Zstd.

## Task List
- [x] Mendefinisikan konstanta 5 folder wajib: `["nginx", "php", "moodle", "moodledata", "mariadb"]`.
- [x] Mengimplementasikan fungsi `encrypt_all` (Seal):
  - Mengarsipkan folder dengan `tar` dan mengompresinya dengan `zstd`.
  - Mengenkripsi data terkompresi menggunakan AES-256-GCM (12-byte Nonce + Ciphertext).
  - Menyimpan hasil enkripsi ke dalam folder tersembunyi `.vault/<nama_folder>.pak` atau brankas terpadu.
  - Menghapus folder plain-text asli setelah enkripsi sukses.
- [x] Mengimplementasikan fungsi `decrypt_all` (Unseal):
  - Membaca file `.vault/<nama_folder>.pak` atau brankas terpadu.
  - Mendekripsi isi file menggunakan kunci master dari modul `security.rs`.
  - Mengekstrak kembali arsip ke direktori kerja aplikasi.
- [x] Menerapkan atribut Windows (`FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM`) pada folder runtime agar terlindung dari akses/modifikasi manual pengguna.