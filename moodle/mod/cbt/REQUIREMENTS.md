# 📋 Technical & Functional Requirements: `mod_cbt` (PHP Backend)

## 1. Spesifikasi Database (`db/install.xml` - XMLDB Schema)

### Tabel 1: `mdl_cbt` (Konfigurasi Utama Aktivitas Ujian)
| Kolom | Tipe | Null | Default | Deskripsi |
| :--- | :--- | :--- | :--- | :--- |
| `id` | BIGINT(10) | No | Auto | Primary Key |
| `course` | BIGINT(10) | No | 0 | Foreign Key ke `mdl_course.id` |
| `name` | VARCHAR(255) | No | '' | Nama Ujian CBT |
| `intro` | TEXT | Yes | Null | Deskripsi / Petunjuk Ujian |
| `timeopen` | BIGINT(10) | No | 0 | Unix Timestamp ujian dibuka |
| `timeclose` | BIGINT(10) | No | 0 | Unix Timestamp ujian ditutup |
| `timelimit` | BIGINT(10) | No | 0 | Durasi ujian dalam detik (misal: 7200 = 120 menit) |
| `maxstrikes` | INT(4) | No | 3 | Batas maksimal pindah tab/kehilangan fokus sebelum terkunci |
| `sebrequired` | TINYINT(1) | No | 0 | Wajib Safe Exam Browser (0=Tidak, 1=Ya) |
| `sebconfigkey` | VARCHAR(64) | Yes | Null | SHA-256 Hash dari Config Key SEB |
| `tokensecret` | VARCHAR(64) | No | '' | Secret key untuk merotasi token ruang 6-digit |
| `active_token` | VARCHAR(10) | No | '' | Token 6-digit aktif saat ini (diperbarui proktor) |
| `token_expiry` | BIGINT(10) | No | 0 | Masa berlaku token saat ini |
| `snapshot_hash` | VARCHAR(64) | Yes | Null | Hash versi naskah soal yang sudah di-freeze ke MUC |
| `grade` | DECIMAL(10,5)| No | 100 | Nilai maksimal di Moodle Gradebook |

### Tabel 2: `mdl_cbt_attempts` (Sesi Pengerjaan Siswa)
| Kolom | Tipe | Null | Default | Deskripsi |
| :--- | :--- | :--- | :--- | :--- |
| `id` | BIGINT(10) | No | Auto | Primary Key |
| `cbtid` | BIGINT(10) | No | 0 | Foreign Key ke `mdl_cbt.id` |
| `userid` | BIGINT(10) | No | 0 | Foreign Key ke `mdl_user.id` |
| `status` | VARCHAR(20) | No | 'inprogress'| Enum: `inprogress`, `locked`, `submitted`, `abandoned` |
| `timestart` | BIGINT(10) | No | 0 | Waktu mulai mutlak (Server Timestamp) |
| `timefinish` | BIGINT(10) | No | 0 | Waktu selesai mutlak |
| `extra_time` | INT(10) | No | 0 | Tambahan waktu dari proktor (dalam detik) |
| `strike_count` | INT(4) | No | 0 | Akumulasi pelanggaran pindah tab/keluar fullscreen |
| `device_hash` | VARCHAR(128) | No | '' | Fingerprint perangkat + User-Agent yang terikat |
| `ip_address` | VARCHAR(45) | No | '' | IP terakhir peserta |
| `question_order`| TEXT | No | '' | JSON Array urutan ID soal yang diacak per siswa |
| `answers_json` | LONGTEXT | Yes | Null | JSON Map `{ "q_id": { "ans": "A", "flag": false, "ts": 1730... } }` |
| `sync_version` | INT(10) | No | 0 | *Optimistic Locking Version* untuk mencegah *stale overwrite* |
| `sumgrades` | DECIMAL(10,5)| Yes | Null | Nilai akhir hasil kalkulasi |

### Tabel 3: `mdl_cbt_violation_logs` (Audit Log Kecurangan & Aksi Proktor)
| Kolom | Tipe | Null | Default | Deskripsi |
| :--- | :--- | :--- | :--- | :--- |
| `id` | BIGINT(10) | No | Auto | Primary Key |
| `attemptid` | BIGINT(10) | No | 0 | Foreign Key ke `mdl_cbt_attempts.id` |
| `event_type` | VARCHAR(50) | No | '' | `TAB_BLUR`, `FULLSCREEN_EXIT`, `DEVTOOLS_OPEN`, `PROCTOR_UNLOCK`, `TIME_EXTEND` |
| `details` | TEXT | Yes | Null | Payload JSON detail kejadian / alasan proktor |
| `timecreated` | BIGINT(10) | No | 0 | Server Unix Timestamp |
