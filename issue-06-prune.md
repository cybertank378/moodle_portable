# Issue 06: Implementasi Pemangkasan Modul & Plugin Moodle (`prune.rs`)

**Label:** `optimization`, `performance`, `cleanup`  
**Bergantung pada:** Issue 01  

## Deskripsi
Karena Moodle ditujukan untuk penggunaan portabel (yang sering kali didistribusikan via *flashdisk* atau dijalankan pada perangkat dengan sumber daya terbatas), modul ini bertugas untuk menghapus *addon*, tema bawaan yang tidak terpakai, dokumentasi, file uji coba (*tests*), serta modul pihak ketiga yang berat untuk menekan ukuran file (*payload size*) dan meringankan penggunaan RAM/CPU.

## Task List
- [ ] Mengembangkan modul `src/prune.rs` untuk memindai dan menghapus folder/berkas yang tidak esensial di dalam direktori `moodle/`.
- [ ] Menghapus folder pengujian (*unit tests*) yang memakan ruang penyimpanan besar:
  - `moodle/**/tests/`
- [ ] Menghapus tema bawaan Moodle selain tema utama yang digunakan (misalnya mempertahankan `boost` dan `classic`, serta menghapus tema lama/tidak terpakai untuk menghemat ruang).
- [ ] Menghapus dokumentasi pengembang, file `README.txt`, lisensi ganda, dan file teks non-esensial dari sub-direktori `moodle/`.
- [ ] Menghapus modul aktivitas/plugin bawaan Moodle yang jarang atau tidak pernah digunakan dalam pembelajaran standar (opsional/dapat dikonfigurasi melalui *whitelist*).
- [ ] Mengintegrasikan pemanggilan fungsi pemangkasan (`prune_moodle_directory`) ke dalam alur proses *packaging* awal saat developer menjalankan perintah `--encrypt` / `--pack`.