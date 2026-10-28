use aes_gcm::{
    aead::{Aead, KeyInit},
    Aes256Gcm, Nonce,
};
use anyhow::{anyhow, Context, Result};
use rand::RngCore;
use reqwest::Client;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::fs;
use std::io::{self, Write};
use std::path::{Path, PathBuf};
use std::time::Duration;

pub const PRIMARY_VALIDATION_ENDPOINT: &str = "https://aksaventra.ai.id/api/v1/validate";
pub const FALLBACK_VALIDATION_ENDPOINTS: [&str; 2] = [
    "https://aksaventra.ai.id/api/v1/validate-key",
    "https://aksaventra.ai.id/api/v1/engine/validate",
];
pub const SECRET_SALT: &str = "AKSAVENTRA_PORTABLE_CORE_2026_SALT";
pub const LICENSE_FILENAME: &str = "serial_key.lic";
pub const LOCAL_TOKEN_FILENAME: &str = "aksaventra.token";
pub const LEGACY_LICENSE_FILENAME: &str = "aksaventra.lic";
pub const NONCE_SIZE: usize = 12;
pub const MAX_LICENSE_LIFETIME_SECS: u64 = 365 * 24 * 60 * 60; // Maksimal 1 tahun sejak diaktifkan (365 hari)

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
    #[serde(default)]
    pub expires_at: Option<u64>,
}

#[derive(Serialize, Deserialize, Debug, Clone, PartialEq, Eq)]
pub struct LocalLicenseToken {
    pub serial_key: String,
    pub hwid: String,
    pub client_name: Option<String>,
    pub vault_secret: String,
    pub created_at: u64,
    pub expires_at: Option<u64>,
    pub signature: String,
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

    /// Menghapus berkas lisensi dan token jika verifikasi gagal atau lisensi dicabut
    pub fn purge_license_files(base_dir: &Path) {
        let primary = base_dir.join(LICENSE_FILENAME);
        if primary.exists() {
            let _ = fs::remove_file(&primary);
        }
        let token_file = base_dir.join(LOCAL_TOKEN_FILENAME);
        if token_file.exists() {
            let _ = fs::remove_file(&token_file);
        }
        let legacy = base_dir.join(LEGACY_LICENSE_FILENAME);
        if legacy.exists() {
            let _ = fs::remove_file(&legacy);
        }
    }

    /// Menurunkan kunci AES-256 (32 byte) menggunakan SHA-256 dari seed + salt
    pub fn derive_key(seed: &str, salt: &str) -> [u8; 32] {
        let mut hasher = Sha256::new();
        hasher.update(seed.as_bytes());
        hasher.update(salt.as_bytes());
        hasher.finalize().into()
    }

    /// Menghitung tanda tangan (signature) token lisensi lokal untuk mencegah manipulasi
    pub fn calculate_signature(serial_key: &str, hwid: &str, vault_secret: &str) -> String {
        let mut hasher = Sha256::new();
        hasher.update(serial_key.as_bytes());
        hasher.update(b":");
        hasher.update(hwid.as_bytes());
        hasher.update(b":");
        hasher.update(vault_secret.as_bytes());
        hasher.update(b":");
        hasher.update(SECRET_SALT.as_bytes());
        format!("{:x}", hasher.finalize())
    }

    /// Menyimpan token lisensi terenkripsi ke media lokal (menggunakan kunci turunan HWID)
    pub fn save_local_token(base_dir: &Path, token: &LocalLicenseToken) -> Result<()> {
        let json_bytes = serde_json::to_vec(token)?;
        let key = Self::derive_key(&token.hwid, SECRET_SALT);
        let cipher = Aes256Gcm::new((&key).into());
        let mut nonce_bytes = [0u8; NONCE_SIZE];
        rand::thread_rng().fill_bytes(&mut nonce_bytes);
        let nonce = Nonce::from_slice(&nonce_bytes);

        let ciphertext = cipher
            .encrypt(nonce, json_bytes.as_slice())
            .map_err(|e| anyhow!("Gagal mengenkripsi token lokal: {:?}", e))?;

        let mut file_payload = Vec::with_capacity(NONCE_SIZE + ciphertext.len());
        file_payload.extend_from_slice(&nonce_bytes);
        file_payload.extend_from_slice(&ciphertext);

        let token_path = base_dir.join(LOCAL_TOKEN_FILENAME);
        fs::write(token_path, file_payload)?;
        Ok(())
    }

    /// Membaca dan memverifikasi token lisensi lokal (cek signature, HWID, dan masa berlaku)
    pub fn read_and_verify_local_token(base_dir: &Path, current_hwid: &str) -> Result<LocalLicenseToken> {
        let token_path = base_dir.join(LOCAL_TOKEN_FILENAME);
        if !token_path.exists() {
            return Err(anyhow!("Berkas token lisensi lokal ({}) tidak ditemukan", LOCAL_TOKEN_FILENAME));
        }

        let payload = fs::read(&token_path)?;
        if payload.len() <= NONCE_SIZE {
            return Err(anyhow!("Format berkas token lokal rusak atau tidak valid"));
        }

        let (nonce_bytes, ciphertext) = payload.split_at(NONCE_SIZE);
        let key = Self::derive_key(current_hwid, SECRET_SALT);
        let cipher = Aes256Gcm::new((&key).into());
        let nonce = Nonce::from_slice(nonce_bytes);

        let decrypted = cipher
            .decrypt(nonce, ciphertext)
            .map_err(|_| anyhow!("Dekripsi token lokal gagal (Hardware ID perangkat tidak cocok)"))?;

        let token: LocalLicenseToken = serde_json::from_slice(&decrypted)
            .context("Format data lisensi lokal rusak atau tidak valid")?;

        // 1. Verifikasi kecocokan Hardware ID
        if token.hwid != current_hwid {
            return Err(anyhow!(
                "Hardware Mismatch: HWID token ({}) berbeda dengan perangkat lokal ({})",
                token.hwid,
                current_hwid
            ));
        }

        // 2. Verifikasi Tanda Tangan (Integrity Check)
        let expected_signature = Self::calculate_signature(&token.serial_key, &token.hwid, &token.vault_secret);
        if token.signature != expected_signature {
            return Err(anyhow!("Tanda tangan (signature) token lisensi lokal tidak valid (data telah dimodifikasi)"));
        }

        // 3. Verifikasi Waktu Kadaluarsa & Batas Maksimal 1 Tahun Sejak Diaktifkan
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(0);

        let max_lifetime_exp = token.created_at.saturating_add(MAX_LICENSE_LIFETIME_SECS);
        let effective_exp = match token.expires_at {
            Some(exp) => std::cmp::min(exp, max_lifetime_exp),
            None => max_lifetime_exp,
        };

        if now > effective_exp {
            return Err(anyhow!(
                "LISENSI KADALUARSA (EXPIRED): Masa berlaku lisensi maksimal 1 tahun sejak aktivasi telah berakhir.\n\
                 Waktu aktif habis pada timestamp: {}. Silakan lakukan perpanjangan lisensi resmi di https://aksaventra.ai.id",
                effective_exp
            ));
        }

        Ok(token)
    }

    /// Mendapatkan Hardware ID unik perangkat lokal
    pub fn get_hardware_id() -> String {
        machine_uid::get().unwrap_or_else(|_| "UNKNOWN-HWID-FALLBACK".to_string())
    }

    /// Alur autentikasi lengkap sesuai diagram alir Aksaventra Engine & Server
    pub async fn authenticate(base_dir: &Path) -> Result<Self> {
        // 1. Baca Machine ID / HWID
        let hwid = Self::get_hardware_id();
        println!("[AKSAVENTRA] Machine ID (HWID): {}", hwid);

        // 2. Cek File Lisensi Lokal (Token Terenkripsi)
        let token_path = base_dir.join(LOCAL_TOKEN_FILENAME);
        if token_path.exists() {
            println!("[AKSAVENTRA] Berkas Lisensi Lokal Ditemukan ({})", LOCAL_TOKEN_FILENAME);
            match Self::read_and_verify_local_token(base_dir, &hwid) {
                Ok(cached_token) => {
                    println!("[AKSAVENTRA] Verifikasi Signature & HWID Lokal: Valid (Offline/Cached Mode).");
                    let now = std::time::SystemTime::now()
                        .duration_since(std::time::UNIX_EPOCH)
                        .map(|d| d.as_secs())
                        .unwrap_or(0);
                    let max_lifetime_exp = cached_token.created_at.saturating_add(MAX_LICENSE_LIFETIME_SECS);
                    let effective_exp = match cached_token.expires_at {
                        Some(exp) => std::cmp::min(exp, max_lifetime_exp),
                        None => max_lifetime_exp,
                    };
                    let days_left = effective_exp.saturating_sub(now) / 86400;

                    println!(
                        "[AKSAVENTRA] Lisensi Aktif: {} (Terdaftar atas nama: {})",
                        cached_token.serial_key,
                        cached_token.client_name.as_deref().unwrap_or("Pengguna Aksaventra")
                    );
                    println!("[AKSAVENTRA] Sisa Masa Aktif Lisensi: {} hari lagi", days_left);

                    let encryption_key = Self::derive_key(&cached_token.vault_secret, SECRET_SALT);
                    return Ok(Self {
                        serial_key: cached_token.serial_key,
                        hwid,
                        encryption_key,
                    });
                }
                Err(err) => {
                    let err_str = err.to_string();
                    if err_str.contains("KADALUARSA") || err_str.contains("EXPIRED") {
                        eprintln!("\n======================================================================");
                        eprintln!("        [PERINGATAN] LISENSI ANDA TELAH KADALUARSA (EXPIRED)!");
                        eprintln!("======================================================================");
                        eprintln!("  Masa aktif lisensi maksimal 1 tahun sejak aktivasi telah berakhir.");
                        eprintln!("  Sistem tidak dapat dijalankan sampai lisensi baru diperpanjang.");
                        eprintln!("  Silakan peroleh Serial Key baru di: https://aksaventra.ai.id");
                        eprintln!("======================================================================\n");
                        Self::purge_license_files(base_dir);
                        return Err(anyhow!("Lisensi telah kadaluarsa (expired). Hubungi https://aksaventra.ai.id untuk perpanjangan."));
                    } else {
                        println!("[AKSAVENTRA] Lisensi lokal tidak valid / butuh re-connect ({})", err);
                    }
                }
            }
        }

        // 3. Prompt Input Serial Key dan / atau baca dari berkas serial_key.lic
        let license_file = Self::resolve_license_path(base_dir);
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
            return Err(anyhow!(
                "Serial Key tidak boleh kosong. Silakan peroleh lisensi resmi di https://aksaventra.ai.id"
            ));
        }

        // 4. POST Request (Serial Key + HWID) ke API Endpoint
        let custom_endpoint = std::env::var("AKSAVENTRA_API_URL").ok();
        let target_endpoint = custom_endpoint
            .as_deref()
            .unwrap_or(PRIMARY_VALIDATION_ENDPOINT);

        println!("[AKSAVENTRA] Menghubungi Server Lisensi {}...", target_endpoint);

        let client = Client::builder()
            .timeout(Duration::from_secs(15))
            .build()?;

        let payload = LicenseRequest {
            serial_key: serial_key.clone(),
            hwid: hwid.clone(),
            engine_version: Some(env!("CARGO_PKG_VERSION").to_string()),
        };

        let mut response = client.post(target_endpoint).json(&payload).send().await;

        if custom_endpoint.is_none() {
            if response.is_err() || response.as_ref().map(|r| r.status().as_u16() == 404).unwrap_or(false) {
                for fallback in FALLBACK_VALIDATION_ENDPOINTS.iter() {
                    let fallback_res = client.post(*fallback).json(&payload).send().await;
                    if let Ok(resp) = fallback_res {
                        if resp.status().is_success() {
                            response = Ok(resp);
                            break;
                        }
                    }
                }
            }
        }

        let response = response.context(
            "Gagal terhubung ke server lisensi aksaventra.ai.id. Pastikan koneksi internet aktif untuk aktivasi pertama kali.",
        )?;

        let status = response.status();

        // 5. Evaluasi Status Respon Server Sesuai Diagram
        if status.as_u16() == 403 {
            Self::purge_license_files(base_dir);
            return Err(anyhow!(
                "[AKSAVENTRA ERROR] Validasi Gagal (HTTP 403: Hardware Mismatch).\n\
                 Serial Key ini telah terkunci pada perangkat keras (HWID) lain.\n\
                 Silakan hubungi administrator di https://aksaventra.ai.id untuk mereset perangkat terdaftar."
            ));
        }

        if status.as_u16() == 401 || status.as_u16() == 404 {
            Self::purge_license_files(base_dir);
            return Err(anyhow!(
                "[AKSAVENTRA ERROR] Validasi Gagal (HTTP {}: Lisensi Ditolak).\n\
                 Serial Key tidak ditemukan, kadaluarsa, atau dinonaktifkan.\n\
                 Silakan periksa kembali lisensi Anda di https://aksaventra.ai.id",
                status.as_u16()
            ));
        }

        if !status.is_success() {
            Self::purge_license_files(base_dir);
            return Err(anyhow!(
                "Validasi lisensi ditolak oleh server aksaventra.ai.id (HTTP {}).",
                status
            ));
        }

        let result: LicenseResponse = response
            .json()
            .await
            .context("Format respons lisensi dari aksaventra.ai.id tidak valid.")?;

        if !result.valid {
            Self::purge_license_files(base_dir);
            return Err(anyhow!("Lisensi Ditolak: {}", result.message));
        }

        println!(
            "[AKSAVENTRA] Lisensi Terverifikasi (200 OK)! Terdaftar atas nama: {}",
            result.client_name.as_deref().unwrap_or("Pengguna Aksaventra")
        );

        let vault_secret = result
            .vault_secret
            .unwrap_or_else(|| "DEFAULT_VAULT_PASSPHRASE".to_string());

        // 6. Simpan File Lisensi Terenkripsi Lokal (Signed Token) dengan batas maksimal 1 tahun
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(0);
        let max_allowed_exp = now.saturating_add(MAX_LICENSE_LIFETIME_SECS);
        let effective_expires_at = match result.expires_at {
            Some(exp) => Some(std::cmp::min(exp, max_allowed_exp)),
            None => Some(max_allowed_exp),
        };
        let signature = Self::calculate_signature(&serial_key, &hwid, &vault_secret);
        let local_token = LocalLicenseToken {
            serial_key: serial_key.clone(),
            hwid: hwid.clone(),
            client_name: result.client_name.clone(),
            vault_secret: vault_secret.clone(),
            created_at: now,
            expires_at: effective_expires_at,
            signature,
        };

        if let Err(e) = Self::save_local_token(base_dir, &local_token) {
            eprintln!("[AKSAVENTRA WARNING] Gagal menyimpan cache token lisensi lokal: {}", e);
        } else {
            println!("[AKSAVENTRA] Berkas Lisensi Terenkripsi Lokal Berhasil Disimpan ({})", LOCAL_TOKEN_FILENAME);
        }

        let encryption_key = Self::derive_key(&vault_secret, SECRET_SALT);

        Ok(Self {
            serial_key,
            hwid,
            encryption_key,
        })
    }

    /// Meminta input Serial Key dari pengguna di terminal
    pub fn prompt_serial_key(target_path: &Path) -> Result<String> {
        print!("[AKSAVENTRA] Masukkan Serial Key Anda: ");
        io::stdout().flush()?;
        let mut input = String::new();
        io::stdin().read_line(&mut input)?;
        let key = input.trim().to_string();
        if key.is_empty() {
            return Err(anyhow!(
                "Serial Key tidak boleh kosong. Silakan peroleh lisensi resmi di https://aksaventra.ai.id"
            ));
        }
        fs::write(target_path, &key)?;
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
            "vault_secret": "TOP_SECRET_SEED_2026",
            "expires_at": 1893456000
        }"#;

        let res: LicenseResponse = serde_json::from_str(json).expect("Harus dapat di-deserialize");
        assert!(res.valid);
        assert_eq!(res.message, "Lisensi aktif");
        assert_eq!(res.client_name.as_deref(), Some("Universitas Aksaventra"));
        assert_eq!(res.vault_secret.as_deref(), Some("TOP_SECRET_SEED_2026"));
        assert_eq!(res.expires_at, Some(1893456000));
    }

    #[test]
    fn test_local_token_encryption_decryption_and_tamper_detection() {
        let temp_dir = std::env::temp_dir().join(format!(
            "aksaventra_token_test_{}",
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir_all(&temp_dir).unwrap();

        let hwid_a = "HWID-DEVICE-AAA";
        let hwid_b = "HWID-DEVICE-BBB";
        let serial = "AKSA-PROD-2026";
        let secret = "SECRET_SEED_VAL";

        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_secs();
        let signature = SecurityManager::calculate_signature(serial, hwid_a, secret);
        let token = LocalLicenseToken {
            serial_key: serial.to_string(),
            hwid: hwid_a.to_string(),
            client_name: Some("Klien Demo".to_string()),
            vault_secret: secret.to_string(),
            created_at: now,
            expires_at: None,
            signature,
        };

        // 1. Simpan token lokal
        SecurityManager::save_local_token(&temp_dir, &token).expect("Harus dapat menyimpan token");

        // 2. Baca dengan HWID yang cocok -> Harus sukses
        let read_token = SecurityManager::read_and_verify_local_token(&temp_dir, hwid_a)
            .expect("Harus dapat membaca dan memverifikasi token yang valid");
        assert_eq!(read_token.serial_key, serial);
        assert_eq!(read_token.hwid, hwid_a);
        assert_eq!(read_token.vault_secret, secret);

        // 3. Baca dengan HWID yang berbeda -> Harus gagal (Hardware Mismatch)
        let mismatch_res = SecurityManager::read_and_verify_local_token(&temp_dir, hwid_b);
        assert!(mismatch_res.is_err(), "HWID yang berbeda harus ditolak");

        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_resolve_and_purge_license_path() {
        let temp_dir = std::env::temp_dir().join(format!(
            "aksaventra_lic_test_{}",
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir_all(&temp_dir).unwrap();

        let lic_file = temp_dir.join(LICENSE_FILENAME);
        let token_file = temp_dir.join(LOCAL_TOKEN_FILENAME);
        fs::write(&lic_file, "TEST-KEY").unwrap();
        fs::write(&token_file, "TOKEN-DATA").unwrap();

        assert_eq!(SecurityManager::resolve_license_path(&temp_dir), lic_file);

        SecurityManager::purge_license_files(&temp_dir);
        assert!(!lic_file.exists(), "Berkas serial_key.lic harus terhapus setelah purge");
        assert!(!token_file.exists(), "Berkas aksaventra.token harus terhapus setelah purge");

        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_license_expiration_validation_after_one_year() {
        let temp_dir = std::env::temp_dir().join(format!(
            "aksaventra_lic_exp_test_{}",
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir_all(&temp_dir).unwrap();

        let hwid = "HWID-EXPIRE-TEST";
        let serial = "AKSA-KEY-EXP";
        let secret = "SECRET";
        let signature = SecurityManager::calculate_signature(serial, hwid, secret);

        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_secs();

        // 1. Token dibuat lebih dari 1 tahun yang lalu
        let expired_token = LocalLicenseToken {
            serial_key: serial.to_string(),
            hwid: hwid.to_string(),
            client_name: Some("Klien Expired".to_string()),
            vault_secret: secret.to_string(),
            created_at: now.saturating_sub(MAX_LICENSE_LIFETIME_SECS + 100),
            expires_at: None,
            signature: signature.clone(),
        };

        SecurityManager::save_local_token(&temp_dir, &expired_token).unwrap();
        let res = SecurityManager::read_and_verify_local_token(&temp_dir, hwid);
        assert!(res.is_err());
        let err_msg = res.unwrap_err().to_string();
        assert!(err_msg.contains("KADALUARSA") || err_msg.contains("EXPIRED"));

        // 2. Token baru aktif (kurang dari 1 tahun) -> Harus valid
        let valid_token = LocalLicenseToken {
            serial_key: serial.to_string(),
            hwid: hwid.to_string(),
            client_name: Some("Klien Aktif".to_string()),
            vault_secret: secret.to_string(),
            created_at: now,
            expires_at: Some(now + MAX_LICENSE_LIFETIME_SECS),
            signature,
        };

        SecurityManager::save_local_token(&temp_dir, &valid_token).unwrap();
        let valid_res = SecurityManager::read_and_verify_local_token(&temp_dir, hwid);
        assert!(valid_res.is_ok());

        let _ = fs::remove_dir_all(&temp_dir);
    }
}