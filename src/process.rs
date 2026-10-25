use crate::paths::PortablePaths;
use anyhow::{ Context, Result };
use std::os::windows::process::CommandExt;
use std::process::{ Child, Command, Stdio };
use std::thread;
use std::time::Duration;

const CREATE_NO_WINDOW: u32 = 0x08000000;

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

    pub fn start_all(&mut self, paths: &PortablePaths) -> Result<()> {
        println!("[ENGINE] Menyalakan MariaDB Database Server...");
        let mysqld_bin = paths.mariadb_dir.join("bin").join("mysqld.exe");
        let my_ini = paths.mariadb_dir.join("data").join("my.ini");
        self.mariadb_proc = Some(
            Command::new(mysqld_bin)
                .arg(format!("--defaults-file={}", my_ini.display()))
                .current_dir(&paths.mariadb_dir)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .spawn()
                .context("Gagal menjalankan mysqld.exe")?
        );

        thread::sleep(Duration::from_secs(2));

        println!("[ENGINE] Menyalakan PHP FastCGI (Port 9000)...");
        let php_cgi_bin = paths.php_dir.join("php-cgi.exe");
        let php_ini = paths.php_dir.join("php.ini");
        self.php_proc = Some(
            Command::new(php_cgi_bin)
                .args(["-b", "127.0.0.1:9000", "-c", &php_ini.to_string_lossy()])
                .current_dir(&paths.php_dir)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .spawn()
                .context("Gagal menjalankan php-cgi.exe")?
        );

        println!("[ENGINE] Menyalakan Nginx Web Server...");
        let nginx_bin = paths.nginx_dir.join("nginx.exe");
        self.nginx_proc = Some(
            Command::new(nginx_bin)
                .current_dir(&paths.nginx_dir)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .spawn()
                .context("Gagal menjalankan nginx.exe")?
        );

        Ok(())
    }

    pub fn stop_all(&mut self, paths: &PortablePaths) {
        println!("\n[ENGINE] Mematikan seluruh layanan aplikasi...");

        // Hentikan Nginx secara halus
        let _ = Command::new(paths.nginx_dir.join("nginx.exe"))
            .args(["-s", "stop"])
            .current_dir(&paths.nginx_dir)
            .creation_flags(CREATE_NO_WINDOW)
            .status();

        if let Some(mut child) = self.nginx_proc.take() {
            let _ = child.kill();
            let _ = child.wait();
        }

        if let Some(mut child) = self.php_proc.take() {
            let _ = child.kill();
            let _ = child.wait();
        }

        // Hentikan MariaDB secara bersih dengan mysqladmin shutdown agar tabel tidak rusak
        let _ = Command::new(paths.mariadb_dir.join("bin").join("mysqladmin.exe"))
            .args(["-u", "root", "shutdown"])
            .creation_flags(CREATE_NO_WINDOW)
            .status();

        if let Some(mut child) = self.mariadb_proc.take() {
            let _ = child.wait();
        }
    }
}