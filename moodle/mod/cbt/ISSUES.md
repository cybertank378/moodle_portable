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

## ISSUE-CBT-04: Ketiadaan Sinkronisasi Data Dua Arah (Two-Way Data Sync) antara Moodle LMS dan Aksaventra CBT
- **GitHub References:** [cybertank378/aksaventra-cbt#48](https://github.com/cybertank378/aksaventra-cbt/issues/48), [cybertank378/moodle_portable#71](https://github.com/cybertank378/moodle_portable/issues/71)
- **Skenario:** Data master yang sudah dibuat di Moodle (Siswa `mdl_user`, Kelas/Cohort `mdl_cohort`, Mata Pelajaran `mdl_course`, Bank Soal `mdl_question`, dan Ujian CBT `mdl_cbt`) tidak muncul sama sekali di Dashboard Aksaventra CBT (Next.js 16). Sebaliknya, data yang diinputkan dari antarmuka Next.js CBT tidak tersimpan ke database Moodle.
- **Akar Masalah:**
  1. **Dual-Database Disconnection:** Moodle menggunakan database `moodle` sedangkan Next.js menggunakan database `aksaventra_cbt` melalui Prisma ORM.
  2. **Isolated Repositories di Next.js:** `ClassesRepository`, `ExamRepository`, dan `QuestionsRepository` di Next.js hanya membaca/menulis tabel Prisma secara lokal tanpa memanggil WebService Moodle (`mod_cbt_*`).
  3. **Ketiadaan Trigger/Observer di Moodle:** Moodle belum memiliki hook/observer event (`\core\event\*`) untuk mendeteksi penambahan siswa/kelas/soal baru dan mengirimkannya ke Aksaventra CBT.
- **Solusi Teknis Arsitektur Sinkronisasi Dua Arah:**
  1. **Arah 1 (Moodle ➡️ Aksaventra CBT):**
     - Sediakan modul sinkronisasi otomatis di engine / REST Web Service (`mod_cbt_sync_all` atau sinkronisasi terjadwal via cron daemon Rust).
     - Engine Rust atau Next.js API secara berkala / saat startup memetakan data dari Moodle:
       - `mdl_cohort` ➡️ `ClassGroup` (moodleId, name, code)
       - `mdl_user` (role student) ➡️ `User` (moodleUserId, username, fullName, className, classGroupId, passwordHash)
       - `mdl_course` & `mdl_cbt` ➡️ `Exam` (moodleCbtId, title, courseName, durationSeconds, maxStrikes, activeToken, questionsJson, answerKeysJson)
       - `mdl_question` ➡️ `QuestionItem` / Bank Soal CBT
  2. **Arah 2 (Aksaventra CBT ➡️ Moodle):**
     - Perbarui Repositories di Next.js (`ClassesRepository`, `ExamRepository`, `QuestionsRepository`) agar saat melakukan mutasi (create/update/delete) juga memanggil WebService Moodle (`mod_cbt_manage_groups`, `mod_cbt_manage_schedule`, dll.) via `callMoodleApi`.
     - Data baru yang diinput di Next.js otomatis tersimpan ke `mdl_*` di Moodle secara konsisten.
  3. **Auto-Reconcile Background Worker:**
     - Menjalankan sinkronisasi data master otomatis saat engine startup dan secara periodik di background thread Rust untuk menjamin konsistensi ACID antara kedua database.
