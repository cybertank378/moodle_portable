use anyhow::{anyhow, Context, Result};
use reqwest::Client;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::fs;
use std::io::{self, Write};
use std::path::{Path, PathBuf};
use std::time::Duration;

pub const PRIMARY_VALIDATION_ENDPOINT: &str = "https://aksaventra.ai.id/api/v1/validate-key";
pub const FALLBACK_VALIDATION_ENDPOINT: &str = "https://aksaventra.ai.id/api/v1/engine/validate";
pub const SECRET_SALT: &str = "AKSAVENTRA_PORTABLE_CORE_2026_SALT";
pub const LICENSE_FILENAME: &str = "serial_key.lic";
pub const LEGACY_LICENSE_FILENAME: &str = "aksaventra.lic";

#[derive(Serialize, Deserialize, Debug, Clone, PartialEq, Eq)]
pub struct LicenseRequest {
    pub serial_key: String,
    pub hwid: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub engine_version: Option<String>,
}

#[derive(Deserialize, Serialize, Debug, Clone)]
pub struct LicenseResponse {
    pub valid: bool,
    pub message: String,
    pub client_name: Option<String>,
    pub vault_secret: Option<String>, // Seed rahasia untuk dekripsi vault
}

#[allow(dead_code)]
#[derive(Clone, Debug)]
pub struct SecurityManager {
    pub serial_key: String,
    pub hwid: String,
    pub encryption_key: [u8; 32],
}

impl SecurityManager {
    /// Menemukan path berkas lisensi (mengutamakan serial_key.lic, fallback ke aksaventra.lic)
    pub fn resolve_license_path(base_dir: &Path) -> PathBuf {
        let primary = base_dir.join(LICENSE_FILENAME);
        if primary.exists() {
            return primary;
        }
        let legacy = base_dir.join(LEGACY_LICENSE_FILENAME);
        if legacy.exists() {
            return legacy;
        }
        primary
    }

    /// Menghapus berkas lisensi jika verifikasi gagal atau lisensi dicabut
    pub fn purge_license_files(base_dir: &Path) {
        let primary = base_dir.join(LICENSE_FILENAME);
        if primary.exists() {
            let _ = fs::remove_file(&primary);
        }
        let legacy = base_dir.join(LEGACY_LICENSE_FILENAME);
        if legacy.exists() {
            let _ = fs::remove_file(&legacy);
        }
    }

    /// Menurunkan kunci AES-256 (32 byte) menggunakan SHA-256 dari seed server + salt
    pub fn derive_key(server_seed: &str, salt: &str) -> [u8; 32] {
        let mut hasher = Sha256::new();
        hasher.update(server_seed.as_bytes());
        hasher.update(salt.as_bytes());
        hasher.finalize().into()
    }

    /// Mendapatkan Hardware ID unik perangkat lokal
    pub fn get_hardware_id() -> String {
        machine_uid::get().unwrap_or_else(|_| "UNKNOWN-HWID-FALLBACK".to_string())
    }

    /// Melakukan autentikasi lisensi ke server aksaventra.ai.id
    pub async fn authenticate(base_dir: &Path) -> Result<Self> {
        let license_file = Self::resolve_license_path(base_dir);
        let hwid = Self::get_hardware_id();

        let serial_key = if license_file.exists() {
            let content = fs::read_to_string(&license_file)?.trim().to_string();
            if content.is_empty() {
                Self::prompt_serial_key(&license_file)?
            } else {
                content
            }
        } else {
            Self::prompt_serial_key(&license_file)?
        };

        if serial_key.is_empty() {
            Self::purge_license_files(base_dir);
            return Err(anyhow!("Serial Key tidak boleh kosong."));
        }

        println!("[AKSAVENTRA] Memvalidasi lisensi ke aksaventra.ai.id...");
        println!("[AKSAVENTRA] Perangkat HWID: {}", hwid);

        let client = Client::builder()
            .timeout(Duration::from_secs(15))
            .build()?;

        let payload = LicenseRequest {
            serial_key: serial_key.clone(),
            hwid: hwid.clone(),
            engine_version: Some(env!("CARGO_PKG_VERSION").to_string()),
        };

        // Kirim request ke endpoint validasi utama, jika gagal/404 coba endpoint fallback
        let response = match client
            .post(PRIMARY_VALIDATION_ENDPOINT)
            .json(&payload)
            .send()
            .await
        {
            Ok(resp) if resp.status().is_success() => Ok(resp),
            Ok(resp) if resp.status().as_u16() == 404 => {
                // Coba fallback endpoint
                client
                    .post(FALLBACK_VALIDATION_ENDPOINT)
                    .json(&payload)
                    .send()
                    .await
            }
            Ok(resp) => Ok(resp),
            Err(e) => {
                // Coba fallback jika jaringan error ke primary
                match client.post(FALLBACK_VALIDATION_ENDPOINT).json(&payload).send().await {
                    Ok(resp) => Ok(resp),
                    Err(_) => Err(e),
                }
            }
        };

        let response = response.context(
            "Gagal terhubung ke server lisensi aksaventra.ai.id. Pastikan koneksi internet aktif.",
        )?;

        if !response.status().is_success() {
            Self::purge_license_files(base_dir);
            return Err(anyhow!(
                "Validasi lisensi ditolak oleh server aksaventra.ai.id (HTTP {}).",
                response.status()
            ));
        }

        let result: LicenseResponse = response
            .json()
            .await
            .context("Format respons lisensi dari aksaventra.ai.id tidak valid.")?;

        if !result.valid {
            Self::purge_license_files(base_dir);
            return Err(anyhow!("Lisensi Tidak Valid: {}", result.message));
        }

        println!(
            "[AKSAVENTRA] Lisensi Terverifikasi! Terdaftar atas nama: {}",
            result.client_name.as_deref().unwrap_or("Pengguna Aksaventra")
        );

        // Turunkan kunci AES-256 (32 byte) dari vault_secret server + salt rahasia
        let server_secret = result
            .vault_secret
            .unwrap_or_else(|| "DEFAULT_VAULT_PASSPHRASE".to_string());
        let encryption_key = Self::derive_key(&server_secret, SECRET_SALT);

        Ok(Self {
            serial_key,
            hwid,
            encryption_key,
        })
    }

    /// Meminta input Serial Key dari pengguna di terminal secara interaktif
    fn prompt_serial_key(target_path: &Path) -> Result<String> {
        print!("[AKSAVENTRA] Masukkan Serial Key Anda: ");
        io::stdout().flush()?;
        let mut input = String::new();
        io::stdin().read_line(&mut input)?;
        let key = input.trim().to_string();
        if !key.is_empty() {
            fs::write(target_path, &key)?;
        }
        Ok(key)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_derive_key_deterministic_and_unique() {
        let key1 = SecurityManager::derive_key("SERVER_SECRET_A", SECRET_SALT);
        let key2 = SecurityManager::derive_key("SERVER_SECRET_A", SECRET_SALT);
        let key3 = SecurityManager::derive_key("SERVER_SECRET_B", SECRET_SALT);

        assert_eq!(key1, key2, "Kunci dengan seed yang sama harus identik");
        assert_ne!(key1, key3, "Kunci dengan seed berbeda tidak boleh sama");
        assert_eq!(key1.len(), 32, "Kunci harus tepat 256-bit (32 byte)");
    }

    #[test]
    fn test_license_request_serialization() {
        let req = LicenseRequest {
            serial_key: "TEST-1234-5678".to_string(),
            hwid: "HWID-TEST-001".to_string(),
            engine_version: None,
        };

        let json = serde_json::to_string(&req).expect("Harus dapat di-serialize ke JSON");
        assert!(json.contains("\"serial_key\":\"TEST-1234-5678\""));
        assert!(json.contains("\"hwid\":\"HWID-TEST-001\""));
        assert!(!json.contains("engine_version"));
    }

    #[test]
    fn test_license_response_deserialization() {
        let json = r#"{
            "valid": true,
            "message": "Lisensi aktif",
            "client_name": "Universitas Aksaventra",
            "vault_secret": "TOP_SECRET_SEED_2026"
        }"#;

        let res: LicenseResponse = serde_json::from_str(json).expect("Harus dapat di-deserialize");
        assert!(res.valid);
        assert_eq!(res.message, "Lisensi aktif");
        assert_eq!(res.client_name.as_deref(), Some("Universitas Aksaventra"));
        assert_eq!(res.vault_secret.as_deref(), Some("TOP_SECRET_SEED_2026"));
    }

    #[test]
    fn test_resolve_and_purge_license_path() {
        let temp_dir = std::env::temp_dir().join(format!("aksaventra_lic_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        fs::create_dir_all(&temp_dir).unwrap();

        let lic_file = temp_dir.join(LICENSE_FILENAME);
        fs::write(&lic_file, "TEST-KEY").unwrap();

        assert_eq!(SecurityManager::resolve_license_path(&temp_dir), lic_file);

        SecurityManager::purge_license_files(&temp_dir);
        assert!(!lic_file.exists(), "Berkas lisensi harus terhapus setelah purge");

        let _ = fs::remove_dir_all(&temp_dir);
    }
}