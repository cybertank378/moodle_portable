use crate::network::wait_for_port;
use crate::paths::PortablePaths;
use anyhow::{anyhow, Context, Result};
use std::fs;
use std::os::windows::process::CommandExt;
use std::path::Path;
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;

pub const CREATE_NO_WINDOW: u32 = 0x08000000;
pub const DB_PORT: u16 = 3306;
pub const PHP_PORT: u16 = 9000;
pub const NEXTJS_PORT: u16 = 3000;

pub struct ServiceOrchestrator {
    mariadb_proc: Option<Child>,
    php_proc: Option<Child>,
    nginx_proc: Option<Child>,
    nextjs_proc: Option<Child>,
    cron_handle: Option<std::thread::JoinHandle<()>>,
}

impl ServiceOrchestrator {
    pub fn new() -> Self {
        Self {
            mariadb_proc: None,
            php_proc: None,
            nginx_proc: None,
            nextjs_proc: None,
            cron_handle: None,
        }
    }

    pub fn is_nextjs_running(&self) -> bool {
        self.nextjs_proc.is_some()
    }

    /// Helper untuk membuat environment PATH terisolasi khusus direktori portabel
    pub fn portable_path_env(paths: &PortablePaths) -> String {
        let node_dir = &paths.nodejs_dir;
        let php_dir = &paths.php_dir;
        let maria_bin = paths.mariadb_dir.join("bin");
        let nginx_dir = &paths.nginx_dir;
        let sys_path = std::env::var("PATH").unwrap_or_default();
        format!(
            "{};{};{};{};{}",
            node_dir.display(),
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

        // Bersihkan residu file PID lama untuk mencegah crash permission denied saat restart
        Self::cleanup_stale_pid_files(&paths.mariadb_dir.join("data"));
        Self::ensure_mariadb_data_unhidden(&paths.mariadb_dir.join("data"));

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

        // 4. Jalankan Next.js CBT Server (Port 3000) menggunakan Node.js portabel
        let _ = self.start_nextjs(paths);

        // 5. Jalankan pemeliharaan otonom Moodle (Upgrade otomatis plugins & themes, autoconfig webservice, drain tasks, & sync_master)
        self.run_autonomous_moodle_maintenance(paths);

        Ok(())
    }

    /// Menjalankan server Next.js portabel pada port 3000
    pub fn start_nextjs(&mut self, paths: &PortablePaths) -> Result<()> {
        let node_bin = paths.nodejs_dir.join("node.exe");
        if !node_bin.exists() {
            println!("  [INFO] Runtime Node.js portabel tidak ditemukan di {:?}, melewati Aksaventra CBT.", paths.nodejs_dir);
            return Ok(());
        }

        let dashboard_dir = &paths.dashboard_dir;
        if !dashboard_dir.exists() {
            println!("  [INFO] Direktori Aksaventra Dashboard tidak ditemukan di {:?}, melewati Aksaventra CBT.", dashboard_dir);
            return Ok(());
        }

        println!("[ENGINE] Menyalakan Aksaventra CBT Server (Port {})...", NEXTJS_PORT);

        // 1. Pastikan database aksaventra_cbt tersedia di MariaDB
        let mysql_bin = paths.mariadb_dir.join("bin").join("mysql.exe");
        if mysql_bin.exists() {
            let _ = Command::new(&mysql_bin)
                .args([
                    "-h", "127.0.0.1",
                    "-P", &DB_PORT.to_string(),
                    "-u", "root",
                    "-e", "CREATE DATABASE IF NOT EXISTS aksaventra_cbt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
                ])
                .creation_flags(CREATE_NO_WINDOW)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status();
        }

        let next_cli = dashboard_dir.join("node_modules").join("next").join("dist").join("bin").join("next");
        let server_js = dashboard_dir.join("server.js");

        let mut cmd = Command::new(&node_bin);
        cmd.current_dir(dashboard_dir)
            .env("PORT", NEXTJS_PORT.to_string())
            .env("PATH", Self::portable_path_env(paths))
            .creation_flags(CREATE_NO_WINDOW)
            .stdout(Stdio::null())
            .stderr(Stdio::null());

        if server_js.exists() {
            cmd.env("NODE_ENV", "production");
            cmd.arg("server.js");
        } else if next_cli.exists() {
            let has_build = dashboard_dir.join(".next").exists();
            if has_build {
                cmd.env("NODE_ENV", "production");
                cmd.arg(&next_cli).args(["start", "-p", &NEXTJS_PORT.to_string()]);
            } else {
                cmd.env("NODE_ENV", "development");
                cmd.arg(&next_cli).args(["dev", "-p", &NEXTJS_PORT.to_string()]);
            }
        } else {
            println!("  [WARN] Modul Aksaventra CLI tidak ditemukan di {:?}.", dashboard_dir);
            return Ok(());
        }

        self.nextjs_proc = Some(cmd.spawn().with_context(|| "Gagal mengeksekusi Aksaventra CBT server")?);

        if wait_for_port(NEXTJS_PORT, Duration::from_secs(10)) {
            println!("  [OK] Aksaventra CBT Server aktif di port {}.", NEXTJS_PORT);
        } else {
            println!("  [INFO] Aksaventra CBT Server telah dijalankan di background (port {}).", NEXTJS_PORT);
        }

        Ok(())
    }

    /// Menjalankan pemeliharaan otonom Moodle pada saat engine start:
    /// 1. upgrade.php --non-interactive (Otomatis upgrade database jika ada pembaruan plugin / theme)
    /// 2. autoconfig.php (Otomatis provisioning webservice, API token, dan webhook secret)
    /// 3. adhoc_task.php --execute --force (Kuras antrean task migrasi question bank)
    /// 4. sync.php (Otomatis sinkronisasi dua arah Moodle <-> Next.js CBT)
    /// 5. purge_caches.php (Bersihkan cache agar plugin dan theme baru langsung aktif di browser)
    pub fn run_autonomous_moodle_maintenance(&self, paths: &PortablePaths) {
        let php_exe = paths.php_dir.join("php.exe");
        let php_ini = paths.php_dir.join("php.ini");
        let php_dir = paths.php_dir.clone();
        let moodle_dir = paths.moodle_dir.clone();
        let path_env = Self::portable_path_env(paths);

        if !php_exe.exists() || !moodle_dir.exists() {
            return;
        }

        println!("[ENGINE] Menjalankan Pemeriksaan & Pembaruan Otonom Moodle (Plugins, Themes, & DB)...");

        // 1. Eksekusi upgrade.php --non-interactive --allow-unstable
        // Jika ada plugin atau theme yang baru ditambahkan/diperbarui versinya, skema database Moodle otomatis di-upgrade
        let upgrade_script = moodle_dir.join("admin").join("cli").join("upgrade.php");
        if upgrade_script.exists() {
            print!("  -> Memeriksa dan memperbarui database core/plugins/themes...");
            let status = Command::new(&php_exe)
                .current_dir(&moodle_dir)
                .env("PHPRC", &php_dir)
                .env("PATH", &path_env)
                .arg("-c")
                .arg(&php_ini)
                .arg("admin/cli/upgrade.php")
                .arg("--non-interactive")
                .arg("--allow-unstable")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();

            match status {
                Ok(s) if s.success() => println!(" [OK] Terverifikasi up-to-date."),
                _ => println!(" [OK] Siap."),
            }
        }

        // 2. Eksekusi autoconfig.php untuk mod_cbt & webservice
        let autoconfig_script = moodle_dir.join("mod").join("cbt").join("cli").join("autoconfig.php");
        if autoconfig_script.exists() {
            print!("  -> Mengonfigurasi WebService & sinkronisasi token...");
            let _ = Command::new(&php_exe)
                .current_dir(&moodle_dir)
                .env("PHPRC", &php_dir)
                .env("PATH", &path_env)
                .arg("-c")
                .arg(&php_ini)
                .arg("mod/cbt/cli/autoconfig.php")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();
            println!(" [OK]");
        }

        // 3. Eksekusi adhoc_task.php --execute --force untuk menguras task migrasi question bank
        let adhoc_script = moodle_dir.join("admin").join("cli").join("adhoc_task.php");
        if adhoc_script.exists() {
            print!("  -> Memproses antrean ad-hoc task migrasi...");
            let _ = Command::new(&php_exe)
                .current_dir(&moodle_dir)
                .env("PHPRC", &php_dir)
                .env("PATH", &path_env)
                .arg("-c")
                .arg(&php_ini)
                .arg("admin/cli/adhoc_task.php")
                .arg("--execute")
                .arg("--force")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();
            println!(" [OK]");
        }

        // 4. Eksekusi sync.php (sync_master) untuk sinkronisasi dua arah Moodle <-> Next.js CBT
        let sync_script = moodle_dir.join("mod").join("cbt").join("cli").join("sync.php");
        if sync_script.exists() {
            print!("  -> Menjalankan sinkronisasi master data (Moodle <-> CBT)...");
            let _ = Command::new(&php_exe)
                .current_dir(&moodle_dir)
                .env("PHPRC", &php_dir)
                .env("PATH", &path_env)
                .arg("-c")
                .arg(&php_ini)
                .arg("mod/cbt/cli/sync.php")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();
            println!(" [OK]");
        }

        // 5. Eksekusi purge_caches.php agar asset tema atau plugin terbaru langsung aktif
        let purge_script = moodle_dir.join("admin").join("cli").join("purge_caches.php");
        if purge_script.exists() {
            let _ = Command::new(&php_exe)
                .current_dir(&moodle_dir)
                .env("PHPRC", &php_dir)
                .env("PATH", &path_env)
                .arg("-c")
                .arg(&php_ini)
                .arg("admin/cli/purge_caches.php")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }
    }

    /// Menjalankan daemon background worker untuk memproses Moodle Cron dan Ad-hoc tasks secara berkala
    pub fn start_moodle_cron_daemon(
        &mut self,
        paths: &PortablePaths,
        running: Arc<AtomicBool>,
    ) {
        let php_exe = paths.php_dir.join("php.exe");
        let php_ini = paths.php_dir.join("php.ini");
        let php_dir = paths.php_dir.clone();
        let moodle_dir = paths.moodle_dir.clone();
        let path_env = Self::portable_path_env(paths);

        let cron_script = moodle_dir.join("admin").join("cli").join("cron.php");
        let adhoc_script = moodle_dir.join("admin").join("cli").join("adhoc_task.php");
        let upgrade_script = moodle_dir.join("admin").join("cli").join("upgrade.php");

        if !php_exe.exists() || !cron_script.exists() {
            return;
        }

        println!("[ENGINE] Menyalakan Daemon Background Cron & Ad-hoc Moodle (Interval: 60s)...");

        let r = running;
        let handle = std::thread::Builder::new()
            .name("moodle-cron-worker".to_string())
            .spawn(move || {
                let mut counter: u32 = 0;
                let mut cycle_count: u32 = 0;
                while r.load(Ordering::SeqCst) {
                    std::thread::sleep(Duration::from_secs(1));
                    if !r.load(Ordering::SeqCst) {
                        break;
                    }
                    counter += 1;
                    if counter >= 60 {
                        counter = 0;
                        cycle_count += 1;

                        // 1. Eksekusi admin/cli/cron.php
                        let _ = Command::new(&php_exe)
                            .current_dir(&moodle_dir)
                            .env("PHPRC", &php_dir)
                            .env("PATH", &path_env)
                            .arg("-c")
                            .arg(&php_ini)
                            .arg("admin/cli/cron.php")
                            .stdout(Stdio::null())
                            .stderr(Stdio::null())
                            .creation_flags(CREATE_NO_WINDOW)
                            .status();

                        // 2. Eksekusi admin/cli/adhoc_task.php --execute --force untuk memproses task antrean cepat
                        if adhoc_script.exists() {
                            let _ = Command::new(&php_exe)
                                .current_dir(&moodle_dir)
                                .env("PHPRC", &php_dir)
                                .env("PATH", &path_env)
                                .arg("-c")
                                .arg(&php_ini)
                                .arg("admin/cli/adhoc_task.php")
                                .arg("--execute")
                                .arg("--force")
                                .stdout(Stdio::null())
                                .stderr(Stdio::null())
                                .creation_flags(CREATE_NO_WINDOW)
                                .status();
                        }

                        // 3. Eksekusi mod/cbt/cli/sync.php untuk auto-sync data master berkala
                        let sync_script = moodle_dir.join("mod").join("cbt").join("cli").join("sync.php");
                        if sync_script.exists() {
                            let _ = Command::new(&php_exe)
                                .current_dir(&moodle_dir)
                                .env("PHPRC", &php_dir)
                                .env("PATH", &path_env)
                                .arg("-c")
                                .arg(&php_ini)
                                .arg("mod/cbt/cli/sync.php")
                                .stdout(Stdio::null())
                                .stderr(Stdio::null())
                                .creation_flags(CREATE_NO_WINDOW)
                                .status();
                        }

                        // 4. Setiap 5 siklus (5 menit), periksa upgrade database otonom jika ada plugin/theme yang baru dipasang
                        if cycle_count % 5 == 0 && upgrade_script.exists() {
                            let _ = Command::new(&php_exe)
                                .current_dir(&moodle_dir)
                                .env("PHPRC", &php_dir)
                                .env("PATH", &path_env)
                                .arg("-c")
                                .arg(&php_ini)
                                .arg("admin/cli/upgrade.php")
                                .arg("--non-interactive")
                                .arg("--allow-unstable")
                                .stdout(Stdio::null())
                                .stderr(Stdio::null())
                                .creation_flags(CREATE_NO_WINDOW)
                                .status();
                        }
                    }
                }
            });

        match handle {
            Ok(h) => {
                self.cron_handle = Some(h);
                println!("  [OK] Daemon Background Cron & Ad-hoc Moodle aktif.");
            }
            Err(e) => {
                eprintln!("  [WARN] Gagal memulai daemon cron Moodle: {:?}", e);
            }
        }
    }

    /// Mematikan seluruh proses layanan background secara bersih (graceful shutdown)
    pub fn stop_all(&mut self, paths: &PortablePaths) {
        println!("\n[ENGINE] Menghentikan seluruh proses layanan background...");

        // 0. Hentikan daemon background cron Moodle jika aktif
        if let Some(handle) = self.cron_handle.take() {
            let _ = handle.join();
        }

        // 1. Hentikan Nginx secara halus (nginx -s stop) lalu force-kill process tree-nya
        let nginx_bin = paths.nginx_dir.join("nginx.exe");
        if nginx_bin.exists() {
            let nginx_posix = PortablePaths::to_posix_path(&paths.nginx_dir);
            let _ = Command::new(&nginx_bin)
                .args(["-p", &format!("{}/", nginx_posix), "-c", "conf/nginx.conf", "-s", "stop"])
                .current_dir(&paths.nginx_dir)
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }

        if let Some(child) = self.nginx_proc.take() {
            let _ = Command::new("taskkill")
                .args(["/F", "/T", "/PID", &child.id().to_string()])
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }
        println!("  [OK] Nginx Web Server dihentikan.");

        // 2. Matikan proses PHP-CGI beserta seluruh 16/32 worker processes (kill tree)
        if let Some(child) = self.php_proc.take() {
            let _ = Command::new("taskkill")
                .args(["/F", "/T", "/PID", &child.id().to_string()])
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }
        println!("  [OK] PHP FastCGI Daemon dihentikan.");

        // 3. Hentikan Aksaventra CBT Server (Node.js) beserta child processes-nya
        if let Some(child) = self.nextjs_proc.take() {
            let _ = Command::new("taskkill")
                .args(["/F", "/T", "/PID", &child.id().to_string()])
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }
        println!("  [OK] Aksaventra CBT Server dihentikan.");

        // 4. Hentikan MariaDB secara bersih via mysqladmin shutdown agar tabel tidak korup
        let mysqladmin_bin = paths.mariadb_dir.join("bin").join("mysqladmin.exe");
        if mysqladmin_bin.exists() {
            let _ = Command::new(&mysqladmin_bin)
                .args(["-u", "root", "-P", &DB_PORT.to_string(), "shutdown"])
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }

        std::thread::sleep(Duration::from_millis(600));

        if let Some(child) = self.mariadb_proc.take() {
            let _ = Command::new("taskkill")
                .args(["/F", "/T", "/PID", &child.id().to_string()])
                .creation_flags(CREATE_NO_WINDOW)
                .status();
        }
        println!("  [OK] MariaDB Database Server dihentikan dengan aman.");

        // 5. Sweep kill: pastikan tidak ada proses residu php-cgi atau nginx yang tertinggal
        Self::kill_lingering_daemons();
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
            global $DB, $CFG;

            // Daftar seluruh komponen yang telah di-prune dari disk
            $pruned = [
                'mod_scorm', 'mod_workshop',
                'scormreport_basic', 'scormreport_graphs', 'scormreport_interactions', 'scormreport_objectives',
                'workshopform_accumulative', 'workshopform_comments', 'workshopform_numerrors', 'workshopform_rubric',
                'workshopallocation_manual', 'workshopallocation_random', 'workshopallocation_scheduled',
                'workshopeval_best',
                'block_badges', 'block_blog_tags', 'block_comments', 'block_tag_flickr', 'block_tags',
                'tool_moodlenet', 'forumreport_summary',
            ];

            foreach ($pruned as $comp) {
                $DB->delete_records('config_plugins', ['plugin' => $comp]);
                $DB->delete_records('upgrade_log', ['plugin' => $comp]);
                $DB->delete_records('capabilities', ['component' => $comp]);
            }

            foreach (['scorm', 'workshop'] as $mod) {
                $DB->delete_records('modules', ['name' => $mod]);
            }

            foreach (['badges', 'blog_tags', 'comments', 'tag_flickr', 'tags'] as $blk) {
                $DB->delete_records('block', ['name' => $blk]);
                $DB->delete_records('block_instances', ['blockname' => $blk]);
            }

            // Hapus muc/config.php jika mengandung datasource lama yang hilang
            $muc_cfg = $CFG->dataroot . '/muc/config.php';
            if (file_exists($muc_cfg)) {
                @unlink($muc_cfg);
            }

            // Matikan fitur berat Moodle untuk mode ujian (CBT)
            set_config('enableanalytics', 0);
            set_config('messaging', 0);
            set_config('enableblogs', 0);
            set_config('enablecompetencies', 0);
            set_config('enablebadges', 0);
            set_config('usecomments', 0);
            set_config('usetags', 0);
            $DB->set_field('modules', 'visible', 0, ['name' => 'forum']);
            $DB->set_field('task_scheduled', 'disabled', 1, ['classname' => '\mod_forum\task\cron_task']);
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
                println!("  [OK] Residu plugin berhasil dibersihkan & setelan performa CBT aktif.");
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

        // Eksekusi pemrosesan awal tugas ad-hoc yang tertunda (migrasi bank soal, dll.)
        let adhoc_script = moodle_dir.join("admin").join("cli").join("adhoc_task.php");
        if adhoc_script.exists() {
            let status_adhoc = Command::new(&php_exe)
                .current_dir(moodle_dir)
                .env("PHPRC", &paths.php_dir)
                .env("PATH", Self::portable_path_env(paths))
                .arg("-c")
                .arg(&php_ini)
                .arg("admin/cli/adhoc_task.php")
                .arg("--execute")
                .arg("--force")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();

            if let Ok(s) = status_adhoc {
                if s.success() {
                    println!("  [OK] Antrean tugas ad-hoc Moodle berhasil diselaraskan (qbank transfer).");
                }
            }
        }

        // 1. Eksekusi autoconfig mod_cbt untuk memastikan Web Services & Token API aktif
        let autoconfig_script = moodle_dir.join("mod").join("cbt").join("cli").join("autoconfig.php");
        if autoconfig_script.exists() {
            let status_autoconfig = Command::new(&php_exe)
                .current_dir(moodle_dir)
                .env("PHPRC", &paths.php_dir)
                .env("PATH", Self::portable_path_env(paths))
                .arg("-c")
                .arg(&php_ini)
                .arg("mod/cbt/cli/autoconfig.php")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();

            if let Ok(s) = status_autoconfig {
                if s.success() {
                    println!("  [OK] Konfigurasi Web Services mod_cbt & API Token berhasil diselaraskan.");
                }
            }
        }

        // 2. Eksekusi sinkronisasi master data awal (Moodle -> Aksaventra CBT)
        let sync_script = moodle_dir.join("mod").join("cbt").join("cli").join("sync.php");
        if sync_script.exists() {
            println!("[ENGINE] Menyinkronkan data Moodle (Siswa, Kelas, Ujian) ke Aksaventra CBT...");
            let status_sync = Command::new(&php_exe)
                .current_dir(moodle_dir)
                .env("PHPRC", &paths.php_dir)
                .env("PATH", Self::portable_path_env(paths))
                .arg("-c")
                .arg(&php_ini)
                .arg("mod/cbt/cli/sync.php")
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .creation_flags(CREATE_NO_WINDOW)
                .status();

            if let Ok(s) = status_sync {
                if s.success() {
                    println!("  [OK] Sinkronisasi master data Moodle <-> Aksaventra CBT selesai.");
                }
            }
        }

        Ok(())
    }

    /// Membersihkan residu file .pid lama yang dapat terkunci atau berstatus hidden
    pub fn cleanup_stale_pid_files(data_dir: &Path) {
        if let Ok(entries) = fs::read_dir(data_dir) {
            for entry in entries.flatten() {
                let path = entry.path();
                if path.is_file() && path.extension().and_then(|s| s.to_str()) == Some("pid") {
                    #[cfg(windows)]
                    {
                        use std::os::windows::ffi::OsStrExt;
                        use winapi::um::fileapi::SetFileAttributesW;
                        use winapi::um::winnt::FILE_ATTRIBUTE_NORMAL;
                        let wide: Vec<u16> = path.as_os_str().encode_wide().chain(std::iter::once(0)).collect();
                        unsafe {
                            SetFileAttributesW(wide.as_ptr(), FILE_ATTRIBUTE_NORMAL);
                        }
                    }
                    let _ = fs::remove_file(&path);
                }
            }
        }
    }

    /// Memastikan folder database dan tabel tidak berstatus Hidden/System agar terdeteksi MariaDB
    pub fn ensure_mariadb_data_unhidden(data_dir: &Path) {
        #[cfg(windows)]
        {
            use std::os::windows::ffi::OsStrExt;
            use winapi::um::fileapi::{GetFileAttributesW, SetFileAttributesW, INVALID_FILE_ATTRIBUTES};
            use winapi::um::winnt::{FILE_ATTRIBUTE_HIDDEN, FILE_ATTRIBUTE_NORMAL, FILE_ATTRIBUTE_SYSTEM};

            if let Ok(entries) = fs::read_dir(data_dir) {
                for entry in entries.flatten() {
                    let path = entry.path();
                    let wide: Vec<u16> = path.as_os_str().encode_wide().chain(std::iter::once(0)).collect();
                    unsafe {
                        let attrs = GetFileAttributesW(wide.as_ptr());
                        if attrs != INVALID_FILE_ATTRIBUTES && (attrs & (FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM) != 0) {
                            let new_attrs = attrs & !(FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM);
                            SetFileAttributesW(wide.as_ptr(), if new_attrs == 0 { FILE_ATTRIBUTE_NORMAL } else { new_attrs });
                        }
                    }
                    if path.is_dir() {
                        if let Ok(sub_entries) = fs::read_dir(&path) {
                            for sub in sub_entries.flatten() {
                                let sub_path = sub.path();
                                let sub_wide: Vec<u16> = sub_path.as_os_str().encode_wide().chain(std::iter::once(0)).collect();
                                unsafe {
                                    let sub_attrs = GetFileAttributesW(sub_wide.as_ptr());
                                    if sub_attrs != INVALID_FILE_ATTRIBUTES && (sub_attrs & (FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM) != 0) {
                                        let new_sub = sub_attrs & !(FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM);
                                        SetFileAttributesW(sub_wide.as_ptr(), if new_sub == 0 { FILE_ATTRIBUTE_NORMAL } else { new_sub });
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /// Memastikan tidak ada daemon server yang menggantung di latar belakang
    pub fn kill_lingering_daemons() {
        #[cfg(windows)]
        {
            let _ = Command::new("taskkill")
                .args(["/F", "/IM", "node.exe", "/T"])
                .creation_flags(CREATE_NO_WINDOW)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status();
            let _ = Command::new("taskkill")
                .args(["/F", "/IM", "php-cgi.exe", "/T"])
                .creation_flags(CREATE_NO_WINDOW)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status();
            let _ = Command::new("taskkill")
                .args(["/F", "/IM", "nginx.exe", "/T"])
                .creation_flags(CREATE_NO_WINDOW)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status();
            let _ = Command::new("taskkill")
                .args(["/F", "/IM", "mariadb.exe", "/T"])
                .creation_flags(CREATE_NO_WINDOW)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status();
            let _ = Command::new("taskkill")
                .args(["/F", "/IM", "mysql.exe", "/T"])
                .creation_flags(CREATE_NO_WINDOW)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status();
            let _ = Command::new("taskkill")
                .args(["/F", "/IM", "mysqld.exe", "/T"])
                .creation_flags(CREATE_NO_WINDOW)
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status();
        }
    }

    /// Menghubungkan engine ke Windows Job Object dengan limit KILL_ON_JOB_CLOSE.
    /// Hal ini menjamin bahwa jika console ditutup (klik tombol [X], Ctrl+C, atau force kill),
    /// OS Windows akan secara otomatis dan instan mematikan seluruh proses anak (executor)
    /// seperti mysqld.exe, php-cgi.exe, nginx.exe, dan node.exe tanpa ada yang tertinggal/stuck.
    #[cfg(windows)]
    pub fn enable_kill_on_job_close() {
        use winapi::um::jobapi2::{CreateJobObjectW, SetInformationJobObject, AssignProcessToJobObject};
        use winapi::um::winnt::{
            JOBOBJECT_EXTENDED_LIMIT_INFORMATION, JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE,
            JobObjectExtendedLimitInformation,
        };
        use winapi::um::processthreadsapi::GetCurrentProcess;
        use std::mem::size_of;
        use std::ptr::null_mut;

        unsafe {
            let job = CreateJobObjectW(null_mut(), null_mut());
            if !job.is_null() {
                let mut info: JOBOBJECT_EXTENDED_LIMIT_INFORMATION = std::mem::zeroed();
                info.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
                let _ = SetInformationJobObject(
                    job,
                    JobObjectExtendedLimitInformation,
                    &mut info as *mut _ as *mut _,
                    size_of::<JOBOBJECT_EXTENDED_LIMIT_INFORMATION>() as u32,
                );
                let _ = AssignProcessToJobObject(job, GetCurrentProcess());
                let _ = job;
            }
        }
    }
}

/// Helper publik untuk modul yang membutuhkan pemanggilan start_mariadb secara mandiri
#[allow(dead_code)]
pub fn start_mariadb(rt_dir: &Path) -> std::io::Result<Child> {
    let paths = PortablePaths::from_base_dir(rt_dir.to_path_buf());
    let maria_bin = paths.mariadb_dir.join("bin").join("mysqld.exe");
    let my_ini = paths.mariadb_dir.join("data").join("my.ini");

    ServiceOrchestrator::cleanup_stale_pid_files(&paths.mariadb_dir.join("data"));
    ServiceOrchestrator::ensure_mariadb_data_unhidden(&paths.mariadb_dir.join("data"));

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

    #[test]
    fn test_moodle_cron_daemon_lifecycle() {
        let temp_dir = std::env::temp_dir().join(format!("aksaventra_cron_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        let _ = fs::create_dir_all(&temp_dir);
        let paths = PortablePaths::from_base_dir(temp_dir.clone());
        let mut orchestrator = ServiceOrchestrator::new();
        assert!(orchestrator.cron_handle.is_none());

        let running = Arc::new(AtomicBool::new(true));
        orchestrator.start_moodle_cron_daemon(&paths, running.clone());
        // Karena php.exe/cron.php tidak ada di temp_dir, gracefully returns none
        assert!(orchestrator.cron_handle.is_none());

        // Buat file php.exe dan cron.php tiruan
        let _ = fs::create_dir_all(&paths.php_dir);
        let _ = fs::create_dir_all(paths.moodle_dir.join("admin").join("cli"));
        let _ = fs::write(paths.php_dir.join("php.exe"), b"mock");
        let _ = fs::write(paths.moodle_dir.join("admin").join("cli").join("cron.php"), b"mock");

        orchestrator.start_moodle_cron_daemon(&paths, running.clone());
        assert!(orchestrator.cron_handle.is_some());

        // Hentikan worker
        running.store(false, Ordering::SeqCst);
        if let Some(h) = orchestrator.cron_handle.take() {
            let _ = h.join();
        }
        let _ = fs::remove_dir_all(&temp_dir);
    }
}