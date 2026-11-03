# ⚠️ Backend Issues, Bottlenecks & Engineering Mitigations (`mod_cbt`)

## ISSUE-PHP-01: Database Connection Exhaustion saat "Submit" Massal di Akhir Waktu
- **Skenario:** 600 siswa kehabisan waktu di detik yang sama, menyebabkan 600 *request* `mod_cbt_submit_final_attempt` masuk bersamaan dan memicu *deadlock* pada tabel `mdl_grade_grades`.
- **Solusi Teknis:**
  1. Pisahkan proses **Penutupan Sesi (`status = 'submitted'`)** dari proses **Push ke Moodle Gradebook**.
  2. Saat siswa menekan Submit (atau waktu habis), `mod_cbt` hanya menghitung skor dari *in-memory Answer Key* dan menyimpannya ke `mdl_cbt_attempts.sumgrades`.
  3. Sinkronisasi ke `mdl_grade_grades` dilakukan secara bertahap di latar belakang menggunakan **Moodle Adhoc Task (`\core\task\adhoc_task`)**.

## ISSUE-PHP-02: URL File Gambar/Audio Soal (`pluginfile.php`) Gagal Dimuat di Next.js karena Cookie Session
- **Solusi Teknis:** Saat proses **Build Exam Snapshot**, konversi seluruh gambar soal menjadi URL `tokenpluginfile.php` atau *Static Asset Copy* sehingga gambar dimuat secara instan oleh *browser* tanpa perlu memeriksa sesi *database* Moodle.

## ISSUE-PHP-03: Manipulasi Urutan & Kunci Jawaban melalui Inspect Network
- **Solusi Teknis:** DTO pada `get_exam_manifest.php` melakukan *stripping* mutlak terhadap seluruh properti penilaian. Kunci jawaban hanya disimpan di sisi server pada *cache* privat `mod_cbt/answerkeys`.
