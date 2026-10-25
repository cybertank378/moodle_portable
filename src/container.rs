use aes_gcm::{
    aead::{ Aead, KeyInit },
    Aes256Gcm, Nonce,
};
use anyhow::{ anyhow, Context, Result };
use rand::RngCore;
use std::fs::{ self, File };
use std::io::{ Read, Write };
use std::path::Path;
use tar::Archive;

const PROTECTED_DIRS: [&str; 5] = ["nginx", "php", "moodle", "moodledata", "mariadb"];
const NONCE_SIZE: usize = 12;

pub struct VaultContainer;

impl VaultContainer {
    /// Mengenkripsi 5 folder utama menjadi satu file `aksaventra_vault.pak`
    pub fn seal_folders(runtime_dir: &Path, vault_file: &Path, key: &[u8; 32]) -> Result<()> {
        println!("[VAULT] Mengamankan & mengenkripsi data (nginx, php, moodle, moodledata, mariadb)...");

        // 1. Buat Tar Archive di memori / temporary file lalu kompres dengan Zstd
        let mut tar_data = Vec::new();
        {
            let enc = zstd::Encoder::new(&mut tar_data, 3)?;
            let mut tar = tar::Builder::new(enc.auto_finish());

            for dir_name in PROTECTED_DIRS.iter() {
                let target = runtime_dir.join(dir_name);
                if target.exists() {
                    tar.append_dir_all(dir_name, &target)
                        .with_context(|| format!("Gagal mengarsipkan folder {}", dir_name))?;
                }
            }
            tar.finish()?;
        }

        // 2. Enkripsi dengan AES-256-GCM
        let cipher = Aes256Gcm::new(key.into());
        let mut nonce_bytes = [0u8; NONCE_SIZE];
        rand::thread_rng().fill_bytes(&mut nonce_bytes);
        let nonce = Nonce::from_slice(&nonce_bytes);

        let ciphertext = cipher
            .encrypt(nonce, tar_data.as_ref())
            .map_err(|_| anyhow!("Gagal melakukan enkripsi AES-256-GCM pada kontainer."))?;

        // 3. Simpan ke file (Nonce 12-byte di awal + Ciphertext)
        let mut out_file = File::create(vault_file)?;
        out_file.write_all(&nonce_bytes)?;
        out_file.write_all(&ciphertext)?;
        out_file.sync_all()?;

        // 4. Hapus folder plain-text setelah terenkripsi
        if runtime_dir.exists() {
            fs::remove_dir_all(runtime_dir)?;
        }

        println!("[VAULT] Seluruh folder berhasil dikunci ke dalam {:?}", vault_file.file_name().unwrap());
        Ok(())
    }

    /// Mendekripsi `aksaventra_vault.pak` ke folder runtime tersembunyi
    pub fn unseal_folders(vault_file: &Path, runtime_dir: &Path, key: &[u8; 32]) -> Result<()> {
        if !vault_file.exists() {
            return Err(anyhow!(
                "File brankas {:?} tidak ditemukan! Pastikan paket aplikasi lengkap.",
                vault_file
            ));
        }

        println!("[VAULT] Membuka enkripsi kontainer aplikasi...");

        let mut file = File::open(vault_file)?;
        let mut raw_data = Vec::new();
        file.read_to_end(&mut raw_data)?;

        if raw_data.len() <= NONCE_SIZE {
            return Err(anyhow!("File kontainer rusak atau korup."));
        }

        let (nonce_bytes, ciphertext) = raw_data.split_at(NONCE_SIZE);
        let cipher = Aes256Gcm::new(key.into());
        let nonce = Nonce::from_slice(nonce_bytes);

        let decrypted_zstd = cipher
            .decrypt(nonce, ciphertext)
            .map_err(|_| anyhow!("Kunci dekripsi tidak cocok atau kontainer telah dimodifikasi!"))?;

        if !runtime_dir.exists() {
            fs::create_dir_all(runtime_dir)?;
            Self::set_hidden_system_attribute(runtime_dir);
        }

        let decoder = zstd::Decoder::new(&decrypted_zstd[..])?;
        let mut archive = Archive::new(decoder);
        archive.unpack(runtime_dir).context("Gagal mengekstrak komponen dari brankas.")?;

        println!("[VAULT] Komponen berhasil dimuat ke ruang kerja terproteksi.");
        Ok(())
    }

    #[cfg(windows)]
    fn set_hidden_system_attribute(path: &Path) {
        use std::os::windows::ffi::OsStrExt;
        use winapi::um::fileapi::SetFileAttributesW;
        use winapi::um::winnt::{ FILE_ATTRIBUTE_HIDDEN, FILE_ATTRIBUTE_SYSTEM };

        let wide: Vec<u16> = path.as_os_str().encode_wide().chain(std::iter::once(0)).collect();
        unsafe {
            SetFileAttributesW(wide.as_ptr(), FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM);
        }
    }
}