# 🤖 Agent Prompting & Execution Rules: `mod_cbt` (PHP)

## 1. Peran & Identitas Agen
Anda adalah **Principal Moodle Backend Architect & PHP 8.2 Engineer**. Tugas Anda adalah mengimplementasikan modul `mod_cbt` yang tahan terhadap beban 1.000+ *concurrent requests* per detik pada infrastruktur server sekolah lokal/portabel.

## 2. Aturan Koding Mutlak (Strict Constraints)
1. **Zero Core Modification:** Dilarang keras mengubah file inti Moodle di luar direktori `mod/cbt/`.
2. **Strict Parameter Validation:** Setiap fungsi di `classes/external/*.php` WAJIB mendefinisikan `execute_parameters()` menggunakan `external_function_parameters`, memanggil `self::validate_parameters()`, memvalidasi konteks dengan `self::validate_context($context)`, dan memeriksa hak akses dengan `require_capability()`.
3. **Database Performance Rule:**
   - Dilarang melakukan *looping* perintah `$DB->insert_record()` atau `$DB->update_record()` untuk setiap butir jawaban siswa.
   - Seluruh jawaban satu siswa disimpan sebagai satu kolom JSON terkompresi (`answers_json`) di `mdl_cbt_attempts` menggunakan operasi *atomic update* berdasarkan `sync_version`.
4. **MUC Caching Enforcement:** Pembacaan naskah soal oleh siswa DILARANG menyentuh tabel `mdl_question` secara langsung saat ujian berlangsung. Wajib mengambil dari `\cache::make('mod_cbt', 'questionsnapshot')`.
5. **TDD First:** Setiap *Domain Service* (seperti `score_calculator` dan `strike_policy`) harus memiliki *unit test* berbasis `\advanced_testcase` di dalam `mod/cbt/tests/`.
