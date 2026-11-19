# ISSUE-11: Pembaruan Kueri Skema Bank Soal Moodle 5 (`mdl_question_versions`) & Sinkronisasi Metadata Penilaian

- **Status:** Open
- **Prioritas:** High (Moodle 5.x Compatibility & Grading Integrity)
- **Terkait:** `cybertank378/aksaventra-cbt` (`ISSUE-13`)
- **File Terdampak:**
  - `moodle/mod/cbt/classes/external/sync_master.php`
  - `moodle/mod/cbt/classes/infrastructure/cache/muc_snapshot_store.php`
  - `moodle/mod/cbt/classes/domain/service/score_calculator.php`
  - `moodle/mod/cbt/cli/sync.php`

---

## 1. Latar Belakang & Akar Masalah

Pada **Moodle 4.x hingga Moodle 5.x**, arsitektur tabel Bank Soal telah direfaktor menggunakan sistem *versioning* (`mdl_question_bank_entries`, `mdl_question_versions`, dan `mdl_question_references`). Saat ini terdapat 4 masalah teknis pada `mod_cbt` saat berinteraksi dengan Moodle 5:

1. **Kueri `SELECT` pada `mdl_question` Mengambil Semua Versi Historis (Draft/Hidden/Versi Lama):**
   Di `sync_master.php` (`fetch_questions()`) dan `muc_snapshot_store.php` (`build_and_store_snapshots()`), kueri langsung membaca `FROM {question} WHERE parent = 0` tanpa melakukan `JOIN` ke `mdl_question_versions` untuk memfilter versi terbaru (`MAX(version)`) dan status aktif (`status = 'ready'`). Akibatnya, soal yang pernah diedit muncul ganda di CBT.
2. **`push_question` di `sync_master.php` Belum Membuat Entri `mdl_question_bank_entries` & `mdl_question_versions`:**
   Saat soal baru dikirim dari Next.js (`push_question`), `sync_master.php` hanya melakukan `insert_record('question', $q)`. Di Moodle 5, soal yang tidak memiliki baris di `mdl_question_bank_entries` dan `mdl_question_versions` **tidak akan tampil di UI Bank Soal Moodle**.
3. **Hilangnya Metadata Toleransi (`mdl_question_numerical`) dan Bobot Parsial saat `pull_questions`:**
   - Untuk tipe `numerical`, nilai toleransi disimpan di tabel `mdl_question_numerical` (kolom `tolerance`), bukan di `mdl_question`. Saat ini `fetch_questions()` belum mengambil nilai toleransi tersebut.
   - Untuk tipe `multichoice` dengan banyak jawaban benar (`mcma`), `upsert_question()` menyetel `fraction = 1.0` untuk setiap opsi benar, padahal aturan baku Moodle mewajibkan total fraksi semua opsi benar berjumlah **`1.0`** (misal: 2 jawaban benar masing-masing `0.5` atau `1 / count($correctOptions)`) dan opsi salah diberi fraksi negatif `-1 / count($correctOptions)`.
4. **Sinkronisasi `cli/sync.php` Belum Menyamakan `points` (`defaultmark`) dan `tolerance`:**
   Skrip jembatan database langsung (`cli/sync.php`) perlu menyertakan kolom `defaultmark` ke `Question.points` serta mengambil `tolerance` dari `mdl_question_numerical`.

---

## 2. Spesifikasi Perubahan Teknis (`moodle_portable`)

### A. Perbarui Kueri Pengambilan Soal Moodle 5 (`sync_master.php` & `muc_snapshot_store.php`)
Gunakan kueri standar Moodle 5 untuk mengambil **hanya versi terbaru berstatus `ready`**:
```sql
SELECT q.id, q.qtype, q.name, q.questiontext, q.questiontextformat,
       q.defaultmark, q.generalfeedback, qbe.idnumber AS code
  FROM {question} q
  JOIN {question_versions} qv ON qv.questionid = q.id
  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
 WHERE q.parent = 0
   AND qv.status = 'ready'
   AND qv.version = (
       SELECT MAX(v2.version)
         FROM {question_versions} v2
        WHERE v2.questionbankentryid = qbe.id
   )
ORDER BY q.id DESC
```

### B. Lengkapi Registrasi Bank Soal Moodle 5 pada `sync_master::upsert_question()`
Saat membuat soal baru dari Next.js ke Moodle 5:
1. Cari atau buat kategori bank soal default pada konteks kursus (`question_get_default_category($context->id, true)`).
2. Insert record ke `{question_bank_entries}` (`questioncategoryid`, `idnumber = $code`, `ownerid`).
3. Insert record ke `{question}` dan hubungkan melalui `{question_versions}` (`questionbankentryid`, `version = 1`, `questionid`, `status = 'ready'`).
4. Sesuaikan distribusi `fraction` pada `{question_answers}` berdasarkan aturan baku Moodle:
   - `multichoice` (single): Jawaban benar = `1.0`, salah = `0.0`.
   - `mcma`: Setel `mdl_qtype_multichoice_options.single = 0`; tiap jawaban benar = `1.0 / $totalCorrect`, tiap jawaban salah = `-1.0 / $totalCorrect`.
   - `numerical`: Simpan nilai `tolerance` ke tabel `{question_numerical}`.

### C. Tambahkan Dukungan `ordering` dan `calculated` pada `score_calculator.php`
Lengkapi `score_calculator::calculate_fraction()` di `moodle/mod/cbt/classes/domain/service/score_calculator.php` agar mendukung:
- `ordering`: Perhitungan skor posisi absolut maupun relatif berpasangan sesuai standar Moodle 5.
- `calculated`: Evaluasi hasil formula dengan toleransi relatif/nominal.

---

## 3. Acceptance Criteria
- [ ] Soal yang dibuat di Next.js CBT dan di-push ke Moodle muncul secara native di halaman Question Bank Moodle 5 tanpa error versi.
- [ ] Edit soal di Moodle 5 menghasilkan versi baru (`version + 1`) dan `mod_cbt` hanya menarik versi terbaru (`status = 'ready'`).
- [ ] Nilai `fraction` pada soal `mcma` di `mdl_question_answers` terdistribusi proporsional (total positif = `1.0`, penalti negatif terisi otomatis) sesuai aturan baku Moodle 5.
- [ ] PHPUnit test di `moodle/mod/cbt/tests/domain_score_calculator_test.php` lulus untuk seluruh skenario penilaian parsial, penalti, dan toleransi numerik.
