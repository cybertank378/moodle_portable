use crate::network::wait_for_port;
use crate::paths::PortablePaths;
use anyhow::{anyhow, Context, Result};
use std::fs;
use std::os::windows::process::CommandExt;
use std::path::Path;
use std::process::{Child, Command, Stdio};
use std::time::Duration;

pub const CREATE_NO_WINDOW: u32 = 0x08000000;
pub const DB_PORT: u16 = 3306;
pub const PHP_PORT: u16 = 9000;

pub struct ServiceOrchestrator {
    mariadb_proc: Option<Child>,
    php_proc: Option<Child>,
    nginx_proc: Option<Child>,
}

impl ServiceOrchestrator {
    pub fn new() -> Self {
        Self {
            mariadb_proc: None,
            php_proc: None,
            nginx_proc: None,
        }
    }

    /// Helper untuk membuat environment PATH terisolasi khusus direktori portabel
    pub fn portable_path_env(paths: &PortablePaths) -> String {
        let php_dir = &paths.php_dir;
        let maria_bin = paths.mariadb_dir.join("bin");
        let nginx_dir = &paths.nginx_dir;
        let sys_path = std::env::var("PATH").unwrap_or_default();
        format!(
            "{};{};{};{}",
            php_dir.display(),
            maria_bin.display(),
            nginx_dir.display(),
            sys_path
        )
    }

    /// Menginisialisasi sistem database MariaDB jika direktori mysql belum ada
    pub fn ensure_mariadb_initialized(paths: &PortablePaths) -> Result<()> {
        let mariadb_dir = &paths.mariadb_dir;
        let data_dir = mariadb_dir.join("data");
        let mysql_dir = data_dir.join("mysql");

        if mysql_dir.exists() {
            return Ok(());
        }

        println!("[ENGINE] Data MariaDB belum diinisialisasi. Menjalankan inisialisasi awal database...");
        fs::create_dir_all(&data_dir)?;

        let install_db_exe = mariadb_dir.join("bin").join("mariadb-install-db.exe");
        let fallback_exe = mariadb_dir.join("bin").join("mysql_install_db.exe");
        let installer = if install_db_exe.exists() {
            install_db_exe
        } else if fallback_exe.exists() {
            fallback_exe
        } else {
            return Ok(());
        };

        let my_ini = data_dir.join("my.ini");
        let temp_ini = mariadb_dir.join("temp_my.ini");
        let had_ini = my_ini.exists();
        if had_ini {
            let _ = fs::rename(&my_ini, &temp_ini);
        }

        let status = Command::new(&installer)
            .arg(format!("--datadir={}", data_dir.display()))
            .env("PATH", Self::portable_path_env(paths))
            .current_dir(mariadb_dir)
            .creation_flags(CREATE_NO_WINDOW)
            .status();

        if had_ini {
            let _ = fs::rename(&temp_ini, &my_ini);
        }

        if let Ok(st) = status {
            if st.success() {
                println!("  [OK] Inisialisasi awal database MariaDB berhasil.");
            } else {
                eprintln!("  [WARN] Inisialisasi MariaDB keluar dengan status: {:?}", st.code());
            }
        }

        Ok(())
    }

    /// Memastikan database moodle telah dibuat dengan collation utf8mb4_unicode_ci
    pub fn ensure_moodle_database(paths: &PortablePaths, port: u16) -> Result<()> {
        let mysql_bin = paths.mariadb_dir.join("bin").join("mysql.exe");
        if mysql_bin.exists() {
            let status = Command::new(&mysql_bin)
                .args([
                    "-u",
                    "root",
                    "-P",
                    &port.to_string(),
                    "-h",
                    "127.0.0.1",
                    "-e",
                    "CREATE DATABASE IF NOT EXISTS moodle DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
                ])
                .env("PATH", Self::portable_path_env(paths))
                .creation_flags(CREATE_NO_WINDOW)
                .status();

            if let Ok(st) = status {
                if st.success() {
                    println!("  [OK] Database 'moodle' (utf8mb4_unicode_ci) terverifikasi & siap.");
                }
            }
        }
        Ok(())
    }

    /// Menjalankan MariaDB, PHP-CGI, dan Nginx secara berurutan dan headless
    pub fn start_all(&mut self, paths: &PortablePaths, app_port: u16, profile: &crate::hardware::HardwareProfile) -> Result<()> {
        // 1. Jalankan MariaDB Server
        println!("[ENGINE] Menyalakan MariaDB Database Server (Port {})...", DB_PORT);
        let mysqld_bin = paths.mariadb_dir.join("bin").join("mysqld.exe");
        let my_ini = paths.mariadb_dir.join("data").join("my.ini");

        if !mysqld_bin.exists() {
            return Err(anyhow!("Binary mysqld.exe tidak ditemukan di {:?}", mysqld_bin));
        }

        Self::ensure_mariadb_initialized(paths)?;

        self.mariadb_proc = Some(
            Command::new(&mysqld_bin)
                .arg(format!("--defaults-file={}", my_ini.display()))
                .arg("--console")
                .current_dir(&paths.mariadb_dir)
                .env("PATH", Self::portable_path_env(paths))
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .spawn()
                .with_context(|| format!("Gagal mengeksekusi {:?}", mysqld_bin))?,
        );

        // Tunggu hingga port MariaDB aktif
        if !wait_for_port(DB_PORT, Duration::from_secs(15)) {
            self.stop_all(paths);
            return Err(anyhow!("MariaDB tidak merespon pada port {}. Pastikan tidak ada konflik database lain.", DB_PORT));
        }
        println!("  [OK] MariaDB Database Server siap menerima koneksi.");

        // Pastikan database moodle otomatis dibuat
        Self::ensure_moodle_database(paths, DB_PORT)?;

        // Bersihkan residu plugin & optimasi performa Moodle untuk CBT
        let _ = Self::optimize_moodle_and_cleanup_plugins(paths);

        // 2. Jalankan PHP FastCGI dengan pool worker dinamis sesuai profil
        println!("[ENGINE] Menyalakan PHP FastCGI (Port {}, {} Workers - {})...", PHP_PORT, profile.workers, profile.name);
        let php_cgi_bin = paths.php_dir.join("php-cgi.exe");
        let php_ini = paths.php_dir.join("php.ini");

        if !php_cgi_bin.exists() {
            self.stop_all(paths);
            return Err(anyhow!("Binary php-cgi.exe tidak ditemukan di {:?}", php_cgi_bin));
        }

        self.php_proc = Some(
            Command::new(&php_cgi_bin)
                .args(["-b", &format!("127.0.0.1:{}", PHP_PORT), "-c", &php_ini.to_string_lossy()])
                .env("PHPRC", &paths.php_dir)
                .env("PATH", Self::portable_path_env(paths))
                .env("PHP_FCGI_CHILDREN", profile.workers.to_string())
                .env("PHP_FCGI_MAX_REQUESTS", "10000")
                .current_dir(&paths.php_dir)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .spawn()
                .with_context(|| format!("Gagal mengeksekusi {:?}", php_cgi_bin))?,
        );

        // Tunggu hingga port PHP FastCGI aktif
        if !wait_for_port(PHP_PORT, Duration::from_secs(10)) {
            self.stop_all(paths);
            return Err(anyhow!("PHP FastCGI tidak merespon pada port {}.", PHP_PORT));
        }
        println!("  [OK] PHP FastCGI Daemon aktif.");

        // 3. Jalankan Nginx Web Server
        println!("[ENGINE] Menyalakan Nginx Web Server (Port {})...", app_port);
        let nginx_bin = paths.nginx_dir.join("nginx.exe");

        if !nginx_bin.exists() {
            self.stop_all(paths);
            return Err(anyhow!("Binary nginx.exe tidak ditemukan di {:?}", nginx_bin));
        }

        let nginx_posix = PortablePaths::to_posix_path(&paths.nginx_dir);
        self.nginx_proc = Some(
            Command::new(&nginx_bin)
                .args(["-p", &format!("{}/", nginx_posix), "-c", "conf/nginx.conf"])
                .env("PATH", Self::portable_path_env(paths))
                .current_dir(&paths.nginx_dir)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .spawn()
                .with_context(|| format!("Gagal mengeksekusi {:?}", nginx_bin))?,
        );

        // Tunggu hingga port Nginx aktif
        if !wait_for_port(app_port, Duration::from_secs(10)) {
            self.stop_all(paths);
            return Err(anyhow!("Nginx Web Server tidak merespon pada port {}.", app_port));
        }
        println!("  [OK] Nginx Web Server aktif.");

        Ok(())
    }

    /// Mematikan seluruh proses layanan background secara bersih (graceful shutdown)
    pub fn stop_all(&mut self, paths: &PortablePaths) {
        println!("\n[ENGINE] Menghentikan seluruh proses layanan background...");

        // 1. Hentikan Nginx secara halus (nginx -s stop)
        let nginx_bin = paths.nginx_dir.join("nginx.exe");
        if nginx_bin.exists() {
            let nginx_posix = PortablePaths::to_posix_path(&paths.nginx_dir);
            let _ = Command::new(&nginx_bin)
                .args(["-p", &format!("{}/", nginx_posix), "-c", "conf/nginx.conf", "-s", "stop"])
                .current_dir(&paths.nginx_dir)
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }

        if let Some(mut child) = self.nginx_proc.take() {
            let _ = child.kill();
            let _ = child.wait();
        }
        println!("  [OK] Nginx Web Server dihentikan.");

        // 2. Matikan proses PHP-CGI
        if let Some(mut child) = self.php_proc.take() {
            let _ = child.kill();
            let _ = child.wait();
        }
        println!("  [OK] PHP FastCGI Daemon dihentikan.");

        // 3. Hentikan MariaDB secara bersih via mysqladmin shutdown agar tabel tidak korup
        let mysqladmin_bin = paths.mariadb_dir.join("bin").join("mysqladmin.exe");
        if mysqladmin_bin.exists() {
            let _ = Command::new(&mysqladmin_bin)
                .args(["-u", "root", "shutdown"])
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }

        if let Some(mut child) = self.mariadb_proc.take() {
            let _ = child.wait();
        }
        println!("  [OK] MariaDB Database Server dihentikan dengan aman.");
    }

    /// Membuka peramban (browser) default sistem secara otomatis ke URL aplikasi
    pub fn open_browser(url: &str) -> Result<()> {
        Command::new("cmd")
            .args(["/C", "start", "", url])
            .creation_flags(CREATE_NO_WINDOW)
            .spawn()
            .with_context(|| format!("Gagal membuka browser untuk URL: {}", url))?;
        Ok(())
    }

    /// Sinkronisasi DB Moodle: membersihkan residu subplugin (forumreport_summary)
    /// dan mengoptimalkan konfigurasi CBT serta menjalankan purge_caches.php
    pub fn optimize_moodle_and_cleanup_plugins(paths: &PortablePaths) -> Result<()> {
        let php_exe = paths.php_dir.join("php.exe");
        let php_ini = paths.php_dir.join("php.ini");
        let moodle_dir = &paths.moodle_dir;

        if !php_exe.exists() || !moodle_dir.join("config.php").exists() {
            return Ok(());
        }

        println!("[ENGINE] Melakukan optimasi Moodle & pembersihan residu plugin (CBT Mode)...");

        let inline_php = r#"
            define('CLI_SCRIPT', true);
            require('config.php');
            // Hapus residu plugin yang di-prune dari disk (Missing from disk!)
            ->delete_records('config_plugins', ['plugin' => 'forumreport_summary']);
            // Matikan fitur berat Moodle untuk mode ujian (CBT)
            set_config('enableanalytics', 0);
            set_config('messaging', 0);
            set_config('enableblogs', 0);
            set_config('enablecompetencies', 0);
            set_config('enablebadges', 0);
            ->set_field('modules', 'visible', 0, ['name' => 'forum']);
            ->set_field('task_scheduled', 'disabled', 1, ['classname' => '\mod_forum\task\cron_task']);
        "#;

        // Eksekusi cleanup database
        let status_db = Command::new(&php_exe)
            .current_dir(moodle_dir)
            .env("PHPRC", &paths.php_dir)
            .env("PATH", Self::portable_path_env(paths))
            .arg("-c")
            .arg(&php_ini)
            .arg("-r")
            .arg(inline_php)
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .creation_flags(CREATE_NO_WINDOW)
            .status();

        match status_db {
            Ok(s) if s.success() => {
                println!("  [OK] Residu forumreport_summary dibersihkan & setelan CBT diterapkan.");
            }
            _ => {
                println!("  [WARN] Penyesuaian database Moodle dilewati (tabel belum siap atau mode offline).");
            }
        }

        // Eksekusi purge_caches.php
        let purge_script = moodle_dir.join("admin").join("cli").join("purge_caches.php");
        if purge_script.exists() {
            let status_cache = Command::new(&php_exe)
                .current_dir(moodle_dir)
                .env("PHPRC", &paths.php_dir)
                .env("PATH", Self::portable_path_env(paths))
                .arg("-c")
                .arg(&php_ini)
                .arg("admin/cli/purge_caches.php")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();

            if let Ok(s) = status_cache {
                if s.success() {
                    println!("  [OK] Cache Moodle berhasil dibersihkan (purge_caches).");
                }
            }
        }

        Ok(())
    }

    /// Memastikan tidak ada daemon server yang menggantung di latar belakang
    pub fn kill_lingering_daemons() {
        let _ = Command::new("taskkill")
            .args(["/F", "/IM", "nginx.exe", "/IM", "php-cgi.exe", "/T"])
            .creation_flags(CREATE_NO_WINDOW)
            .status();
    }
}

/// Helper publik untuk modul yang membutuhkan pemanggilan start_mariadb secara mandiri
#[allow(dead_code)]
pub fn start_mariadb(rt_dir: &Path) -> std::io::Result<Child> {
    let paths = PortablePaths::from_base_dir(rt_dir.to_path_buf());
    let maria_bin = paths.mariadb_dir.join("bin").join("mysqld.exe");
    let my_ini = paths.mariadb_dir.join("data").join("my.ini");

    Command::new(maria_bin)
        .arg(format!("--defaults-file={}", my_ini.display()))
        .arg("--console")
        .current_dir(&paths.mariadb_dir)
        .env("PATH", ServiceOrchestrator::portable_path_env(&paths))
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .creation_flags(CREATE_NO_WINDOW)
        .spawn()
}

/// Helper publik untuk modul yang membutuhkan pemanggilan start_php_cgi secara mandiri
#[allow(dead_code)]
pub fn start_php_cgi(rt_dir: &Path, bind_port: u16) -> std::io::Result<Child> {
    let paths = PortablePaths::from_base_dir(rt_dir.to_path_buf());
    let php_dir = &paths.php_dir;
    let php_cgi = php_dir.join("php-cgi.exe");
    let php_ini = php_dir.join("php.ini");

    Command::new(php_cgi)
        .arg("-b")
        .arg(format!("127.0.0.1:{}", bind_port))
        .arg("-c")
        .arg(&php_ini)
        .current_dir(php_dir)
        .env("PHPRC", php_dir)
        .env("PATH", ServiceOrchestrator::portable_path_env(&paths))
        .env("PHP_FCGI_MAX_REQUESTS", "0")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .creation_flags(CREATE_NO_WINDOW)
        .spawn()
}

/// Helper publik untuk modul yang membutuhkan pemanggilan start_nginx secara mandiri
#[allow(dead_code)]
pub fn start_nginx(rt_dir: &Path) -> std::io::Result<Child> {
    let paths = PortablePaths::from_base_dir(rt_dir.to_path_buf());
    let nginx_dir = &paths.nginx_dir;
    let nginx_exe = nginx_dir.join("nginx.exe");
    let nginx_posix = PortablePaths::to_posix_path(nginx_dir);

    Command::new(nginx_exe)
        .arg("-p")
        .arg(format!("{}/", nginx_posix))
        .arg("-c")
        .arg("conf/nginx.conf")
        .current_dir(nginx_dir)
        .env("PATH", ServiceOrchestrator::portable_path_env(&paths))
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .creation_flags(CREATE_NO_WINDOW)
        .spawn()
}

/// Helper publik untuk membersihkan plugin dan mengoptimalkan DB Moodle
#[allow(dead_code)]
pub fn optimize_moodle_and_cleanup_plugins(rt_dir: &Path) -> std::io::Result<()> {
    let paths = PortablePaths::from_base_dir(rt_dir.to_path_buf());
    ServiceOrchestrator::optimize_moodle_and_cleanup_plugins(&paths)
        .map_err(|e| std::io::Error::new(std::io::ErrorKind::Other, e.to_string()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_orchestrator_initial_state() {
        let orchestrator = ServiceOrchestrator::new();
        assert!(orchestrator.mariadb_proc.is_none());
        assert!(orchestrator.php_proc.is_none());
        assert!(orchestrator.nginx_proc.is_none());
    }

    #[test]
    fn test_constants_defined() {
        assert_eq!(CREATE_NO_WINDOW, 0x08000000);
        assert_eq!(DB_PORT, 3306);
        assert_eq!(PHP_PORT, 9000);
    }

    #[test]
    fn test_portable_path_env_structure() {
        let base = std::path::PathBuf::from("D:/Aksaventra");
        let paths = PortablePaths::from_base_dir(base);
        let env_val = ServiceOrchestrator::portable_path_env(&paths);
        assert!(env_val.contains("php"));
        assert!(env_val.contains("mariadb"));
        assert!(env_val.contains("nginx"));
    }

    #[test]
    fn test_optimize_moodle_graceful_when_empty() {
        let temp_dir = std::env::temp_dir().join(format!("moodle_empty_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        let paths = PortablePaths::from_base_dir(temp_dir.clone());
        let res = ServiceOrchestrator::optimize_moodle_and_cleanup_plugins(&paths);
        assert!(res.is_ok(), "Harus graceful saat direktori moodle kosong");
        let _ = fs::remove_dir_all(&temp_dir);
    }
}