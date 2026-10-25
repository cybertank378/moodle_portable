use std::env;
use std::path::PathBuf;
use std::process;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;

use aksaventra_installer::{
    get_service_url, open_browser, setup_ctrlc_handler, start_mariadb,
    start_moodle_cron, start_nginx, start_php_cgi, stop_services_gracefully_with_port,
    wait_for_mariadb_ready, wait_for_tcp_port, InstallationPaths,
};

fn resolve_engine_directory(args: &[String]) -> PathBuf {
    // If CLI argument --dir or -d is passed, use it
    if let Some(dir) = aksaventra_installer::progress::parse_install_dir_from_args(args) {
        return dir;
    }

    // Check if current working directory has runtime components
    let cwd = env::current_dir().unwrap_or_else(|_| PathBuf::from("."));
    if cwd.join("moodle").exists() && cwd.join("nginx").exists() {
        return cwd;
    }

    // Check if directory containing exe has runtime components
    if let Ok(exe_path) = env::current_exe() {
        if let Some(exe_dir) = exe_path.parent() {
            if exe_dir.join("moodle").exists() && exe_dir.join("nginx").exists() {
                return exe_dir.to_path_buf();
            }
            if exe_dir.join("runtime").join("moodle").exists() {
                return exe_dir.join("runtime");
            }
        }
    }

    // Check ./runtime
    if cwd.join("runtime").join("moodle").exists() {
        return cwd.join("runtime");
    }

    cwd
}

fn main() {
    let args: Vec<String> = env::args().collect();

    if args.iter().any(|a| a == "-h" || a == "--help") {
        println!("Aksaventra Portable LMS Engine");
        println!("Penggunaan: aksaventra_engine.exe [PILIHAN]");
        println!();
        println!("Pilihan:");
        println!("  -d, --dir, --install-dir <DIR>  Direktori instalasi runtime (default: auto-detect)");
        println!("  --skip-browser                  Jangan buka browser secara otomatis");
        println!("  -h, --help                      Tampilkan bantuan ini");
        return;
    }

    let skip_browser = args.iter().any(|a| a == "--skip-browser");
    let raw_target_dir = resolve_engine_directory(&args);
    let target_dir = if raw_target_dir.is_absolute() {
        raw_target_dir
    } else {
        std::env::current_dir().unwrap_or_default().join(&raw_target_dir)
    };
    let paths = InstallationPaths::new(&target_dir);

    println!("==========================================================");
    println!("             Aksaventra Portable LMS Engine               ");
    println!("==========================================================");
    println!("Runtime Directory: {}", target_dir.display());

    // Validate existence of components
    if !paths.moodle_dir.exists()
        || !paths.nginx_dir.exists()
        || !paths.mariadb_dir.exists()
        || !paths.php_dir.exists()
    {
        eprintln!("[ERROR] Komponen runtime Aksaventra tidak ditemukan di: {}", target_dir.display());
        eprintln!("Pastikan Anda telah menjalankan 'aksaventra_installer.exe' terlebih dahulu.");
        process::exit(1);
    }

    if !paths.moodle_config().exists() {
        eprintln!("[ERROR] Konfigurasi Moodle (config.php) belum dibuat di: {}", paths.moodle_dir.display());
        eprintln!("Jalankan 'aksaventra_installer.exe' untuk menyelesaikan instalasi database awal.");
        process::exit(1);
    }

    println!("Menjalankan server basis data MariaDB (port 3306)...");
    let _maria_child = match start_mariadb(&paths.mariadb_dir) {
        Ok(child) => child,
        Err(e) => {
            eprintln!("[ERROR] Gagal menjalankan MariaDB: {}", e);
            process::exit(1);
        }
    };

    if !wait_for_mariadb_ready(3306, Duration::from_secs(15)) {
        eprintln!("[ERROR] MariaDB tidak merespon pada port 3306.");
        let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
        process::exit(1);
    }
    println!("  [OK] MariaDB siap menerima koneksi.");

    println!("Menjalankan layanan PHP 8.2 FastCGI (port 9000)...");
    let _php_child = match start_php_cgi(&paths.php_dir, 9000) {
        Ok(child) => child,
        Err(e) => {
            eprintln!("[ERROR] Gagal menjalankan PHP FastCGI: {}", e);
            let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
            process::exit(1);
        }
    };

    if !wait_for_tcp_port(9000, Duration::from_secs(10)) {
        eprintln!("[ERROR] PHP FastCGI tidak merespon pada port 9000.");
        let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
        process::exit(1);
    }
    println!("  [OK] Layanan PHP 8.2 FastCGI aktif.");

    println!("Menjalankan server web Nginx (port 80)...");
    let _nginx_child = match start_nginx(&paths.nginx_dir) {
        Ok(child) => child,
        Err(e) => {
            eprintln!("[ERROR] Gagal menjalankan Nginx: {}", e);
            let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
            process::exit(1);
        }
    };

    if !wait_for_tcp_port(80, Duration::from_secs(10)) {
        eprintln!("[ERROR] Nginx web server tidak merespon pada port 80.");
        let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
        process::exit(1);
    }
    println!("  [OK] Server web Nginx aktif.");

    println!("Menjalankan penjadwal background Cron...");
    let cron_handle = start_moodle_cron(paths.base_dir.to_string_lossy().to_string());
    println!("  [OK] Background cron scheduler aktif.");

    let service_url = get_service_url(80);
    println!("==========================================================");
    println!("       Aksaventra Portable LMS Engine Telah BERJALAN!      ");
    println!("==========================================================");
    println!("  Alamat Browser Lokal: http://localhost");
    println!("  Alamat Jaringan LAN:  {}", service_url);
    println!("  Layanan Aktif:        Nginx (80), PHP-CGI (9000), MariaDB (3306)");
    println!("==========================================================");
    println!("  Tekan Ctrl+C untuk mematikan semua layanan dengan aman. ");
    println!("==========================================================");

    if !skip_browser {
        let _ = open_browser("http://localhost");
    }

    let running = Arc::new(AtomicBool::new(true));
    let r = Arc::clone(&running);
    let shutdown_base = paths.base_dir.clone();

    let _ = setup_ctrlc_handler(move || {
        println!();
        println!("[INFO] Menghentikan semua layanan Aksaventra dengan aman...");
        r.store(false, Ordering::SeqCst);
        let report = stop_services_gracefully_with_port(&shutdown_base, 3306);
        println!(
            "[OK] Layanan selesai dihentikan (Nginx: {}, MariaDB: {}, PHP: {}).",
            report.nginx_stopped, report.mariadb_stopped, report.php_terminated
        );
        println!("Server Aksaventra telah dimatikan. Sampai jumpa!");
        process::exit(0);
    });

    while running.load(Ordering::SeqCst) {
        thread::sleep(Duration::from_millis(500));
    }

    cron_handle.stop();
}
