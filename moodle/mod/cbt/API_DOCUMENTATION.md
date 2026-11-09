# 📘 Dokumentasi Resmi REST API `mod_cbt` untuk Next.js 16 (`aksaventra-cbt`)

Dokumen ini merupakan panduan teknis resmi bagi pengembang frontend Next.js untuk berinteraksi dengan backend Moodle melalui modul **`mod_cbt` (Aksaventra CBT Headless Engine)**.

---

## 🌐 1. Konfigurasi Protokol & Autentikasi

### Base URL & Endpoint
Seluruh pemanggilan API ditujukan ke endpoint standar Moodle REST Web Services:
- **Endpoint URL**: `${NEXT_PUBLIC_MOODLE_URL}/webservice/rest/server.php`
- **Format Respon Wajib**: `moodlewsrestformat=json`
- **Tipe Permintaan**: `POST` (Direkomendasikan untuk semua mutasi dan kueri data berukuran besar).
- **Service Name**: `mod_cbt_service` (*CBT Examination Service*).

### Parameter Standar Request
Setiap request ke Moodle Web Service wajib menyertakan:
| Parameter | Tipe | Keterangan |
| :--- | :--- | :--- |
| `wstoken` | `string` | Token permanen admin/guru (`MOODLE_WS_TOKEN`). |
| `wsfunction` | `string` | Nama fungsi API `mod_cbt_*` yang dipanggil. |
| `moodlewsrestformat` | `string` | Wajib diisi `json`. |

---

## 🛠️ 2. TypeScript API Client Wrapper (Next.js)

Gunakan utilitas berikut di `src/libs/moodleClient.ts` untuk mempermudah komunikasi dari Next.js:

```typescript
// src/libs/moodleClient.ts

const MOODLE_URL = process.env.NEXT_PUBLIC_MOODLE_URL || 'http://localhost:8080';
const MOODLE_TOKEN = process.env.MOODLE_WS_TOKEN || '';
const WS_ENDPOINT = `${MOODLE_URL}/webservice/rest/server.php`;

export async function callMoodleApi<T = any>(
  wsfunction: string,
  params: Record<string, any> = {}
): Promise<T> {
  const formData = new URLSearchParams();
  formData.append('wstoken', MOODLE_TOKEN);
  formData.append('wsfunction', wsfunction);
  formData.append('moodlewsrestformat', 'json');

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      formData.append(key, typeof value === 'object' ? JSON.stringify(value) : String(value));
    }
  });

  const response = await fetch(WS_ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: formData.toString(),
    cache: 'no-store',
  });

  if (!response.ok) {
    throw new Error(`HTTP error! status: ${response.status}`);
  }

  const json = await response.json();

  // Moodle error handler
  if (json.exception || json.errorcode) {
    throw new Error(json.message || `Moodle Exception: ${json.errorcode}`);
  }

  return json as T;
}
```

---

## 🔐 2. Autentikasi & Alur Login Siswa / Guru

Aplikasi Next.js mengautentikasi pengguna secara langsung ke backend Moodle menggunakan endpoint token bawaan Moodle yang terhubung ke layanan `mod_cbt_service`.

### A. Endpoint Login Pengguna (`/login/token.php`)
Digunakan pada form login Next.js (`/login` atau `/student/login`) untuk memvalidasi NIS/username dan password.

- **URL Endpoint**: `${NEXT_PUBLIC_MOODLE_URL}/login/token.php`
- **HTTP Method**: `POST`
- **Header**: `Content-Type: application/x-www-form-urlencoded`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `username` | `string` | Ya | NIS atau Username siswa / guru |
  | `password` | `string` | Ya | Kata sandi akun Moodle |
  | `service` | `string` | Ya | Wajib bernilai `mod_cbt_service` |

- **Contoh Request (TypeScript / Next.js)**:
  ```typescript
  export async function loginUserToMoodle(username: string, password: string) {
    const params = new URLSearchParams({
      username: username.trim(),
      password: password,
      service: 'mod_cbt_service',
    });

    const response = await fetch(`${process.env.NEXT_PUBLIC_MOODLE_URL}/login/token.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });

    const data = await response.json();
    if (data.error) {
      throw new Error(data.error); // e.g. "Invalid login, please check your credentials"
    }

    // data.token berisi user session token unik siswa/guru
    return data.token as string;
  }
  ```

- **Contoh Response Berhasil**:
  ```json
  {
    "token": "48b1d98e7ca6e91f08e452ef3a479b18",
    "privatetoken": null
  }
  ```

- **Contoh Response Gagal**:
  ```json
  {
    "error": "Invalid login, please check your credentials",
    "errorcode": "invalidlogin"
  }
  ```

---

### B. Endpoint Validasi Sesi & Profil Pengguna (`core_webservice_get_site_info`)
Setelah token diperoleh dari login di atas, panggil API ini untuk mendapatkan data profil siswa/guru (ID Pengguna, Nama Lengkap, Peran/Admin).

- **URL Endpoint**: `${NEXT_PUBLIC_MOODLE_URL}/webservice/rest/server.php`
- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `wstoken` | `string` | Ya | Token user yang didapatkan dari `/login/token.php` |
  | `wsfunction` | `string` | Ya | Wajib diisi `core_webservice_get_site_info` |
  | `moodlewsrestformat` | `string` | Ya | Wajib diisi `json` |

- **Contoh Response JSON**:
  ```json
  {
    "sitename": "Aksaventra CBT LMS",
    "username": "1001",
    "firstname": "Ahmad",
    "lastname": "Dahlan",
    "fullname": "Ahmad Dahlan",
    "lang": "en",
    "userid": 10,
    "userpictureurl": "http://localhost:8080/theme/image.php/boost/core/1/u/f1",
    "userissiteadmin": false
  }
  ```

---

### C. Single Sign-On (SSO) Handshake Launch Bridge (`/mod/cbt/launch.php`)
Bagi siswa yang mengakses ujian langsung melalui antarmuka web Moodle:
1. Siswa mengklik tombol **"Buka Aplikasi Ujian CBT (Next.js)"** di halaman aktivitas Moodle.
2. Moodle men-generate `launch_token` sementara (berlaku 5 menit) dan me-redirect browser siswa ke:
   ```
   http://localhost:3000/exam?cbtid=1&cmid=1&launch_token=abc123xyz...&moodle_api=http://localhost:8080/webservice/rest/server.php
   ```
3. Frontend Next.js membaca parameter URL tersebut dan langsung membuka sesi ujian siswa tanpa perlu memasukkan username & password kembali.

---

## 📚 3. Katalog Lengkap 12 REST API `mod_cbt`

---

### 👥 GRUP 1: Persiapan & Administrasi (Admin & Guru)

#### 1. `mod_cbt_manage_groups`
Mengelola kelas/kelompok (*cohort*) dan membuat akun siswa di Moodle.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `action` | `string` | Ya | Pilihan: `list_classes`, `create_class`, `create_student`, `get_class_students` |
  | `payload` | `string` | Tidak | String JSON sesuai action yang dipilih (default: `"{}"`) |

- **Contoh Request (Membuat Kelas Baru)**:
  ```json
  {
    "action": "create_class",
    "payload": "{\"name\": \"X RPL 1\", \"code\": \"XRPL1\", \"description\": \"Kelas X Rekayasa Perangkat Lunak 1\"}"
  }
  ```

- **Contoh Request (Membuat Siswa Baru)**:
  ```json
  {
    "action": "create_student",
    "payload": "{\"username\": \"1001\", \"firstname\": \"Ahmad\", \"lastname\": \"Dahlan\", \"password\": \"Siswa@2026\", \"email\": \"ahmad@sekolah.sch.id\", \"class_id\": 2}"
  }
  ```

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "message": "Class group created successfully",
    "classes": [
      {
        "id": 2,
        "name": "X RPL 1",
        "idnumber": "XRPL1",
        "description": "Kelas X Rekayasa Perangkat Lunak 1",
        "student_count": 36
      }
    ],
    "students": []
  }
  ```

---

#### 2. `mod_cbt_manage_schedule`
Mengambil atau menyimpan jadwal dan konfigurasi sesi ujian CBT.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `action` | `string` | Ya | Pilihan: `list` (ambil jadwal) atau `save` (buat/ubah jadwal) |
  | `data` | `string` | Tidak | String JSON data jadwal untuk action `save` |

- **Contoh Request (Simpan Jadwal Baru)**:
  ```json
  {
    "action": "save",
    "data": "{\"course_id\": 2, \"title\": \"Penilaian Akhir Semester Ganjil\", \"intro\": \"Kerjakan dengan tertib\", \"time_open\": 1731200000, \"time_close\": 1731286400, \"duration_seconds\": 7200, \"max_strikes\": 3, \"active_token\": \"789123\", \"max_grade\": 100.0}"
  }
  ```

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "message": "Schedule saved successfully",
    "schedules": [
      {
        "id": 1,
        "course_id": 2,
        "course_name": "Ujian CBT Sekolah",
        "title": "Penilaian Akhir Semester Ganjil",
        "intro": "<p>Kerjakan dengan tertib</p>",
        "time_open": 1731200000,
        "time_close": 1731286400,
        "duration_seconds": 7200,
        "max_strikes": 3,
        "active_token": "789123",
        "token_expiry": 0,
        "max_grade": 100.0
      }
    ]
  }
  ```

---

#### 3. `mod_cbt_assign_group_to_exam`
Menugaskan kelompok kelas sekaligus ke dalam sesi ujian dengan pembagian ruang dan nomor meja otomatis berurutan.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `cbtid` | `int` | Ya | ID sesi ujian CBT Moodle |
  | `class_ids` | `string` | Ya | Array JSON ID kelompok kelas, contoh: `"[2, 3]"` |
  | `room_code` | `string` | Tidak | Kode ruangan target (default: `"LAB-01"`) |
  | `seat_prefix` | `string` | Tidak | Prefix nomor meja (default: `"M-"`) |
  | `start_seat` | `int` | Tidak | Nomor urut awal meja (default: `1`) |

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "assigned_count": 36,
    "message": "Successfully assigned 36 students from 1 groups to room LAB-01 with seats starting from M-01"
  }
  ```

---

#### 4. `mod_cbt_manage_roster`
Melihat daftar peserta yang terdaftar pada ujian tertentu atau memindahkan ruangan/meja siswa secara individual.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `cbtid` | `int` | Ya | ID sesi ujian CBT Moodle |
  | `action` | `string` | Ya | `get` (ambil daftar) atau `assign` (simpan nomor meja) |
  | `participants` | `string` | Tidak | Array JSON peserta untuk action `assign` |

- **Contoh Response JSON (`action: "get"`)**:
  ```json
  {
    "success": true,
    "message": "Roster retrieved successfully",
    "roster": [
      {
        "user_id": 10,
        "username": "1001",
        "full_name": "Ahmad Dahlan",
        "email": "ahmad@sekolah.sch.id",
        "room_code": "LAB-01",
        "seat_number": "M-01",
        "attempt_id": 5,
        "status": "inprogress",
        "strike_count": 0,
        "score": 0.0
      }
    ]
  }
  ```

---

#### 5. `mod_cbt_import_export`
Fasilitas Import/Export massal data siswa, naskah bank soal 8 jenis pertanyaan, dan hasil rekap nilai ujian berbasis format Microsoft Excel / CSV.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `action` | `string` | Ya | Pilihan: `import_students`, `export_students`, `import_questions`, `export_results` |
  | `cbtid` | `int` | Tidak | ID sesi ujian CBT (wajib untuk soal dan rekap nilai) |
  | `payload` | `string` | Tidak | Isi teks CSV (dengan pemisah `;` atau `,` dan UTF-8 BOM) |

- **Contoh Request (Import Siswa Massal)**:
  ```json
  {
    "action": "import_students",
    "cbtid": 1,
    "payload": "nis_username;nama_lengkap;password;kelas;ruang_ujian;nomor_meja;email\n1001;Ahmad Dahlan;Siswa@2026;X-RPL-1;Lab Komputer 1;A-01;ahmad@sekolah.sch.id"
  }
  ```

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "message": "Berhasil mengimpor 36 akun siswa.",
    "processed_count": 36,
    "csv_data": "",
    "errors": []
  }
  ```

---

### 📝 GRUP 2: Pelaksanaan Ujian (Siswa / Peserta Ujian)

#### 6. `mod_cbt_get_exam_manifest`
Otentikasi sesi ujian siswa, verifikasi token ruangan, pencatatan fingerprint perangkat, dan pengambilan naskah soal beku (*stripped snapshot* tanpa kunci jawaban).

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `cbtid` | `int` | Ya | ID sesi ujian CBT |
  | `token` | `string` | Tidak | Token 6-digit ruangan ujian (jika diaktifkan) |
  | `device_hash` | `string` | Tidak | Fingerprint hash browser/perangkat siswa |

- **Contoh Response JSON**:
  ```json
  {
    "examId": 1,
    "title": "Simulasi Ujian CBT Aksaventra",
    "durationSeconds": 7200,
    "maxStrikes": 3,
    "serverTimestamp": 1731201500,
    "attempt": {
      "attemptId": 12,
      "timeStart": 1731201500,
      "extraTimeSeconds": 0,
      "remainingSeconds": 7200,
      "strikeCount": 0,
      "status": "inprogress",
      "syncVersion": 1
    },
    "questions": [
      {
        "slot": 1,
        "questionId": 101,
        "type": "multichoice_single",
        "stemHtml": "<p>Ibukota negara Indonesia yang baru adalah...</p>",
        "options": [
          { "key": "A", "html": "<p>Jakarta</p>" },
          { "key": "B", "html": "<p>Surabaya</p>" },
          { "key": "C", "html": "<p>Nusantara</p>" }
        ]
      }
    ]
  }
  ```

---

#### 7. `mod_cbt_sync_batch_answers`
Sinkronisasi batch jawaban dari antrean IndexedDB siswa ke server secara berkala (*optimistic concurrency*).

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `attemptid` | `int` | Ya | ID percobaan ujian (`attemptId`) |
  | `sync_version` | `int` | Ya | Nomor sekuens versi sync klien |
  | `answers_json` | `string` | Ya | JSON payload kumpulan jawaban |

- **Contoh Request**:
  ```json
  {
    "attemptid": 12,
    "sync_version": 2,
    "answers_json": "{\"101\": {\"ans\": \"C\", \"flag\": false, \"ts\": 1731201650}}"
  }
  ```

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "sync_version": 2,
    "server_time": 1731201655,
    "remaining_seconds": 7045,
    "status": "inprogress",
    "error": ""
  }
  ```

---

#### 8. `mod_cbt_report_violation`
Melaporkan peristiwa kecurangan (tab blur, keluar fullscreen, devtools). Jika akumulasi pelanggaran mencapai `maxstrikes`, sistem akan mengunci sesi siswa secara otomatis.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `attemptid` | `int` | Ya | ID percobaan ujian |
  | `event_type` | `string` | Ya | Pilihan: `TAB_BLUR`, `FULLSCREEN_EXIT`, `DEVTOOLS_OPEN` |
  | `details` | `string` | Tidak | Konteks tambahan (e.g. `{"duration_ms": 2500}`) |

- **Contoh Response JSON**:
  ```json
  {
    "strike_count": 3,
    "locked": true,
    "status": "locked",
    "message": "Maximum strike count reached. Exam session locked."
  }
  ```

---

#### 9. `mod_cbt_submit_exam`
Mengumpulkan dan memfinalisasi pengerjaan ujian siswa, mengkalkulasi skor otomatis untuk seluruh jenis soal objektif, dan memperbarui buku nilai Moodle.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `attemptid` | `int` | Ya | ID percobaan ujian yang akan dikumpulkan |

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "sumgrades": 88.5,
    "timefinish": 1731205200
  }
  ```

---

### 🛡️ GRUP 3: Pengawasan Ujian (Live Proctoring Monitor)

#### 10. `mod_cbt_get_proctor_stream`
Mengambil matriks seluruh kandidat di ruangan ujian beserta notifikasi pelanggaran kecurangan terkini secara real-time.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `cbtid` | `int` | Ya | ID sesi ujian CBT |
  | `room_code` | `string` | Tidak | Filter ruangan ujian (e.g. `"LAB-01"`) |

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "exam": {
      "id": 1,
      "title": "Simulasi Ujian CBT Aksaventra",
      "active_token": "123456",
      "total_candidates": 36,
      "in_progress": 34,
      "submitted": 1,
      "locked": 1
    },
    "candidates": [
      {
        "attempt_id": 12,
        "user_id": 10,
        "username": "1001",
        "full_name": "Ahmad Dahlan",
        "seat_number": "M-01",
        "room_code": "LAB-01",
        "status": "locked",
        "strike_count": 3,
        "remaining_seconds": 6400,
        "last_sync": 1731202100
      }
    ],
    "recent_alerts": [
      {
        "id": 88,
        "attempt_id": 12,
        "username": "1001",
        "full_name": "Ahmad Dahlan",
        "event_type": "TAB_BLUR",
        "timecreated": 1731202095
      }
    ]
  }
  ```

---

#### 11. `mod_cbt_proctor_control`
Menjalankan intervensi proktor terhadap peserta (Buka Kunci, Blokir Manual, Tambah Waktu, Kumpul Paksa).

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `attemptid` | `int` | Ya | ID percobaan ujian siswa |
  | `action` | `string` | Ya | Pilihan: `unlock`, `extend_time`, `force_submit` |
  | `payload` | `string` | Tidak | JSON payload (misal `{"seconds": 600, "reason": "Kendala jaringan"}`) |

- **Aksi Kendali yang Didukung**:
  1. **`unlock`**: Membuka kunci siswa yang terblokir akibat *false alarm*. Status kembali menjadi `inprogress` dan strike di-reset ke 0.
  2. **`extend_time`**: Menambah kompensasi waktu pengerjaan siswa sebanyak N detik.
  3. **`force_submit`**: Mengumpulkan paksa ujian siswa seketika.

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "message": "Attempt unlocked successfully.",
    "attempt": {
      "id": 12,
      "status": "inprogress",
      "strike_count": 0,
      "extra_time": 0,
      "sumgrades": 0.0
    }
  }
  ```

---

### 📊 GRUP 4: Rekapitulasi & Hasil Ujian (Pasca Ujian)

#### 12. `mod_cbt_get_recapitulation`
Mengambil rekapitulasi nilai akhir, statistik ujian (rata-rata, nilai tertinggi/terendah), dan riwayat integritas seluruh siswa.

- **HTTP Method**: `POST`
- **Parameter Request**:
  | Parameter | Tipe | Wajib | Keterangan |
  | :--- | :--- | :--- | :--- |
  | `cbtid` | `int` | Ya | ID sesi ujian CBT |

- **Contoh Response JSON**:
  ```json
  {
    "success": true,
    "exam": {
      "id": 1,
      "title": "Simulasi Ujian CBT Aksaventra",
      "course_name": "Ujian CBT Sekolah",
      "max_grade": 100.0
    },
    "statistics": {
      "total_students": 36,
      "submitted_count": 35,
      "locked_count": 1,
      "average_score": 82.4,
      "highest_score": 98.0,
      "lowest_score": 54.0
    },
    "results": [
      {
        "user_id": 10,
        "username": "1001",
        "full_name": "Ahmad Dahlan",
        "email": "ahmad@sekolah.sch.id",
        "seat_number": "M-01",
        "room_code": "LAB-01",
        "status": "submitted",
        "strike_count": 0,
        "time_start": 1731201500,
        "time_finish": 1731204800,
        "duration_taken_seconds": 3300,
        "final_score": 88.5,
        "max_score": 100.0,
        "percentage": 88.5
      }
    ]
  }
  ```

---

## 🔒 4. Keamanan & Pola Integrasi Next.js

1. **Proxy Keamanan Next.js API Routes**:
   Untuk mencegah terbukanya `MOODLE_WS_TOKEN` di browser klien, buat proxy route di Next.js:
   `src/app/api/moodle-proxy/[...wsfunction]/route.ts`:
   - Frontend memanggil `/api/moodle-proxy/mod_cbt_get_exam_manifest`.
   - Next.js server route menyematkan token `process.env.MOODLE_WS_TOKEN` dan meneruskannya ke Moodle.
2. **Kesesuaian Format Excel**:
   - Seluruh payload CSV yang di-export menggunakan **UTF-8 BOM (`\xEF\xBB\xBF`)** dengan pemisah titik-koma (`;`).
   - Format ini menjamin berkas Excel langsung terbuka rapi dalam kolom-kolom terpisah di Microsoft Excel tanpa perlu wizard import teks.

