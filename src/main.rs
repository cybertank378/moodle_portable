mod container;
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
    println!("Aksaventra Encrypted Portable Moodle Engine v2.0");
    println!("Penggunaan: aksaventra_engine.exe [PILIHAN]");
    println!();
    println!("Pilihan:");
    println!("  --encrypt, --pack          Kemas folder mentah ke dalam brankas terenkripsi (.pak)");
    println!("  -p, --port <PORT>          Tentukan port lokal khusus (default: 8080)");
    println!("  --skip-browser             Jangan buka peramban secara otomatis");
    println!("  -h, --help                 Tampilkan bantuan ini");
}

#[tokio::main]
async fn main() -> Result<()> {
    let args: Vec<String> = env::args().collect();

    if args.iter().any(|arg| arg == "-h" || arg == "--help") {
        print_help();
        return Ok(());
    }

    println!("======================================================");
    println!("          AKSAVENTRA PORTABLE ENGINE v2.0             ");
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

    // 3. Dekripsi folder runtime dari aksaventra_vault.pak
    VaultContainer::unseal_folders(&paths.vault_file, &paths.runtime_dir, &security.encryption_key)?;

    // 4. Re-konfigurasi path secara dinamis agar 100% portabel
    paths.patch_portable_configs(app_port)?;

    // 5. Jalankan MariaDB, PHP-CGI, dan Nginx secara headless
    let mut services = ServiceOrchestrator::new();
    services.start_all(&paths, app_port)?;

    // 6. Buka aplikasi di peramban (browser) default
    let app_url = format!("http://127.0.0.1:{}", app_port);
    println!("======================================================");
    println!("   AKSAVENTRA PORTABLE LMS TELAH AKTIF & BERJALAN     ");
    println!("======================================================");
    println!("  Alamat URL Web:  {}", app_url);
    println!("  Status Layanan:  MariaDB, PHP FastCGI, Nginx (Aktif)");
    println!("======================================================");
    println!("  Tekan Ctrl+C untuk menutup dan mengamankan data.     ");
    println!("======================================================");

    if !args.iter().any(|arg| arg == "--skip-browser") {
        let _ = ServiceOrchestrator::open_browser(&app_url);
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
    println!("[VAULT] Mengamankan dan mengenkripsi ulang data runtime...");
    VaultContainer::seal_folders(&paths.runtime_dir, &paths.vault_file, &security.encryption_key)?;

    println!("[ENGINE] Seluruh data telah diamankan kembali ke dalam vault. Sampai jumpa!");
    Ok(())
}