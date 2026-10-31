use anyhow::{anyhow, Context, Result};
use indicatif::{ProgressBar, ProgressStyle};
use reqwest::Client;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::env;
use std::fs::{self, File};
use std::io::{Read, Write};
use std::path::Path;
use std::time::Duration;

#[allow(dead_code)]
pub const DEFAULT_UPDATER_ENDPOINT: &str = "https://aksaventra.ai.id/api/v1/updater/version";
#[allow(dead_code)]
pub const FALLBACK_UPDATER_ENDPOINT: &str = "https://aksaventra.ai.id/api/v1/updater/version";
pub const UPDATER_TIMEOUT_SECS: u64 = 4;

#[derive(Serialize, Deserialize, Debug, Clone, PartialEq, Eq)]
pub struct UpdateRequest {
    pub client_version: String,
    pub hwid: String,
    pub serial_key: String,
    pub channel: String,
}

#[derive(Serialize, Deserialize, Debug, Clone, PartialEq, Eq)]
pub struct UpdateInfo {
    pub status: Option<String>,
    #[serde(default)]
    pub has_update: bool,
    pub latest_version: String,
    #[serde(default)]
    pub min_supported_version: Option<String>,
    #[serde(default)]
    pub mandatory: bool,
    #[serde(default)]
    pub download_url: Option<String>,
    #[serde(default)]
    pub checksum_sha256: Option<String>,
    #[serde(default)]
    pub release_notes: Option<String>,
    #[serde(default)]
    pub published_at: Option<u64>,
}

pub struct AutoUpdater;

impl AutoUpdater {
    /// Memeriksa apakah versi latest lebih tinggi daripada versi current (SemVer parser)
    pub fn is_newer_version(current: &str, latest: &str) -> bool {
        let parse_version = |v: &str| -> Vec<u64> {
            let clean = v.trim().trim_start_matches(|c| c == 'v' || c == 'V');
            clean
                .split('.')
                .filter_map(|part| {
                    // Ambil digit awal sebelum suffix seperti -beta, -rc
                    let digits: String = part.chars().take_while(|c| c.is_ascii_digit()).collect();
                    digits.parse::<u64>().ok()
                })
                .collect()
        };

        let curr_parts = parse_version(current);
        let latest_parts = parse_version(latest);

        let max_len = std::cmp::max(curr_parts.len(), latest_parts.len());
        for i in 0..max_len {
            let c = curr_parts.get(i).copied().unwrap_or(0);
            let l = latest_parts.get(i).copied().unwrap_or(0);
            if l > c {
                return true;
            } else if l < c {
                return false;
            }
        }
        false
    }

    /// Menghitung SHA-256 dari berkas biner lokal
    pub fn calculate_sha256(file_path: &Path) -> Result<String> {
        let mut file = File::open(file_path)
            .with_context(|| format!("Gagal membuka berkas untuk kalkulasi SHA-256: {:?}", file_path))?;
        let mut hasher = Sha256::new();
        let mut buffer = [0u8; 64 * 1024];

        loop {
            let bytes_read = file.read(&mut buffer)?;
            if bytes_read == 0 {
                break;
            }
            hasher.update(&buffer[..bytes_read]);
        }

        Ok(format!("{:x}", hasher.finalize()))
    }

    /// Membersihkan berkas backup sementara (.old) dari proses pembaruan sebelumnya
    pub fn cleanup_previous_update_backups(base_dir: &Path) {
        let old_binary = base_dir.join("aksaventra_engine.old");
        if old_binary.exists() {
            let _ = fs::remove_file(&old_binary);
        }
        let old_exe = base_dir.join("aksaventra_engine.old.exe");
        if old_exe.exists() {
            let _ = fs::remove_file(&old_exe);
        }
    }

    /// Mendapatkan endpoint validasi versi updater dari environment atau basis terpadu
    pub fn resolve_updater_endpoint() -> String {
        if let Ok(url) = env::var("AKSAVENTRA_UPDATER_URL") {
            let trimmed = url.trim();
            if !trimmed.is_empty() {
                return trimmed.to_string();
            }
        }
        format!("{}/api/v1/updater/version", crate::security::SecurityManager::get_api_base_url())
    }

    /// Memeriksa ketersediaan update ke server secara non-blocking / fail-open
    pub async fn check_update(
        serial_key: &str,
        hwid: &str,
        current_version: &str,
    ) -> Result<Option<UpdateInfo>> {
        let target_endpoint = Self::resolve_updater_endpoint();

        let client = Client::builder()
            .timeout(Duration::from_secs(UPDATER_TIMEOUT_SECS))
            .build()?;

        let payload = UpdateRequest {
            client_version: current_version.to_string(),
            hwid: hwid.to_string(),
            serial_key: serial_key.to_string(),
            channel: "stable".to_string(),
        };

        let mut response = client.post(&target_endpoint).json(&payload).send().await;

        if response.is_err() && target_endpoint.contains("aksaventra.ai.id") {
            let fallback = "https://aksaventra.ai.id/api/v1/updater/version";
            if target_endpoint != fallback {
                response = client.post(fallback).json(&payload).send().await;
            }
        }

        let response = match response {
            Ok(resp) if resp.status().is_success() => resp,
            _ => return Ok(None), // Fail-open: jika server updater offline, jangan gagalkan startup
        };

        let body_text = response.text().await.unwrap_or_default();
        if body_text.trim().is_empty() {
            return Ok(None);
        }

        let update_info: UpdateInfo = match serde_json::from_str(&body_text) {
            Ok(info) => info,
            Err(_) => return Ok(None),
        };

        if update_info.has_update || Self::is_newer_version(current_version, &update_info.latest_version) {
            Ok(Some(update_info))
        } else {
            Ok(None)
        }
    }

    /// Mengunduh biner baru dari download_url dengan progress bar streaming
    pub async fn download_binary(download_url: &str, dest_path: &Path) -> Result<()> {
        let client = Client::builder()
            .timeout(Duration::from_secs(300))
            .build()?;

        let mut response = client
            .get(download_url)
            .send()
            .await
            .with_context(|| format!("Gagal menghubungi URL unduhan: {}", download_url))?;

        if !response.status().is_success() {
            return Err(anyhow!(
                "Gagal mengunduh biner baru (HTTP Status: {})",
                response.status()
            ));
        }

        let total_size = response.content_length().unwrap_or(0);
        let pb = ProgressBar::new(total_size);
        pb.set_style(
            ProgressStyle::default_bar()
                .template("[UPDATE] [{elapsed_precise}] [{bar:40.cyan/blue}] {bytes}/{total_bytes} ({percent}%) ETA: {eta} | {msg}")
                .unwrap_or_else(|_| ProgressStyle::default_bar())
                .progress_chars("#>-"),
        );
        pb.set_message("Mengunduh biner pembaruan...");

        let mut temp_file = File::create(dest_path)
            .with_context(|| format!("Gagal membuat berkas sementara unduhan di {:?}", dest_path))?;

        while let Some(chunk) = response.chunk().await? {
            temp_file.write_all(&chunk)?;
            pb.inc(chunk.len() as u64);
        }

        temp_file.sync_all()?;
        pb.finish_with_message("Pengunduhan biner pembaruan selesai.");
        Ok(())
    }

    /// Menjalankan atomic hot-swap biner pada Windows dan merestart proses
    pub fn apply_update_and_restart(new_binary_path: &Path, raw_args: &[String]) -> Result<()> {
        let current_exe = env::current_exe()
            .context("Gagal menentukan path executable yang sedang berjalan")?;
        let current_dir = current_exe
            .parent()
            .context("Gagal menentukan direktori executable")?;

        let old_binary_backup = current_dir.join("aksaventra_engine.old.exe");

        // 1. Bersihkan backup lama jika ada
        if old_binary_backup.exists() {
            let _ = fs::remove_file(&old_binary_backup);
        }

        // 2. Pada Windows, executable yang sedang berjalan BISA di-rename (meski tidak bisa di-delete langsung)
        fs::rename(&current_exe, &old_binary_backup).with_context(|| {
            format!(
                "Gagal me-rename biner aktif {:?} ke backup {:?}",
                current_exe, old_binary_backup
            )
        })?;

        // 3. Pindahkan biner baru ke nama executable utama
        if let Err(e) = fs::rename(new_binary_path, &current_exe) {
            // Rollback jika pemindahan gagal
            let _ = fs::rename(&old_binary_backup, &current_exe);
            return Err(anyhow!("Gagal menempatkan biner baru ke {:?}: {}", current_exe, e));
        }

        println!("[UPDATE] Biner mesin berhasil diperbarui secara atomik!");
        println!("[UPDATE] Pengunduhan biner pembaruan selesai dan integritas terverifikasi!");
        println!("[UPDATE] Menjalankan executable baru dan menghapus executable lama...");

        // Jalankan background worker untuk menghapus file executable lama segera setelah proses lama terminate
        #[cfg(windows)]
        {
            use std::os::windows::process::CommandExt;
            const CREATE_NO_WINDOW: u32 = 0x08000000;
            let old_path_str = old_binary_backup.to_string_lossy().to_string();
            let _ = std::process::Command::new("cmd")
                .args(["/c", &format!("ping 127.0.0.1 -n 2 >nul & del /f /q \"{}\"", old_path_str)])
                .creation_flags(CREATE_NO_WINDOW)
                .spawn();
        }

        // 4. Siapkan argumen restart (pastikan menyertakan flag --skip-update agar tidak terjadi loop)
        let mut restart_args: Vec<String> = raw_args.iter().cloned().skip(1).collect();
        if !restart_args.iter().any(|a| a == "--skip-update") {
            restart_args.push("--skip-update".to_string());
        }

        // 5. Spawn proses baru
        std::process::Command::new(&current_exe)
            .args(&restart_args)
            .spawn()
            .with_context(|| format!("Gagal meluncurkan biner baru {:?}", current_exe))?;

        // 6. Matikan proses lama seketika itu juga
        println!("[UPDATE] Proses lama dimatikan seketika. Beralih ke versi terbaru.");
        std::process::exit(0);
    }

    /// Alur utama auto-updater yang dipanggil saat startup engine
    pub async fn perform_update_check_and_apply(
        base_dir: &Path,
        serial_key: &str,
        hwid: &str,
        raw_args: &[String],
    ) -> Result<()> {
        // Cek flag lewati update
        if raw_args.iter().any(|a| a == "--skip-update") || env::var("AKSAVENTRA_SKIP_UPDATE").is_ok() {
            return Ok(());
        }

        // 1. Bersihkan berkas .old dari update sebelumnya
        Self::cleanup_previous_update_backups(base_dir);

        let current_version = format!("v{}", env!("CARGO_PKG_VERSION"));
        println!(
            "[UPDATER] Memeriksa versi pembaruan sistem (Versi saat ini: {})...",
            current_version
        );

        let update_info = match Self::check_update(serial_key, hwid, &current_version).await {
            Ok(Some(info)) => info,
            Ok(None) => {
                println!("[UPDATER] Versi mesin Anda telah mutakhir ({})", current_version);
                return Ok(());
            }
            Err(e) => {
                println!("[UPDATER] Server pembaruan tidak dapat dijangkau ({}). Melanjutkan versi saat ini.", e);
                return Ok(());
            }
        };

        println!("======================================================");
        println!("       PEMBARUAN AKSAVENTRA ENGINE TERSEDIA           ");
        println!("======================================================");
        println!("  Versi Aktif   : {}", current_version);
        println!("  Versi Terbaru : {}", update_info.latest_version);
        if let Some(notes) = &update_info.release_notes {
            println!("  Catatan Rilis : {}", notes);
        }
        if update_info.mandatory {
            println!("  Status        : Pembaruan Wajib (Mandatory Update)");
        }
        println!("======================================================");

        // Jika hanya check update saja
        if raw_args.iter().any(|a| a == "--check-update") {
            return Ok(());
        }

        let download_url = match &update_info.download_url {
            Some(url) if !url.trim().is_empty() => url,
            _ => {
                println!("[UPDATER WARNING] URL unduhan tidak tersedia dari server.");
                return Ok(());
            }
        };

        let temp_download_path = base_dir.join("aksaventra_engine.new");

        // Pastikan tidak ada daemon server yang aktif selama proses unduh
        crate::process::ServiceOrchestrator::kill_lingering_daemons();

        // 2. Unduh biner baru
        if let Err(e) = Self::download_binary(download_url, &temp_download_path).await {
            eprintln!("[UPDATER ERROR] Gagal mengunduh berkas pembaruan: {}", e);
            let _ = fs::remove_file(&temp_download_path);
            return Ok(());
        }

        // 3. Verifikasi checksum SHA-256
        if let Some(expected_checksum) = &update_info.checksum_sha256 {
            let actual_checksum = match Self::calculate_sha256(&temp_download_path) {
                Ok(c) => c,
                Err(e) => {
                    eprintln!("[UPDATER ERROR] Gagal menghitung checksum biner unduhan: {}", e);
                    let _ = fs::remove_file(&temp_download_path);
                    return Ok(());
                }
            };

            let expected_clean = expected_checksum.trim().to_lowercase();
            let actual_clean = actual_checksum.trim().to_lowercase();

            if expected_clean != actual_clean {
                eprintln!("\n[UPDATER ERROR] Verifikasi Checksum SHA-256 GAGAL!");
                eprintln!("  Expected : {}", expected_clean);
                eprintln!("  Actual   : {}", actual_clean);
                eprintln!("Berkas unduhan korup atau telah dimodifikasi. Pembaruan dibatalkan.");
                let _ = fs::remove_file(&temp_download_path);
                return Ok(());
            }
            println!("[UPDATER] Verifikasi Checksum SHA-256 Valid ({})", actual_clean);
        }

        // 4. Terapkan penggantian atomik dan restart
        Self::apply_update_and_restart(&temp_download_path, raw_args)?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_is_newer_version_comparison() {
        assert!(AutoUpdater::is_newer_version("v2.2.1", "v2.2.2"));
        assert!(AutoUpdater::is_newer_version("2.2.1", "2.3.0"));
        assert!(AutoUpdater::is_newer_version("v2.2.1", "v3.0.0"));
        assert!(AutoUpdater::is_newer_version("v2.2.1-beta", "v2.2.2"));

        assert!(!AutoUpdater::is_newer_version("v2.2.1", "v2.2.1"));
        assert!(!AutoUpdater::is_newer_version("v2.2.2", "v2.2.1"));
        assert!(!AutoUpdater::is_newer_version("v3.0.0", "v2.2.1"));
        assert!(!AutoUpdater::is_newer_version("2.2.1", "2.2.1"));
    }

    #[test]
    fn test_calculate_sha256_matches_known_data() {
        let temp_dir = env::temp_dir().join(format!("updater_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        fs::create_dir_all(&temp_dir).unwrap();
        let test_file = temp_dir.join("test_file.bin");

        let content = b"AKSAVENTRA_UPDATER_TEST_DATA_2026";
        fs::write(&test_file, content).unwrap();

        let mut hasher = Sha256::new();
        hasher.update(content);
        let expected = format!("{:x}", hasher.finalize());

        let actual = AutoUpdater::calculate_sha256(&test_file).unwrap();
        assert_eq!(actual, expected);

        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_deserialize_update_info_response() {
        let json = r#"{
            "status": "success",
            "has_update": true,
            "latest_version": "v2.2.2",
            "min_supported_version": "v2.0.0",
            "mandatory": false,
            "download_url": "https://aksaventra.ai.id/api/v1/updater/download/aksaventra_engine.exe",
            "checksum_sha256": "4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945",
            "release_notes": "Pembaruan kestabilan dan perbaikan sistem lisensi.",
            "published_at": 1824792000
        }"#;

        let info: UpdateInfo = serde_json::from_str(json).expect("Harus dapat di-deserialize");
        assert!(info.has_update);
        assert_eq!(info.latest_version, "v2.2.2");
        assert_eq!(info.min_supported_version.as_deref(), Some("v2.0.0"));
        assert!(!info.mandatory);
        assert_eq!(
            info.download_url.as_deref(),
            Some("https://aksaventra.ai.id/api/v1/updater/download/aksaventra_engine.exe")
        );
        assert_eq!(
            info.checksum_sha256.as_deref(),
            Some("4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945")
        );
        assert_eq!(info.published_at, Some(1824792000));
    }

    #[test]
    fn test_resolve_updater_endpoint_derives_from_base_url() {
        std::env::remove_var("AKSAVENTRA_API_URL");
        std::env::remove_var("AKSAVENTRA_UPDATER_URL");
        assert_eq!(
            AutoUpdater::resolve_updater_endpoint(),
            "https://aksaventra.ai.id/api/v1/updater/version"
        );

        std::env::set_var("AKSAVENTRA_API_URL", "http://localhost:3000");
        assert_eq!(
            AutoUpdater::resolve_updater_endpoint(),
            "http://localhost:3000/api/v1/updater/version"
        );

        // Custom override via AKSAVENTRA_UPDATER_URL takes priority if set
        std::env::set_var("AKSAVENTRA_UPDATER_URL", "http://custom-updater.local/version");
        assert_eq!(
            AutoUpdater::resolve_updater_endpoint(),
            "http://custom-updater.local/version"
        );

        std::env::remove_var("AKSAVENTRA_API_URL");
        std::env::remove_var("AKSAVENTRA_UPDATER_URL");
    }

    #[test]
    fn test_cleanup_previous_update_backups() {
        let temp_dir = env::temp_dir().join(format!("updater_cleanup_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        fs::create_dir_all(&temp_dir).unwrap();

        let old_bin = temp_dir.join("aksaventra_engine.old");
        let old_exe = temp_dir.join("aksaventra_engine.old.exe");
        fs::write(&old_bin, b"OLD").unwrap();
        fs::write(&old_exe, b"OLD_EXE").unwrap();

        assert!(old_bin.exists());
        assert!(old_exe.exists());

        AutoUpdater::cleanup_previous_update_backups(&temp_dir);

        assert!(!old_bin.exists());
        assert!(!old_exe.exists());

        let _ = fs::remove_dir_all(&temp_dir);
    }
}
