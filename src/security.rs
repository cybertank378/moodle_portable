use anyhow::{ anyhow, Context, Result };
use reqwest::Client;
use serde::{ Deserialize, Serialize };
use sha2::{ Digest, Sha256 };
use std::fs;
use std::io::{ self, Write };
use std::path::Path;
use std::time::Duration;

const VALIDATION_ENDPOINT: &str = "https://aksaventra.ai.id/api/v1/engine/validate";
const SECRET_SALT: &str = "AKSAVENTRA_PORTABLE_CORE_2026_SALT";

#[derive(Serialize)]
struct LicenseRequest {
    serial_key: String,
    hwid: String,
    engine_version: String,
}

#[derive(Deserialize)]
pub struct LicenseResponse {
    pub valid: bool,
    pub message: String,
    pub client_name: Option<String>,
    pub vault_secret: Option<String>, // Dikirim server jika serial_key valid
}

pub struct SecurityManager {
    pub serial_key: String,
    pub hwid: String,
    pub encryption_key: [u8; 32],
}

impl SecurityManager {
    pub async fn authenticate(base_dir: &Path) -> Result<Self> {
        let license_file = base_dir.join("aksaventra.lic");
        let hwid = machine_uid::get().unwrap_or_else(|_| "UNKNOWN-HWID-FALLBACK".to_string());

        let serial_key = if license_file.exists() {
            fs::read_to_string(&license_file)?.trim().to_string()
        } else {
            print!("[AKSAVENTRA] Masukkan Serial Key Anda: ");
            io::stdout().flush()?;
            let mut input = String::new();
            io::stdin().read_line(&mut input)?;
            let key = input.trim().to_string();
            fs::write(&license_file, &key)?;
            key
        };

        println!("[AKSAVENTRA] Memvalidasi lisensi ke aksaventra.ai.id...");

        let client = Client::builder()
            .timeout(Duration::from_secs(15))
            .build()?;

        let payload = LicenseRequest {
            serial_key: serial_key.clone(),
            hwid: hwid.clone(),
            engine_version: env!("CARGO_PKG_VERSION").to_string(),
        };

        let response = client
            .post(VALIDATION_ENDPOINT)
            .json(&payload)
            .send()
            .await
            .context("Gagal terhubung ke server lisensi aksaventra.ai.id. Pastikan koneksi internet aktif.")?;

        if !response.status().is_success() {
            let _ = fs::remove_file(&license_file);
            return Err(anyhow!("Validasi ditolak oleh server (HTTP {}).", response.status()));
        }

        let result: LicenseResponse = response
            .json()
            .await
            .context("Format respons dari aksaventra.ai.id tidak valid.")?;

        if !result.valid {
            let _ = fs::remove_file(&license_file);
            return Err(anyhow!("Lisensi Tidak Valid: {}", result.message));
        }

        println!(
            "[AKSAVENTRA] Lisensi Terverifikasi! Lisensi milik: {}",
            result.client_name.unwrap_or_else(|| "Registered User".to_string())
        );

        // Turunkan kunci AES-256 (32 byte) dari kombinasi rahasia server + salt
        let server_secret = result
            .vault_secret
            .unwrap_or_else(|| "DEFAULT_VAULT_PASSPHRASE".to_string());
        
        let mut hasher = Sha256::new();
        hasher.update(server_secret.as_bytes());
        hasher.update(SECRET_SALT.as_bytes());
        let encryption_key: [u8; 32] = hasher.finalize().into();

        Ok(Self {
            serial_key,
            hwid,
            encryption_key,
        })
    }
}