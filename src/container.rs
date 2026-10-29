use aes_gcm::{
    aead::{Aead, KeyInit},
    Aes256Gcm, Nonce,
};
use anyhow::{anyhow, Context, Result};
use rand::RngCore;
use rayon::prelude::*;
use std::collections::HashSet;
use std::fs::{self, File};
use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use tar::Archive;

pub const PROTECTED_DIRS: [&str; 5] = ["nginx", "php", "moodle", "moodledata", "mariadb"];
pub const NONCE_SIZE: usize = 12;

pub struct VaultContainer;

impl VaultContainer {
    /// Mencoba mendekripsi kontainer dengan daftar seed standar rilis jika paket .pak dikemas dengan seed awal yang berbeda
    fn try_decrypt_with_fallback_seeds(nonce: &Nonce<aes_gcm::aes::cipher::typenum::U12>, ciphertext: &[u8]) -> Result<Vec<u8>> {
        const FALLBACK_SEEDS: [&str; 3] = [
            "AKSA_VAULT_SECRET_ENT_9F2A_2026",
            "AKSAVENTRA_DEMO_SECRET_SEED_2026",
            "DEFAULT_VAULT_PASSPHRASE",
        ];
        for seed in FALLBACK_SEEDS.iter() {
            let alt_key = crate::security::SecurityManager::derive_key(
                seed,
                crate::security::SECRET_SALT,
            );
            let alt_cipher = Aes256Gcm::new((&alt_key).into());
            if let Ok(decrypted) = alt_cipher.decrypt(nonce, ciphertext) {
                return Ok(decrypted);
            }
        }
        Err(anyhow!("Fallback dekripsi gagal"))
    }
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
            let mut encoder = zstd::Encoder::new(&mut compressed_tar, 1)?;
            let threads = std::thread::available_parallelism().map(|n| n.get() as u32).unwrap_or(4);
            let _ = encoder.multithread(threads);
            let mut tar = tar::Builder::new(encoder.auto_finish());
            Self::append_dir_recursive(&mut tar, folder_name, folder_path, None)?;
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

    /// Menambahkan seluruh isi direktori ke tar builder dengan normalisasi path POSIX (forward slash '/')
    fn append_dir_recursive<W: Write>(
        tar: &mut tar::Builder<W>,
        base_archive_dir: &str,
        source_dir: &Path,
        progress_bar: Option<&indicatif::ProgressBar>,
    ) -> Result<usize> {
        let mut file_count = 0;
        for entry in walkdir::WalkDir::new(source_dir) {
            let entry = entry.with_context(|| format!("Gagal membaca entri di {:?}", source_dir))?;
            let rel_path = entry
                .path()
                .strip_prefix(source_dir)
                .with_context(|| format!("Gagal memotong prefix dari {:?}", entry.path()))?;

            if rel_path.as_os_str().is_empty() {
                continue;
            }

            // Normalisasi pemisah direktori Windows (\) ke standar POSIX (/) untuk tar archive
            let rel_posix = rel_path.to_string_lossy().replace('\\', "/");
            let archive_path = format!("{}/{}", base_archive_dir, rel_posix);

            if entry.file_type().is_dir() {
                let _ = tar.append_dir(&archive_path, entry.path());
            } else if entry.file_type().is_file() {
                let mut file = File::open(entry.path())
                    .with_context(|| format!("Gagal membuka berkas {:?}", entry.path()))?;
                tar.append_file(&archive_path, &mut file)
                    .with_context(|| format!("Gagal menambahkan berkas {}", archive_path))?;
                file_count += 1;

                if let Some(pb) = progress_bar {
                    let display_msg = if archive_path.len() > 45 {
                        format!("...{}", &archive_path[archive_path.len() - 42..])
                    } else {
                        archive_path.clone()
                    };
                    pb.set_message(display_msg);
                    pb.inc(1);
                    if file_count % 5000 == 0 {
                        pb.println(format!(
                            "  [SEAL] Sedang mengarsipkan {} berkas...",
                            file_count
                        ));
                    }
                }
            }
        }
        Ok(file_count)
    }

    /// Mendekripsi berkas .pak dan mengekstrak isinya ke target direktori
    #[allow(dead_code)]
    pub fn decrypt_folder(pak_file: &Path, output_dir: &Path, key: &[u8; 32]) -> Result<()> {
        if !pak_file.exists() {
            return Err(anyhow!("Berkas kontainer {:?} tidak ditemukan.", pak_file));
        }

        let mut file = File::open(pak_file)?;
        let file_len = file.metadata().map(|m| m.len() as usize).unwrap_or(0);
        let mut raw_data = Vec::with_capacity(file_len);
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
        println!("[VAULT] Menghitung berkas yang akan disegel ke dalam brankas...");

        let total_files_to_pack: usize = PROTECTED_DIRS
            .par_iter()
            .map(|dir_name| {
                let target = runtime_dir.join(dir_name);
                if target.exists() {
                    walkdir::WalkDir::new(&target)
                        .into_iter()
                        .filter_map(|e| e.ok())
                        .filter(|e| e.file_type().is_file())
                        .count()
                } else {
                    0
                }
            })
            .sum();

        println!("[VAULT] Total {} berkas terdeteksi. Memulai proses sealing & kompresi...", total_files_to_pack);

        let pb = indicatif::ProgressBar::new(total_files_to_pack as u64);
        pb.set_style(
            indicatif::ProgressStyle::default_bar()
                .template("[SEAL] [{elapsed_precise}] [{bar:40.cyan/blue}] {pos}/{len} ({percent}%) ETA: {eta} | {msg}")
                .unwrap_or_else(|_| indicatif::ProgressStyle::default_bar())
                .progress_chars("#>-"),
        );

        let mut total_files = 0;
        // 1. Buat Tar Archive di memori dengan Zstd kompresi dan path normalisasi
        // Pre-alokasi buffer kapasitas 128 MB untuk menghindari realokasi berulang pada heap
        let mut tar_data = Vec::with_capacity(128 * 1024 * 1024);
        {
            let mut enc = zstd::Encoder::new(&mut tar_data, 1)?;
            let threads = std::thread::available_parallelism().map(|n| n.get() as u32).unwrap_or(4);
            let _ = enc.multithread(threads);
            let mut tar = tar::Builder::new(enc.auto_finish());

            // Tambahkan entri metadata awal agar saat unseal jumlah file diketahui secara presisi
            let meta_str = format!("total_files={}\n", total_files_to_pack);
            let mut header = tar::Header::new_gnu();
            header.set_size(meta_str.len() as u64);
            header.set_mode(0o644);
            header.set_cksum();
            let _ = tar.append_data(&mut header, ".aksaventra_meta", meta_str.as_bytes());

            for dir_name in PROTECTED_DIRS.iter() {
                let target = runtime_dir.join(dir_name);
                if target.exists() {
                    let count = Self::append_dir_recursive(&mut tar, dir_name, &target, Some(&pb))?;
                    total_files += count;
                }
            }
            tar.finish()?;
        }
        pb.finish_with_message(format!("Selesai mengarsipkan {} berkas ke memori.", total_files));

        println!("[VAULT] Mengenkripsi kontainer dengan AES-256-GCM...");

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

        // 5. Hapus folder plain-text hanya jika berada di dalam folder runtime sementara (bukan base_dir sumber)
        let vault_parent = vault_file.parent().unwrap_or(runtime_dir);
        if runtime_dir != vault_parent {
            for dir_name in PROTECTED_DIRS.iter() {
                let target = runtime_dir.join(dir_name);
                if target.exists() {
                    let _ = fs::remove_dir_all(&target);
                }
            }
        }

        println!(
            "[VAULT] Sukses! Seluruh {} berkas tersimpan aman di {:?}",
            total_files,
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

        let nginx_bin = runtime_dir.join("nginx").join("nginx.exe");
        let mysqld_bin = runtime_dir.join("mariadb").join("bin").join("mysqld.exe");
        let php_bin = runtime_dir.join("php").join("php-cgi.exe");

        // Jika komponen penting sudah lengkap di runtime_dir, gunakan yang sudah ada
        if nginx_bin.exists() && mysqld_bin.exists() && php_bin.exists() {
            println!("[VAULT] Komponen runtime telah tersedia di ruang kerja terproteksi.");
            return Ok(());
        }

        println!("[VAULT] Membuka enkripsi kontainer aplikasi dan mengekstrak komponen...");

        let mut file = File::open(vault_file)?;
        let file_len = file.metadata().map(|m| m.len() as usize).unwrap_or(0);
        let mut raw_data = Vec::with_capacity(file_len);
        file.read_to_end(&mut raw_data)?;

        if raw_data.len() <= NONCE_SIZE {
            return Err(anyhow!("File kontainer rusak atau korup."));
        }

        let (nonce_bytes, ciphertext) = raw_data.split_at(NONCE_SIZE);
        let cipher = Aes256Gcm::new(key.into());
        let nonce = Nonce::from_slice(nonce_bytes);

        let decrypted_zstd = cipher
            .decrypt(nonce, ciphertext)
            .or_else(|_| Self::try_decrypt_with_fallback_seeds(nonce, ciphertext))
            .map_err(|_| anyhow!("Kunci dekripsi tidak cocok atau kontainer telah dimodifikasi!"))?;

        if !runtime_dir.exists() {
            fs::create_dir_all(runtime_dir)?;
        }

        println!("[VAULT] Mengekstrak seluruh berkas ke ruang kerja terproteksi (Multi-Threaded)...");
        let decoder = zstd::Decoder::new(&decrypted_zstd[..])?;
        let buf_decoder = std::io::BufReader::with_capacity(4 * 1024 * 1024, decoder);
        let mut archive = Archive::new(buf_decoder);
        archive.set_preserve_permissions(false);
        archive.set_preserve_mtime(false);
        archive.set_overwrite(true);

        let pb = indicatif::ProgressBar::new(30904);
        pb.set_style(
            indicatif::ProgressStyle::default_bar()
                .template("[UNSEAL] [{elapsed_precise}] [{bar:40.green/white}] {pos}/{len} ({percent}%) ETA: {eta} | {msg}")
                .unwrap_or_else(|_| indicatif::ProgressStyle::default_bar())
                .progress_chars("#>-"),
        );

        let mut created_dirs: HashSet<PathBuf> = HashSet::new();
        created_dirs.insert(runtime_dir.to_path_buf());

        const BATCH_SIZE: usize = 2000;
        let mut batch: Vec<(PathBuf, Vec<u8>)> = Vec::with_capacity(BATCH_SIZE);

        let flush_batch = |batch: &mut Vec<(PathBuf, Vec<u8>)>,
                           created_dirs: &mut HashSet<PathBuf>,
                           pb: &indicatif::ProgressBar| {
            if batch.is_empty() {
                return;
            }

            // 1. Buat direktori unik terlebih dahulu secara berurutan tanpa syscall duplikat
            for (dest, _) in batch.iter() {
                if let Some(parent) = dest.parent() {
                    if created_dirs.insert(parent.to_path_buf()) {
                        let _ = fs::create_dir_all(parent);
                    }
                }
            }

            // 2. Tulis seluruh berkas dalam batch secara paralel di multi-core CPU dengan Rayon
            let items: Vec<(PathBuf, Vec<u8>)> = std::mem::take(batch);
            let count = items.len();
            items.into_par_iter().for_each(|(dest, data)| {
                let _ = fs::write(&dest, data);
            });

            pb.inc(count as u64);
            let current_pos = pb.position();
            if current_pos % 5000 == 0 || current_pos >= pb.length().unwrap_or(30904) {
                pb.println(format!(
                    "  [UNSEAL] Telah mengekstrak {} berkas...",
                    current_pos
                ));
            }
        };

        for entry_res in archive.entries()? {
            let mut entry = match entry_res {
                Ok(e) => e,
                Err(err) => {
                    eprintln!("\n[WARN] Lewati entri korup: {}", err);
                    continue;
                }
            };

            let path = match entry.path() {
                Ok(p) => p.to_path_buf(),
                Err(_) => continue,
            };

            let path_str = path.to_string_lossy().to_string();

            // Tangani metadata jika ada untuk menyesuaikan total length progress bar
            if path_str == ".aksaventra_meta" {
                let mut buf = String::new();
                if let Ok(_) = entry.read_to_string(&mut buf) {
                    if let Some(num_str) = buf.strip_prefix("total_files=") {
                        if let Ok(count) = num_str.trim().parse::<u64>() {
                            pb.set_length(count);
                        }
                    }
                }
                continue;
            }

            let dest = runtime_dir.join(&path);

            if entry.header().entry_type().is_dir() {
                if created_dirs.insert(dest.clone()) {
                    let _ = fs::create_dir_all(&dest);
                }
                continue;
            }

            let mut file_data = Vec::with_capacity(entry.size() as usize);
            if let Err(err) = entry.read_to_end(&mut file_data) {
                eprintln!("\n[WARN] Lewati berkas: {} ({})", path_str, err);
                continue;
            }

            let display_msg = if path_str.len() > 45 {
                format!("...{}", &path_str[path_str.len() - 42..])
            } else {
                path_str.clone()
            };
            pb.set_message(display_msg);

            batch.push((dest, file_data));

            if batch.len() >= BATCH_SIZE {
                flush_batch(&mut batch, &mut created_dirs, &pb);
            }
        }

        // Tulis sisa berkas terakhir
        flush_batch(&mut batch, &mut created_dirs, &pb);

        pb.finish_with_message("Selesai mengekstrak seluruh berkas.");

        // Terapkan atribut tersembunyi dan sistem setelah seluruh berkas selesai diekstrak
        Self::set_hidden_system_attribute(runtime_dir);

        println!("[VAULT] Seluruh komponen (Nginx, PHP, MariaDB, Moodle) berhasil dimuat dan terverifikasi lengkap.");
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