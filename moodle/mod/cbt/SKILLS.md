# 🛠️ Technical Skills & Implementation Patterns: `mod_cbt` (PHP)

## 1. Kompetensi Inti yang Diterapkan
- **Moodle External API (`\core_external\external_api`):** Pembuatan *endpoint* bebas overhead HTML dengan validasi tipe ketat (`PARAM_INT`, `PARAM_ALPHANUMEXT`, `PARAM_RAW`).
- **Moodle Cache API (`db/caches.php`):** Penggunaan `cache_store::MODE_APPLICATION` dengan opsi `'simplekeys' => true` dan `'simpledata' => true` untuk menyimpan *Question Snapshot* dan *Answer Key Map* di memori/file lokal.
- **Moodle Gradebook API (`lib/gradelib.php`):** Implementasi fungsi standar `cbt_grade_item_update()` dan `cbt_update_grades()` agar nilai ujian dari Next.js langsung tercatat resmi di buku nilai Moodle.
- **CORS & Token Handshake:** Pembuatan *endpoint* jembatan (`mod/cbt/launch.php`) yang membuat *one-time launch code* ketika siswa mengklik ujian di Moodle, lalu melakukan *redirect* ke aplikasi Next.js 16 tanpa mengekspos *Moodle Admin Token*.
