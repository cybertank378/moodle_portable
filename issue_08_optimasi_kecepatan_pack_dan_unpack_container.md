# Issue 08: Optimasi Ekstrem Proses Pack & Unpack pada Modul Container (`src/container.rs`)

**Status:** Open  
**Prioritas:** High (Performance & User Experience)  
**Komponen Terkait:** `src/container.rs`, `Cargo.toml`  
**Referensi:** [Optimizing Rust Programs for Speed](https://blog.cubed.run/optimizing-rust-programs-for-speed-ccacf3785174)

---

## 1. Latar Belakang & Masalah (Problem Statement)
Distribusi **Moodle Portable** mencakup puluhan ribu file berukuran kecil (terutama skrip `.php` pada direktori `moodle/`, `php/`, serta file sistem `mariadb/` dan `nginx/`). Saat ini, proses *packing* (pengarsipan/kompresi) dan *unpacking* (ekstraksi) pada `src/container.rs` masih menjadi *bottleneck* utama saat inisialisasi maupun penutupan aplikasi.

Penyebab utama lambatnya proses ini di lingkungan Windows (NTFS) meliputi:
1. **Eksekusi Sekuensial (*Single-Threaded*):** Pemindaian direktori, kompresi/dekompresi, dan penulisan file ke disk masih berjalan secara berurutan satu per satu.
2. **Overhead Sistem File Windows (NTFS & Windows Defender):** Membuat puluhan ribu *file handle* secara berurutan memicu *overhead* metadata NTFS dan pemindaian *real-time* dari Windows Defender pada setiap file.
3. **Kapasitas Buffer I/O Standar:** Penggunaan buffer standar (`8 KB` pada `BufReader`/`BufWriter` bawaan Rust) terlalu kecil untuk operasi I/O masif dan memicu terlalu banyak *system call*.
4. **Belum Maksimalnya Optimasi Kompilator & Memori:** Alokasi memori dinamis yang berulang tanpa *pre-allocation* (`Vec::with_capacity`) serta konfigurasi `[profile.release]` yang belum dioptimalkan penuh untuk kecepatan eksekusi.

---

## 2. Tujuan (Objectives)
* Memangkas waktu proses **Pack** dan **Unpack** hingga **3x–10x lebih cepat** dibandingkan implementasi saat ini.
* Memaksimalkan utilisasi seluruh *core* CPU (*multithreading*) baik saat kompresi, dekompresi, maupun operasi I/O disk.
* Meminimalkan *overhead* alokasi memori dan *system call* pada sistem operasi Windows.

---

## 3. Rencana Solusi & Opsi Arsitektur (Proposed Solutions)

### Fase 1: Optimasi Algoritma Kompresi & Multithreading
* [ ] **Ganti/Upgrade Engine Kompresi ke `zstd` atau `lz4_flex`:**
  * Gunakan **`zstd` (Zstandard)** dengan fitur *multithreaded encoder* (`encoder.multithread(num_cpus)`) untuk rasio kompresi tinggi dengan kecepatan dekompresi ekstrem.
  * *Opsi alternatif:* Gunakan **`lz4_flex`** jika memprioritaskan kecepatan ekstraksi instan di atas ukuran file.
* [ ] **Parallel Directory Traversal saat *Pack*:**
  * Ganti pemindaian direktori sekuensial (`walkdir` standar) dengan **`jwalk`** atau **`ignore`** agar pembacaan metadata ribuan file Moodle berjalan secara paralel.

### Fase 2: Optimasi I/O & Ekstraksi Paralel (*Parallel Unpack*)
* [ ] **Pre-Creation Struktur Direktori:**
  * Pisahkan pembuatan folder dan penulisan file. Kumpulkan seluruh *path* direktori terlebih dahulu dan jalankan `fs::create_dir_all` di awal sebelum mengekstrak file, sehingga *worker thread* tidak perlu mengecek keberadaan folder berulang kali.
* [ ] **Ekstraksi File Paralel dengan `rayon`:**
  * Gunakan indeks kontainer / *chunking* agar ribuan file kecil dapat didekompresi dan ditulis ke disk secara paralel menggunakan `rayon` (`par_iter`).
* [ ] **Implementasi *Memory-Mapped I/O* (`memmap2`) & Large Buffer:**
  * Gunakan `memmap2::Mmap` saat membaca file kontainer berukuran besar untuk memangkas *overhead* `read()` syscall.
  * Tingkatkan ukuran `BufReader` dan `BufWriter` dari default `8 KB` menjadi **`4 MB` – `8 MB`** (`BufWriter::with_capacity(4 * 1024 * 1024, file)`).

### Fase 3: Optimasi Kode Rust & Compiler (`Cargo.toml`)
Berdasarkan praktik terbaik optimasi kecepatan Rust:
* [ ] **Pre-Alokasi Memori Buffer:** Gunakan `Vec::with_capacity()` untuk semua buffer pembacaan file dan penampungan *entry* agar tidak terjadi realokasi heap berulang kali.
* [ ] **Tuning `[profile.release]` pada `Cargo.toml`:**
  ```toml
  [profile.release]
  opt-level = 3           # Optimasi kecepatan maksimal
  lto = "fat"             # Link-Time Optimization lintas seluruh crate
  codegen-units = 1       # Memaksimalkan inlining oleh LLVM
  panic = "abort"         # Menghilangkan overhead stack unwinding
  strip = true            # Memperkecil ukuran binary akhir
  ```
* [ ] **Target CPU Native / SIMD (Opsional):** Aktifkan instruksi `target-cpu=native` atau AVX2 pada `.cargo/config.toml` untuk mempercepat algoritma kompresi dan *hashing/encryption*.

### Fase 4: Eksplorasi Arsitektur *Zero-Unpack* (Jangka Panjang)
* [ ] **Evaluasi VHDX Mounting / Virtual File System:**
  * Mengingat `moodle/`, `php/`, dan `nginx/` sebagian besar bersifat *read-only*, evaluasi opsi menyimpan komponen tersebut dalam image virtual (`.vhdx` atau arsip terindeks) yang dapat di-*mount* secara instan (< 1 detik) tanpa perlu mengekstrak 50.000+ file ke disk fisik setiap kali dijalankan.

---

## 4. Kriteria Keberhasilan (Acceptance Criteria)
1. Proses *pack* dan *unpack* tetap menjaga integritas data 100% (tidak ada file yang korup atau tertinggal) dan kompatibel dengan modul keamanan (`src/security.rs`).
2. Penggunaan CPU saat proses *pack* dan *unpack* terdistribusi merata di semua *core* yang tersedia.
3. Terdapat peningkatan waktu eksekusi yang terukur (melalui *benchmark* / *timer log*) minimal **50%–80% lebih cepat** dibandingkan versi sebelumnya.