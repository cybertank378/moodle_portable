mod container;
mod hardware;
mod network;
mod paths;
mod process;
mod security;

use anyhow::Result;
use container::VaultContainer;
use paths::PortablePaths;
use process::ServiceOrchestrator;
use security::SecurityManager;
use std::env;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;

const DEFAULT_PORT: u16 = 8080;

fn print_help() {
    println!("Aksaventra Encrypted Portable Moodle Engine v{}", env!("CARGO_PKG_VERSION"));
    println!("Penggunaan: aksaventra_engine.exe [PILIHAN]");
    println!();
    println!("Pilihan:");
    println!("  -c, --concurrency, --workers <N>   Jumlah worker PHP FastCGI (default: auto rekomendasi hardware)");
    println!("  --profile <PROFIL>                 Profil performa: eco | normal | high | extreme");
    println!("  -p, --port <PORT>                  Tentukan port lokal khusus (default: 8080)");
    println!("  --encrypt, --pack                  Kemas folder mentah ke dalam brankas terenkripsi (.pak)");
    println!("  --skip-browser                     Jangan buka peramban secara otomatis");
    println!("  -h, --help                         Tampilkan bantuan ini");
}

#[tokio::main]
async fn main() -> Result<()> {
    let args: Vec<String> = env::args().collect();

    if args.iter().any(|arg| arg == "-h" || arg == "--help") {
        print_help();
        return Ok(());
    }

    println!("======================================================");
    println!("       AKSAVENTRA PORTABLE ENGINE v{}          ", env!("CARGO_PKG_VERSION"));
    println!("       Powered by Rust | aksaventra.ai.id             ");
    println!("======================================================");

    let paths = PortablePaths::init()?;

    // 1. Validasi Serial Key ke server aksaventra.ai.id terlebih dahulu
    let security = SecurityManager::authenticate(&paths.base_dir).await?;

    // Mode Packaging / Builder: `aksaventra_engine.exe --encrypt` atau `aksaventra_engine.exe --pack`
    if args.iter().any(|arg| arg == "--encrypt" || arg == "--pack") {
        println!("[PACKER] Mengemas folder mentah (nginx, php, moodle, moodledata, mariadb) ke dalam brankas...");
        VaultContainer::seal_folders(&paths.base_dir, &paths.vault_file, &security.encryption_key)?;
        println!("[PACKER] Selesai! Berkas {:?} telah dibuat.", paths.vault_file.file_name().unwrap_or_default());
        println!("[PACKER] Anda sekarang dapat mendistribusikan aksaventra_engine.exe bersama berkas .pak.");
        return Ok(());
    }

    // 2. Tentukan Port Bebas (Network Port Discovery)
    let mut preferred_port = DEFAULT_PORT;
    if let Some(pos) = args.iter().position(|a| a == "-p" || a == "--port") {
        if let Some(val) = args.get(pos + 1) {
            if let Ok(parsed) = val.parse::<u16>() {
                preferred_port = parsed;
            }
        }
    }
    let app_port = network::find_available_port(preferred_port);

    // 3. Deteksi Hardware & Tentukan Profil Konfigurasi Performa SEBELUM Unpack/Jalankan
    let hw_specs = hardware::detect_hardware();
    println!("[HARDWARE] Deteksi Spesifikasi Host : {} Core CPU | {:.1} GB Total RAM", hw_specs.cpu_cores, hw_specs.total_ram_gb);

    let mut is_auto = true;
    let active_profile = if let Some(pos) = args.iter().position(|a| a == "-c" || a == "--concurrency" || a == "--workers") {
        is_auto = false;
        if let Some(val) = args.get(pos + 1).and_then(|v| v.parse::<u32>().ok()) {
            hardware::custom_profile(val)
        } else {
            hardware::recommend_profile(&hw_specs)
        }
    } else if let Some(pos) = args.iter().position(|a| a == "--profile") {
        is_auto = false;
        if let Some(val) = args.get(pos + 1) {
            hardware::profile_from_name(val).unwrap_or_else(|| hardware::recommend_profile(&hw_specs))
        } else {
            hardware::recommend_profile(&hw_specs)
        }
    } else if let Ok(val) = env::var("AKSAVENTRA_CONCURRENCY").and_then(|v| v.parse::<u32>().map_err(|_| env::VarError::NotPresent)) {
        is_auto = false;
        hardware::custom_profile(val)
    } else {
        // Otomatis tentukan profil terbaik berdasarkan hardware aktif (Mode Default Klik 2x)
        hardware::recommend_profile(&hw_specs)
    };

    if is_auto {
        println!("[TUNING] Mode Otomatis (Klik 2x): Menggunakan rekomendasi performa terbaik untuk hardware Anda.");
    } else {
        println!("[TUNING] Mode Manual (CLI Override): Menggunakan parameter performa yang ditentukan pengguna.");
    }

    println!("[TUNING] Profil Performa Aktif     : {}", active_profile.name);
    println!("  * PHP FastCGI Worker Pool       : {} Workers", active_profile.workers);
    println!("  * MariaDB InnoDB Buffer Pool    : {}", active_profile.buffer_pool);
    println!("  * Nginx Worker Connections      : {}", active_profile.nginx_connections);
    println!("  * Estimasi Daya Tampung CBT     : {}", active_profile.estimated_cbt_users);

    // 4. Dekripsi folder runtime dari aksaventra_vault.pak jika ada
    if paths.vault_file.exists() {
        VaultContainer::unseal_folders(&paths.vault_file, &paths.runtime_dir, &security.encryption_key)?;
    } else if paths.nginx_dir.exists() {
        println!("[ENGINE] Menjalankan dalam mode folder langsung (Development Mode)...");
    } else {
        return Err(anyhow::anyhow!(
            "Berkas brankas {:?} tidak ditemukan dan folder layanan tidak tersedia di direktori aplikasi.",
            paths.vault_file.file_name().unwrap_or_default()
        ));
    }

    // 5. Re-konfigurasi path dan kapasitas konkurensi secara dinamis
    paths.patch_portable_configs(app_port, &active_profile)?;

    // 6. Jalankan MariaDB, PHP-CGI, dan Nginx secara headless
    let mut services = ServiceOrchestrator::new();
    services.start_all(&paths, app_port, &active_profile)?;

    // 7. Buka aplikasi di peramban (browser) default
    let active_ip = network::get_active_local_ip();
    let local_url = format!("http://localhost:{}", app_port);
    let lan_url = format!("http://{}:{}", active_ip, app_port);
    println!("======================================================");
    println!("   AKSAVENTRA PORTABLE LMS TELAH AKTIF & BERJALAN     ");
    println!("======================================================");
    println!("  Akses Lokal (Laptop Server) : {}", local_url);
    if active_ip != "127.0.0.1" {
        println!("  Akses Jaringan (Wi-Fi/LAN)  : {}", lan_url);
    }
    println!("  Status Layanan              : MariaDB ({}), PHP ({} Workers), Nginx", active_profile.buffer_pool, active_profile.workers);
    println!("  Profil / Kapasitas CBT      : {} ({})", active_profile.name, active_profile.estimated_cbt_users);
    println!("======================================================");
    println!("  Tekan Ctrl+C untuk menutup dan mengamankan data.     ");
    println!("======================================================");

    if !args.iter().any(|arg| arg == "--skip-browser") {
        let target_url = if active_ip != "127.0.0.1" {
            &lan_url
        } else {
            &local_url
        };
        let _ = ServiceOrchestrator::open_browser(target_url);
    }

    // 7. Penanganan sinyal keluar aplikasi (Ctrl+C Graceful Shutdown & Re-encryption)
    let running = Arc::new(AtomicBool::new(true));
    let r = running.clone();
    ctrlc::set_handler(move || {
        r.store(false, Ordering::SeqCst);
    })?;

    while running.load(Ordering::SeqCst) {
        tokio::time::sleep(Duration::from_millis(500)).await;
    }

    // 8. Matikan seluruh service & enkripsi kembali seluruh perubahan database/moodledata
    services.stop_all(&paths);
    if paths.vault_file.exists() {
        println!("[VAULT] Mengamankan dan mengenkripsi ulang data runtime...");
        VaultContainer::seal_folders(&paths.runtime_dir, &paths.vault_file, &security.encryption_key)?;
        println!("[ENGINE] Seluruh data telah diamankan kembali ke dalam vault. Sampai jumpa!");
    } else {
        println!("[ENGINE] Seluruh layanan telah dimatikan dengan aman. Sampai jumpa!");
    }
    Ok(())
}