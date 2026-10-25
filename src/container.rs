use aes_gcm::{
    aead::{Aead, KeyInit},
    Aes256Gcm, Nonce,
};
use anyhow::{anyhow, Context, Result};
use rand::RngCore;
use std::fs::{self, File};
use std::io::{Read, Write};
use std::path::Path;
use tar::Archive;

pub const PROTECTED_DIRS: [&str; 5] = ["nginx", "php", "moodle", "moodledata", "mariadb"];
pub const NONCE_SIZE: usize = 12;

pub struct VaultContainer;

impl VaultContainer {
    /// Mengarsipkan dan mengenkripsi satu folder individual ke berkas .pak
    #[allow(dead_code)]
    pub fn encrypt_folder(folder_path: &Path, output_pak: &Path, key: &[u8; 32]) -> Result<()> {
        if !folder_path.exists() {
            return Err(anyhow!("Folder {:?} tidak ditemukan untuk dienkripsi.", folder_path));
        }

        let folder_name = folder_path
            .file_name()
            .and_then(|n| n.to_str())
            .unwrap_or("content");

        // 1. Tar archive + kompresi Zstd
        let mut compressed_tar = Vec::new();
        {
            let encoder = zstd::Encoder::new(&mut compressed_tar, 3)?;
            let mut tar = tar::Builder::new(encoder.auto_finish());
            tar.append_dir_all(folder_name, folder_path)
                .with_context(|| format!("Gagal mengarsipkan folder {:?}", folder_path))?;
            tar.finish()?;
        }

        // 2. Enkripsi AES-256-GCM
        let cipher = Aes256Gcm::new(key.into());
        let mut nonce_bytes = [0u8; NONCE_SIZE];
        rand::thread_rng().fill_bytes(&mut nonce_bytes);
        let nonce = Nonce::from_slice(&nonce_bytes);

        let ciphertext = cipher
            .encrypt(nonce, compressed_tar.as_ref())
            .map_err(|_| anyhow!("Enkripsi AES-256-GCM gagal untuk folder {:?}", folder_name))?;

        // 3. Simpan ke berkas .pak (Nonce 12-byte + Ciphertext terotentikasi)
        if let Some(parent) = output_pak.parent() {
            if !parent.exists() {
                fs::create_dir_all(parent)?;
                Self::set_hidden_system_attribute(parent);
            }
        }

        let mut out_file = File::create(output_pak)?;
        out_file.write_all(&nonce_bytes)?;
        out_file.write_all(&ciphertext)?;
        out_file.sync_all()?;

        Ok(())
    }

    /// Mendekripsi berkas .pak dan mengekstrak isinya ke target direktori
    #[allow(dead_code)]
    pub fn decrypt_folder(pak_file: &Path, output_dir: &Path, key: &[u8; 32]) -> Result<()> {
        if !pak_file.exists() {
            return Err(anyhow!("Berkas kontainer {:?} tidak ditemukan.", pak_file));
        }

        let mut file = File::open(pak_file)?;
        let mut raw_data = Vec::new();
        file.read_to_end(&mut raw_data)?;

        if raw_data.len() <= NONCE_SIZE {
            return Err(anyhow!("Berkas kontainer {:?} korup atau tidak valid.", pak_file));
        }

        let (nonce_bytes, ciphertext) = raw_data.split_at(NONCE_SIZE);
        let cipher = Aes256Gcm::new(key.into());
        let nonce = Nonce::from_slice(nonce_bytes);

        let decrypted_bytes = cipher
            .decrypt(nonce, ciphertext)
            .map_err(|_| anyhow!("Dekripsi gagal: Kunci lisensi salah atau berkas telah dirusak!"))?;

        if !output_dir.exists() {
            fs::create_dir_all(output_dir)?;
            Self::set_hidden_system_attribute(output_dir);
        }

        let decoder = zstd::Decoder::new(&decrypted_bytes[..])?;
        let mut archive = Archive::new(decoder);
        archive.unpack(output_dir).context("Gagal mengekstrak arsip dari kontainer.")?;

        Ok(())
    }

    /// Mengenkripsi seluruh folder utama (nginx, php, moodle, moodledata, mariadb)
    /// Mendukung penyimpanan ke file tunggal (.pak) atau folder brankas (.vault/<nama>.pak)
    #[allow(dead_code)]
    pub fn encrypt_all(source_dir: &Path, vault_target: &Path, key: &[u8; 32]) -> Result<()> {
        Self::seal_folders(source_dir, vault_target, key)
    }

    /// Mendekripsi seluruh folder utama dari brankas ke direktori kerja aplikasi
    #[allow(dead_code)]
    pub fn decrypt_all(vault_source: &Path, dest_dir: &Path, key: &[u8; 32]) -> Result<()> {
        Self::unseal_folders(vault_source, dest_dir, key)
    }

    /// Mengenkripsi seluruh 5 folder utama ke dalam brankas terpadu aksaventra_vault.pak
    pub fn seal_folders(runtime_dir: &Path, vault_file: &Path, key: &[u8; 32]) -> Result<()> {
        println!("[VAULT] Mengamankan & mengenkripsi data (nginx, php, moodle, moodledata, mariadb)...");

        // 1. Buat Tar Archive di memori / temporary buffer lalu kompres dengan Zstd
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

        // 3. Pastikan direktori tujuan tersedia dan terproteksi
        if let Some(parent) = vault_file.parent() {
            if !parent.exists() {
                fs::create_dir_all(parent)?;
                Self::set_hidden_system_attribute(parent);
            }
        }

        // 4. Simpan ke file (Nonce 12-byte di awal + Ciphertext)
        let mut out_file = File::create(vault_file)?;
        out_file.write_all(&nonce_bytes)?;
        out_file.write_all(&ciphertext)?;
        out_file.sync_all()?;

        // 5. Hapus folder plain-text setelah enkripsi sukses (jika berbeda dari vault_file parent)
        for dir_name in PROTECTED_DIRS.iter() {
            let target = runtime_dir.join(dir_name);
            if target.exists() {
                let _ = fs::remove_dir_all(&target);
            }
        }

        println!(
            "[VAULT] Seluruh folder berhasil dikunci ke dalam {:?}",
            vault_file.file_name().unwrap_or_default()
        );
        Ok(())
    }

    /// Mendekripsi berkas brankas terpadu aksaventra_vault.pak ke folder runtime
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

    /// Menerapkan atribut tersembunyi dan sistem (Hidden + System) pada Windows
    #[cfg(windows)]
    pub fn set_hidden_system_attribute(path: &Path) {
        use std::os::windows::ffi::OsStrExt;
        use winapi::um::fileapi::SetFileAttributesW;
        use winapi::um::winnt::{FILE_ATTRIBUTE_HIDDEN, FILE_ATTRIBUTE_SYSTEM};

        let wide: Vec<u16> = path.as_os_str().encode_wide().chain(std::iter::once(0)).collect();
        unsafe {
            SetFileAttributesW(wide.as_ptr(), FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM);
        }
    }

    #[cfg(not(windows))]
    pub fn set_hidden_system_attribute(_path: &Path) {
        // No-op pada OS non-Windows
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_protected_dirs_constant() {
        assert_eq!(PROTECTED_DIRS.len(), 5);
        assert!(PROTECTED_DIRS.contains(&"nginx"));
        assert!(PROTECTED_DIRS.contains(&"php"));
        assert!(PROTECTED_DIRS.contains(&"moodle"));
        assert!(PROTECTED_DIRS.contains(&"moodledata"));
        assert!(PROTECTED_DIRS.contains(&"mariadb"));
    }

    #[test]
    fn test_encrypt_and_decrypt_folder_roundtrip() {
        let timestamp = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let base_temp = std::env::temp_dir().join(format!("aksaventra_vault_test_{}", timestamp));
        let src_folder = base_temp.join("moodle");
        let pak_file = base_temp.join("moodle.pak");
        let dest_folder = base_temp.join("extracted");

        fs::create_dir_all(&src_folder).unwrap();
        fs::write(src_folder.join("version.php"), "<?php $version = '2026';").unwrap();
        fs::create_dir_all(src_folder.join("admin")).unwrap();
        fs::write(src_folder.join("admin").join("cli.php"), "echo 'ok';").unwrap();

        let key = [42u8; 32];

        // Enkripsi
        VaultContainer::encrypt_folder(&src_folder, &pak_file, &key)
            .expect("Enkripsi folder harus berhasil");
        assert!(pak_file.exists());

        // Dekripsi
        VaultContainer::decrypt_folder(&pak_file, &dest_folder, &key)
            .expect("Dekripsi folder harus berhasil");

        let extracted_file = dest_folder.join("moodle").join("version.php");
        assert!(extracted_file.exists());
        let content = fs::read_to_string(&extracted_file).unwrap();
        assert_eq!(content, "<?php $version = '2026';");

        let cli_file = dest_folder.join("moodle").join("admin").join("cli.php");
        assert!(cli_file.exists());

        let _ = fs::remove_dir_all(&base_temp);
    }

    #[test]
    fn test_wrong_key_fails_decryption() {
        let timestamp = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let base_temp = std::env::temp_dir().join(format!("aksaventra_fail_test_{}", timestamp));
        let src_folder = base_temp.join("secret_data");
        let pak_file = base_temp.join("secret.pak");
        let dest_folder = base_temp.join("out");

        fs::create_dir_all(&src_folder).unwrap();
        fs::write(src_folder.join("data.txt"), "Rahasia Perusahaan").unwrap();

        let key_correct = [7u8; 32];
        let key_wrong = [9u8; 32];

        VaultContainer::encrypt_folder(&src_folder, &pak_file, &key_correct).unwrap();

        // Dekripsi dengan kunci yang salah harus Err
        let result = VaultContainer::decrypt_folder(&pak_file, &dest_folder, &key_wrong);
        assert!(result.is_err(), "Dekripsi dengan kunci salah wajib gagal");

        let _ = fs::remove_dir_all(&base_temp);
    }

    #[test]
    fn test_tampered_ciphertext_fails_decryption() {
        let timestamp = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let base_temp = std::env::temp_dir().join(format!("aksaventra_tamper_test_{}", timestamp));
        let src_folder = base_temp.join("tamper_dir");
        let pak_file = base_temp.join("tamper.pak");
        let dest_folder = base_temp.join("out");

        fs::create_dir_all(&src_folder).unwrap();
        fs::write(src_folder.join("file.txt"), "Integritas data").unwrap();

        let key = [13u8; 32];
        VaultContainer::encrypt_folder(&src_folder, &pak_file, &key).unwrap();

        // Manipulasi berkas .pak (rusak byte ciphertext)
        let mut bytes = fs::read(&pak_file).unwrap();
        let last_idx = bytes.len() - 1;
        bytes[last_idx] ^= 0xFF; // Ubah 1 bit
        fs::write(&pak_file, bytes).unwrap();

        let result = VaultContainer::decrypt_folder(&pak_file, &dest_folder, &key);
        assert!(result.is_err(), "Data yang dimanipulasi harus ditolak oleh tag otentikasi GCM");

        let _ = fs::remove_dir_all(&base_temp);
    }

    #[test]
    fn test_seal_and_unseal_folders_roundtrip() {
        let timestamp = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let base_temp = std::env::temp_dir().join(format!("aksaventra_seal_test_{}", timestamp));
        let runtime_src = base_temp.join("runtime_src");
        let vault_file = base_temp.join("aksaventra_vault.pak");
        let runtime_dest = base_temp.join("runtime_dest");

        // Buat folder tiruan untuk 5 komponen
        for dir in PROTECTED_DIRS.iter() {
            let path = runtime_src.join(dir);
            fs::create_dir_all(&path).unwrap();
            fs::write(path.join("manifest.txt"), format!("Component {}", dir)).unwrap();
        }

        let key = [99u8; 32];

        // Seal
        VaultContainer::seal_folders(&runtime_src, &vault_file, &key)
            .expect("seal_folders harus sukses");
        assert!(vault_file.exists());

        // Unseal
        VaultContainer::unseal_folders(&vault_file, &runtime_dest, &key)
            .expect("unseal_folders harus sukses");

        // Pastikan seluruh 5 folder ada di tujuan
        for dir in PROTECTED_DIRS.iter() {
            let extracted_file = runtime_dest.join(dir).join("manifest.txt");
            assert!(extracted_file.exists(), "Folder {} harus ada setelah unseal", dir);
            let content = fs::read_to_string(&extracted_file).unwrap();
            assert_eq!(content, format!("Component {}", dir));
        }

        let _ = fs::remove_dir_all(&base_temp);
    }
}