mod container;
mod paths;
mod process;
mod security;

use anyhow::Result;
use container::VaultContainer;
use paths::PortablePaths;
use process::ServiceOrchestrator;
use security::SecurityManager;
use std::env;
use std::sync::atomic::{ AtomicBool, Ordering };
use std::sync::Arc;
use std::time::Duration;

const APP_PORT: u16 = 8080;

#[tokio::main]
async fn main() -> Result<()> {
    println!("======================================================");
    println!("          AKSAVENTRA PORTABLE ENGINE v2.0             ");
    println!("       Powered by Rust | aksaventra.ai.id             ");
    println!("======================================================");

    let paths = PortablePaths::init()?;

    // 1. Validasi Serial Key ke aksaventra.ai.id terlebih dahulu
    let security = SecurityManager::authenticate(&paths.base_dir).await?;

    // Mode Admin / Builder: `aksaventra_engine.exe --pack`
    // Digunakan saat pertama kali ingin mengenkripsi folder mentah menjadi aksaventra_vault.pak
    let args: Vec<String> = env::args().collect();
    if args.iter().any(|arg| arg == "--pack") {
        println!("[PACKER] Mengemas folder mentah ke dalam brankas terenkripsi...");
        VaultContainer::seal_folders(&paths.base_dir, &paths.vault_file, &security.encryption_key)?;
        println!("[PACKER] Selesai! Anda sekarang dapat mendistribusikan aksaventra_engine.exe bersama aksaventra_vault.pak.");
        return Ok(());
    }

    // 2. Dekripsi folder nginx, php, moodle, moodledata, mariadb dari aksaventra_vault.pak
    VaultContainer::unseal_folders(&paths.vault_file, &paths.runtime_dir, &security.encryption_key)?;

    // 3. Re-konfigurasi path secara dinamis agar 100% portabel
    paths.patch_portable_configs(APP_PORT)?;

    // 4. Jalankan MariaDB, PHP, dan Nginx
    let mut services = ServiceOrchestrator::new();
    services.start_all(&paths)?;

    // 5. Buka aplikasi di Browser default
    let app_url = format!("http://127.0.0.1:{}", APP_PORT);
    println!("[ENGINE] Aplikasi aktif di: {}", app_url);
    println!("[ENGINE] Tekan Ctrl+C untuk menutup aplikasi dan mengenkripsi ulang data.");
    let _ = std::process::Command::new("cmd")
        .args(["/C", "start", &app_url])
        .spawn();

    // 6. Tunggu sinyal penutupan (Ctrl+C)
    let running = Arc::new(AtomicBool::new(true));
    let r = running.clone();
    ctrlc::set_handler(move || {
        r.store(false, Ordering::SeqCst);
    })?;

    while running.load(Ordering::SeqCst) {
        tokio::time::sleep(Duration::from_millis(500)).await;
    }

    // 7. Matikan service & enkripsi kembali seluruh perubahan database/moodledata
    services.stop_all(&paths);
    VaultContainer::seal_folders(&paths.runtime_dir, &paths.vault_file, &security.encryption_key)?;

    println!("[ENGINE] Seluruh data telah diamankan kembali. Sampai jumpa!");
    Ok(())
}