# Issue 10: Integrasi Penuh Rust Engine untuk Portabilitas Dinamis & Optimasi Stack (Nginx, MariaDB, PHP, dan Moodle)

## 1. Deskripsi Masalah (Full-Stack Scope)

Agar arsitektur `.aksaventra_rt` 100% *portable* (bebas dipindahkan ke drive/direktori mana pun) dan siap menangani beban ujian (CBT) tanpa konflik dengan aplikasi global di host Windows, keempat komponen utama membutuhkan penyesuaian terpadu di dalam kode Rust (`src/`):

1. **Nginx (`nginx/conf/nginx.conf` & `sites-enabled/moodle.conf`):**
   - Memerlukan parameter CLI `-p <rt_dir>/nginx/` saat dijalankan oleh `src/process.rs` agar semua path relatif (`logs/`, `temp/`, `conf/`) mengacu ke dalam `.aksaventra_rt/nginx`.
   - Direktif `root` pada `sites-enabled/moodle.conf` dan `SCRIPT_FILENAME` pada `fastcgi_params` harus menggunakan path dinamis yang diinjeksi oleh `src/paths.rs` (menggunakan *forward slash* `/`) agar PHP-CGI tidak gagal membaca skrip Moodle.
2. **MariaDB (`mariadb/data/my.ini`):**
   - Direktif `basedir`, `datadir`, `plugin_dir`, dan `tmpdir` pada `mariadb/data/my.ini` tidak boleh *hardcoded* ke `D:/`.
   - Eksekusi `mysqld.exe` dan `mysql.exe` oleh `src/process.rs` wajib menyertakan argumen `--defaults-file="<rt_dir>/mariadb/data/my.ini"` di posisi argumen pertama agar tidak membaca `my.ini` global milik Laragon/XAMPP di Windows.
3. **PHP (`php/php.ini`):**
   - Direktif `extension_dir` dan `upload_tmp_dir` harus diinjeksi secara dinamis oleh `src/paths.rs` mengacu ke `<rt_dir>/php/ext` dan `<rt_dir>/moodledata/temp`.
   - Setiap pemanggilan `php.exe` dan `php-cgi.exe` di `src/process.rs` wajib mengunci environment variable `PHPRC` dan `PATH` ke `<rt_dir>/php`.
4. **Moodle (`moodle/config.php` & Database Cleanup):**
   - Variabel `$CFG->wwwroot`, `$CFG->dataroot`, dan `$CFG->tempdir` pada `moodle/config.php` harus diperbarui secara dinamis oleh `src/paths.rs` atau dihitung menggunakan `dirname(__DIR__)`.
   - Sisa registrasi subplugin yang foldernya telah dihapus dari disk (seperti `forumreport_summary` versi `2025041400`) harus dibersihkan otomatis dari tabel `mdl_config_plugins` disertai penonaktifan fitur latar belakang yang membebani CPU/RAM.

---

## 2. Standarisasi Template Konfigurasi Sebelum Build Container

### A. `moodle/config.php` (Gunakan Resolusi Relatif Native PHP)
Ubah definisi `$CFG->dataroot` di `moodle/config.php` agar otomatis menunjuk ke `.aksaventra_rt/moodledata` tanpa bergantung pada huruf Drive:

```php
// Resolusi otomatis ke folder .aksaventra_rt/moodledata
$CFG->dataroot  = str_replace('\\', '/', dirname(__DIR__)) . '/moodledata';
$CFG->tempdir   = $CFG->dataroot . '/temp';
$CFG->cachedir  = $CFG->dataroot . '/cache';
$CFG->localcachedir = $CFG->dataroot . '/localcache';
```

### B. `php/php.ini`
Komentari path statis agar dapat diinjeksi bersih oleh `src/paths.rs` atau menggunakan resolusi native binary:

```ini
; Direkayasa dinamis oleh src/paths.rs saat runtime startup
extension_dir = "ext"
;session.save_path = ""
```

---

## 3. Implementasi pada Kode Rust (`src/`)

### A. Pembaruan `src/paths.rs` (Injeksi Path Dinamis untuk Nginx, MariaDB, PHP, & Moodle)
Tambahkan fungsi sinkronisasi path menyeluruh di `src/paths.rs` yang dipanggil setiap kali aplikasi dimulai (*startup*):

```rust
use std::fs;
use std::io::Result;
use std::path::Path;

pub fn sync_all_runtime_paths(rt_dir: &Path) -> Result<()> {
    let rt_slash = rt_dir.to_string_lossy().replace('\\', "/");

    // 1. Update PHP (php/php.ini)
    let php_ini = rt_dir.join("php").join("php.ini");
    if php_ini.exists() {
        let ext_dir = format!("{}/php/ext", rt_slash);
        let tmp_dir = format!("{}/moodledata/temp", rt_slash);
        let _ = fs::create_dir_all(rt_dir.join("moodledata").join("temp"));

        let content = fs::read_to_string(&php_ini)?;
        let updated = content
            .lines()
            .map(|line| {
                let trimmed = line.trim_start();
                if trimmed.starts_with("extension_dir") || trimmed.starts_with(";extension_dir") {
                    format!("extension_dir = \"{}\"", ext_dir)
                } else if trimmed.starts_with("sys_temp_dir") || trimmed.starts_with(";sys_temp_dir") {
                    format!("sys_temp_dir = \"{}\"", tmp_dir)
                } else if trimmed.starts_with("upload_tmp_dir") || trimmed.starts_with(";upload_tmp_dir") {
                    format!("upload_tmp_dir = \"{}\"", tmp_dir)
                } else if trimmed.starts_with("session.save_path") {
                    ";session.save_path = \"\"".to_string()
                } else {
                    line.to_string()
                }
            })
            .collect::<Vec<_>>()
            .join("\r\n");
        fs::write(&php_ini, updated)?;
    }

    // 2. Update MariaDB (mariadb/data/my.ini)
    let my_ini = rt_dir.join("mariadb").join("data").join("my.ini");
    if my_ini.exists() {
        let basedir = format!("{}/mariadb", rt_slash);
        let datadir = format!("{}/mariadb/data", rt_slash);
        let plugin_dir = format!("{}/mariadb/lib/plugin", rt_slash);

        let content = fs::read_to_string(&my_ini)?;
        let updated = content
            .lines()
            .map(|line| {
                let trimmed = line.trim_start();
                if trimmed.starts_with("basedir") {
                    format!("basedir=\"{}\"", basedir)
                } else if trimmed.starts_with("datadir") {
                    format!("datadir=\"{}\"", datadir)
                } else if trimmed.starts_with("plugin_dir") || trimmed.starts_with("plugin-dir") {
                    format!("plugin_dir=\"{}\"", plugin_dir)
                } else {
                    line.to_string()
                }
            })
            .collect::<Vec<_>>()
            .join("\r\n");
        fs::write(&my_ini, updated)?;
    }

    // 3. Update Nginx (nginx/conf/sites-enabled/moodle.conf)
    let moodle_conf = rt_dir
        .join("nginx")
        .join("conf")
        .join("sites-enabled")
        .join("moodle.conf");
    if moodle_conf.exists() {
        let moodle_root = format!("{}/moodle", rt_slash);
        let dataroot = format!("{}/moodledata", rt_slash);

        let content = fs::read_to_string(&moodle_conf)?;
        let updated = content
            .lines()
            .map(|line| {
                let trimmed = line.trim_start();
                if trimmed.starts_with("root ") {
                    format!("    root \"{}\";", moodle_root)
                } else if trimmed.contains("/moodledata/") && trimmed.starts_with("internal;") == false {
                    // Jika menggunakan alias dataroot untuk X-Sendfile
                    if trimmed.starts_with("alias ") {
                        format!("        alias \"{}/\";", dataroot)
                    } else {
                        line.to_string()
                    }
                } else {
                    line.to_string()
                }
            })
            .collect::<Vec<_>>()
            .join("\r\n");
        fs::write(&moodle_conf, updated)?;
    }

    Ok(())
}
```

---

### B. Pembaruan `src/process.rs` (Isolasi Binary Portable & Auto-Sync Moodle DB)
Pastikan setiap *service* (MariaDB, PHP-CGI, Nginx, dan Moodle CLI) dijalankan dengan mengisolasi `PATH` dan `PHPRC` agar tidak pernah memanggil binary global di luar `.aksaventra_rt`:

```rust
use std::path::Path;
use std::process::{Child, Command, Stdio};

/// Helper untuk membuat environment PATH terisolasi khusus .aksaventra_rt
fn portable_path_env(rt_dir: &Path) -> String {
    let php_dir = rt_dir.join("php");
    let maria_bin = rt_dir.join("mariadb").join("bin");
    let nginx_dir = rt_dir.join("nginx");
    let sys_path = std::env::var("PATH").unwrap_or_default();
    format!(
        "{};{};{};{}",
        php_dir.display(),
        maria_bin.display(),
        nginx_dir.display(),
        sys_path
    )
}

/// 1. Start MariaDB dengan penguncian --defaults-file
pub fn start_mariadb(rt_dir: &Path) -> std::io::Result<Child> {
    let maria_bin = rt_dir.join("mariadb").join("bin").join("mysqld.exe");
    let my_ini = rt_dir.join("mariadb").join("data").join("my.ini");

    Command::new(maria_bin)
        .arg(format!("--defaults-file={}", my_ini.display()))
        .arg("--console")
        .current_dir(rt_dir.join("mariadb"))
        .env("PATH", portable_path_env(rt_dir))
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
}

/// 2. Start PHP-CGI dengan penguncian PHPRC & php.ini
pub fn start_php_cgi(rt_dir: &Path, bind_port: u16) -> std::io::Result<Child> {
    let php_dir = rt_dir.join("php");
    let php_cgi = php_dir.join("php-cgi.exe");
    let php_ini = php_dir.join("php.ini");

    Command::new(php_cgi)
        .arg("-b")
        .arg(format!("127.0.0.1:{}", bind_port))
        .arg("-c")
        .arg(&php_ini)
        .current_dir(&php_dir)
        .env("PHPRC", &php_dir)
        .env("PATH", portable_path_env(rt_dir))
        .env("PHP_FCGI_MAX_REQUESTS", "0")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
}

/// 3. Start Nginx dengan prefix -p mengarah ke .aksaventra_rt/nginx
pub fn start_nginx(rt_dir: &Path) -> std::io::Result<Child> {
    let nginx_dir = rt_dir.join("nginx");
    let nginx_exe = nginx_dir.join("nginx.exe");

    Command::new(nginx_exe)
        .arg("-p")
        .arg(format!("{}/", nginx_dir.to_string_lossy().replace('\\', "/")))
        .arg("-c")
        .arg("conf/nginx.conf")
        .current_dir(&nginx_dir)
        .env("PATH", portable_path_env(rt_dir))
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
}

/// 4. Sinkronisasi DB Moodle (Hapus forumreport_summary & Optimasi CBT)
pub fn optimize_moodle_and_cleanup_plugins(rt_dir: &Path) -> std::io::Result<()> {
    let php_dir = rt_dir.join("php");
    let php_exe = php_dir.join("php.exe");
    let php_ini = php_dir.join("php.ini");
    let moodle_dir = rt_dir.join("moodle");

    let inline_php = r#"
        define('CLI_SCRIPT', true);
        require('config.php');
        // Hapus residu plugin yang di-prune dari disk (Missing from disk!)
        $DB->delete_records('config_plugins', ['plugin' => 'forumreport_summary']);
        // Matikan fitur berat Moodle untuk mode ujian (CBT)
        set_config('enableanalytics', 0);
        set_config('messaging', 0);
        set_config('enableblogs', 0);
        set_config('enablecompetencies', 0);
        set_config('enablebadges', 0);
        $DB->set_field('modules', 'visible', 0, ['name' => 'forum']);
        $DB->set_field('task_scheduled', 'disabled', 1, ['classname' => '\mod_forum\task\cron_task']);
    "#;

    // Eksekusi cleanup database
    Command::new(&php_exe)
        .current_dir(&moodle_dir)
        .env("PHPRC", &php_dir)
        .env("PATH", portable_path_env(rt_dir))
        .arg("-c")
        .arg(&php_ini)
        .arg("-r")
        .arg(inline_php)
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()?;

    // Eksekusi purge_caches.php
    Command::new(&php_exe)
        .current_dir(&moodle_dir)
        .env("PHPRC", &php_dir)
        .env("PATH", portable_path_env(rt_dir))
        .arg("-c")
        .arg(&php_ini)
        .arg("admin/cli/purge_caches.php")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()?;

    Ok(())
}
```

---

### C. Urutan Eksekusi pada `src/main.rs`
Pastikan urutan pemanggilan saat *startup* di `src/main.rs` berjalan secara sekuensial seperti berikut:

1. `container::ensure_extracted(&rt_dir)` — Ekstraksi/verifikasi runtime `.aksaventra_rt`.
2. `paths::sync_all_runtime_paths(&rt_dir)` — Suntikkan path dinamis ke `php.ini`, `my.ini`, dan `moodle.conf`.
3. `process::start_mariadb(&rt_dir)` — Jalankan MariaDB dan tunggu hingga *port* database siap menerima koneksi.
4. `process::optimize_moodle_and_cleanup_plugins(&rt_dir)` — Bersihkan `forumreport_summary`, terapkan optimasi CBT, dan jalankan `purge_caches.php`.
5. `process::start_php_cgi(&rt_dir, 9000)` & `process::start_nginx(&rt_dir)` — Jalankan *web server* setelah database dan cache Moodle bersih.

---

## 4. Checklist Penyelesaian (Definition of Done)
- [ ] Path pada `php/php.ini`, `mariadb/data/my.ini`, `nginx/conf/sites-enabled/moodle.conf`, dan `moodle/config.php` terkonfigurasi otomatis oleh `paths::sync_all_runtime_paths` tanpa *hardcode* `D:/`.
- [ ] Eksekusi MariaDB, PHP-CGI, Nginx, dan Moodle CLI di `src/process.rs` terisolasi dari binary host Windows menggunakan `portable_path_env` dan `PHPRC`.
- [ ] Status **Missing from disk!** pada `forumreport_summary` (`2025041400`) terhapus otomatis saat startup dan cache Moodle bersih.