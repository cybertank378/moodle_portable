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

    /// Menginisialisasi sistem database MariaDB jika direktori mysql belum ada
    pub fn ensure_mariadb_initialized(mariadb_dir: &Path) -> Result<()> {
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
    pub fn ensure_moodle_database(mariadb_dir: &Path, port: u16) -> Result<()> {
        let mysql_bin = mariadb_dir.join("bin").join("mysql.exe");
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

        Self::ensure_mariadb_initialized(&paths.mariadb_dir)?;

        self.mariadb_proc = Some(
            Command::new(&mysqld_bin)
                .arg(format!("--defaults-file={}", my_ini.display()))
                .current_dir(&paths.mariadb_dir)
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
        Self::ensure_moodle_database(&paths.mariadb_dir, DB_PORT)?;

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

        self.nginx_proc = Some(
            Command::new(&nginx_bin)
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
            let _ = Command::new(&nginx_bin)
                .args(["-s", "stop"])
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

    /// Memastikan tidak ada daemon server yang menggantung di latar belakang
    pub fn kill_lingering_daemons() {
        let _ = Command::new("taskkill")
            .args(["/F", "/IM", "nginx.exe", "/IM", "php-cgi.exe", "/T"])
            .creation_flags(CREATE_NO_WINDOW)
            .status();
    }
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
}