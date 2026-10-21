use std::fs;
use std::io::{self, Cursor};
use std::path::{Path, PathBuf};
use std::time::Instant;
use zip::ZipArchive;

use crate::container::{
    is_aksa_container, unpack_container_to_dir, DEFAULT_CONTAINER_NAME,
    DEFAULT_OFFLINE_CONTAINER_NAME,
};
use crate::network::is_internet_connected;
use crate::prune::{prune_moodle, PruneReport};

pub const DEFAULT_OFFLINE_PAYLOAD_NAME: &str = "offline_payload.zip";
pub const PLACEHOLDER_FILENAME: &str = "placeholder.txt";

/// Mode of payload provisioning based on network status (Issue #21).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PayloadMode {
    /// Scenario 1: Internet is connected, download package pipeline
    Online,
    /// Scenario 2: No active internet connection, use local offline payload
    Offline,
}

impl std::fmt::Display for PayloadMode {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            PayloadMode::Online => write!(f, "Online"),
            PayloadMode::Offline => write!(f, "Offline"),
        }
    }
}

/// Configuration options for payload provisioning.
#[derive(Debug, Clone)]
pub struct PayloadProvisionConfig {
    pub runtime_dir: PathBuf,
    pub offline_payload_path: Option<PathBuf>,
    pub force_mode: Option<PayloadMode>,
    pub skip_prune: bool,
}

impl PayloadProvisionConfig {
    pub fn new<P: AsRef<Path>>(runtime_dir: P) -> Self {
        Self {
            runtime_dir: runtime_dir.as_ref().to_path_buf(),
            offline_payload_path: None,
            force_mode: None,
            skip_prune: false,
        }
    }

    pub fn with_offline_payload<P: AsRef<Path>>(mut self, path: P) -> Self {
        self.offline_payload_path = Some(path.as_ref().to_path_buf());
        self
    }

    pub fn with_force_mode(mut self, mode: PayloadMode) -> Self {
        self.force_mode = Some(mode);
        self
    }
}

/// Result of dual-mode payload provisioning.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ProvisionResult {
    pub mode: PayloadMode,
    pub extracted_files: usize,
    pub placeholder_path: PathBuf,
    pub prune_report: Option<PruneReport>,
    pub duration_ms: u128,
    pub was_skipped: bool,
}

/// Detects the target mode based on internet connectivity or force_mode override.
pub fn detect_payload_mode(config: &PayloadProvisionConfig) -> PayloadMode {
    if let Some(forced) = config.force_mode {
        return forced;
    }
    if is_internet_connected() {
        PayloadMode::Online
    } else {
        PayloadMode::Offline
    }
}

/// Finds the path to the offline payload archive (preferring .pak container, then .zip).
pub fn resolve_offline_payload_path(config: &PayloadProvisionConfig) -> Option<PathBuf> {
    // 1. Explicit path specified in config
    if let Some(ref path) = config.offline_payload_path {
        if path.is_file() {
            return Some(path.clone());
        }
    }

    // 2. Relative to runtime dir (prefer .pak over .zip)
    let candidates = [
        config.runtime_dir.join(DEFAULT_OFFLINE_CONTAINER_NAME),
        config.runtime_dir.join(DEFAULT_CONTAINER_NAME),
        PathBuf::from(DEFAULT_OFFLINE_CONTAINER_NAME),
        PathBuf::from(DEFAULT_CONTAINER_NAME),
        config.runtime_dir.join(DEFAULT_OFFLINE_PAYLOAD_NAME),
        config.runtime_dir.join("payload.zip"),
        PathBuf::from(DEFAULT_OFFLINE_PAYLOAD_NAME),
        PathBuf::from("payload.zip"),
    ];

    for candidate in candidates {
        if candidate.is_file() {
            return Some(candidate);
        }
    }

    None
}

/// Ensures the placeholder.txt file exists in target directory (Scenario 2).
pub fn ensure_placeholder_file<P: AsRef<Path>>(
    target_dir: P,
    mode: PayloadMode,
    source_name: &str,
) -> io::Result<PathBuf> {
    let dir = target_dir.as_ref();
    if !dir.exists() {
        fs::create_dir_all(dir)?;
    }
    let placeholder_path = dir.join(PLACEHOLDER_FILENAME);
    let content = format!(
        "Moodle Portable Runtime Placeholder\n\
         Mode: {}\n\
         Source: {}\n\
         Status: Verified\n",
        mode, source_name
    );
    fs::write(&placeholder_path, content)?;
    Ok(placeholder_path)
}

/// Unpacks a byte buffer (either AKSA secure container or zip) into target directory.
pub fn unpack_payload_buffer_to_dir(bytes: &[u8], target_dir: &Path) -> io::Result<usize> {
    if is_aksa_container(bytes) {
        unpack_container_to_dir(bytes, target_dir, None)
            .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, format!("Failed to unpack secure container: {e}")))
    } else {
        unzip_buffer_to_dir(bytes, target_dir)
    }
}

/// Unzips a zip archive byte buffer into a target directory with ZipSlip protection.
pub fn unzip_buffer_to_dir(bytes: &[u8], target_dir: &Path) -> io::Result<usize> {
    if !target_dir.exists() {
        fs::create_dir_all(target_dir)?;
    }

    let reader = Cursor::new(bytes);
    let mut archive = ZipArchive::new(reader)
        .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, format!("Invalid zip archive: {e}")))?;

    let mut count = 0;
    for i in 0..archive.len() {
        let mut file = archive.by_index(i)
            .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, format!("Failed reading entry {i}: {e}")))?;

        let outpath = match file.enclosed_name() {
            Some(p) => target_dir.join(p),
            None => continue,
        };

        if file.is_dir() {
            fs::create_dir_all(&outpath)?;
        } else {
            if let Some(parent) = outpath.parent() {
                if !parent.exists() {
                    fs::create_dir_all(parent)?;
                }
            }
            let mut outfile = fs::File::create(&outpath)?;
            io::copy(&mut file, &mut outfile)?;
            count += 1;
        }
    }
    Ok(count)
}

/// Main entrypoint for Dual-Mode Payload Provisioning (Issue #21 & #31).
pub fn provision_payload(
    config: &PayloadProvisionConfig,
    embedded_fallback: &[u8],
) -> io::Result<ProvisionResult> {
    let start = Instant::now();
    let mode = detect_payload_mode(config);

    // If runtime directory already exists and contains moodle root, skip extraction
    let moodle_dir = config.runtime_dir.join("moodle");
    if moodle_dir.exists() && config.runtime_dir.exists() {
        let placeholder = ensure_placeholder_file(
            &config.runtime_dir,
            mode,
            "existing-runtime",
        )?;
        return Ok(ProvisionResult {
            mode,
            extracted_files: 0,
            placeholder_path: placeholder,
            prune_report: None,
            duration_ms: start.elapsed().as_millis(),
            was_skipped: true,
        });
    }

    let (extracted_count, source_label) = match mode {
        PayloadMode::Online => {
            // In online mode, unpack the embedded or downloaded package
            let count = unpack_payload_buffer_to_dir(embedded_fallback, &config.runtime_dir)?;
            (count, "online-download-package".to_string())
        }
        PayloadMode::Offline => {
            // Check for offline payload archive on disk (.pak preferred, or .zip)
            if let Some(offline_archive) = resolve_offline_payload_path(config) {
                let payload_bytes = fs::read(&offline_archive)?;
                let count = unpack_payload_buffer_to_dir(&payload_bytes, &config.runtime_dir)?;
                let label = offline_archive
                    .file_name()
                    .and_then(|n| n.to_str())
                    .unwrap_or("offline_payload")
                    .to_string();
                (count, label)
            } else {
                let count = unpack_payload_buffer_to_dir(embedded_fallback, &config.runtime_dir)?;
                (count, "embedded-offline-payload".to_string())
            }
        }
    };

    // Ensure placeholder.txt is present in the target directory
    let placeholder = ensure_placeholder_file(&config.runtime_dir, mode, &source_label)?;

    // Prune the extracted moodle directory if requested
    let mut prune_report = None;
    if !config.skip_prune && moodle_dir.exists() {
        let report = prune_moodle(&moodle_dir)?;
        prune_report = Some(report);
    }

    Ok(ProvisionResult {
        mode,
        extracted_files: extracted_count,
        placeholder_path: placeholder,
        prune_report,
        duration_ms: start.elapsed().as_millis(),
        was_skipped: false,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;
    use zip::write::FileOptions;
    use zip::ZipWriter;

    fn create_test_zip_buffer() -> Vec<u8> {
        let mut buf = Vec::new();
        {
            let mut writer = ZipWriter::new(Cursor::new(&mut buf));
            let options = FileOptions::default().compression_method(zip::CompressionMethod::Stored);

            writer.start_file("moodle/index.php", options).unwrap();
            writer.write_all(b"<?php // Moodle index").unwrap();

            writer.start_file("moodle/mod/chat/view.php", options).unwrap();
            writer.write_all(b"<?php // Unneeded chat module").unwrap();

            writer.start_file("moodle/tests/behat/test.feature", options).unwrap();
            writer.write_all(b"# Behat test suite").unwrap();

            writer.start_file("php/php.exe", options).unwrap();
            writer.write_all(b"fake php binary").unwrap();

            writer.finish().unwrap();
        }
        buf
    }

    #[test]
    fn test_detect_payload_mode_force_override() {
        let config_online = PayloadProvisionConfig::new("./test_runtime")
            .with_force_mode(PayloadMode::Online);
        assert_eq!(detect_payload_mode(&config_online), PayloadMode::Online);

        let config_offline = PayloadProvisionConfig::new("./test_runtime")
            .with_force_mode(PayloadMode::Offline);
        assert_eq!(detect_payload_mode(&config_offline), PayloadMode::Offline);
    }

    #[test]
    fn test_ensure_placeholder_file_creates_file() {
        let temp_dir = std::env::temp_dir().join(format!("placeholder_test_{}", std::process::id()));
        let _ = fs::remove_dir_all(&temp_dir);

        let path = ensure_placeholder_file(&temp_dir, PayloadMode::Offline, "offline_payload.zip").unwrap();
        assert!(path.exists());
        assert_eq!(path.file_name().unwrap(), PLACEHOLDER_FILENAME);

        let content = fs::read_to_string(&path).unwrap();
        assert!(content.contains("Mode: Offline"));
        assert!(content.contains("Source: offline_payload.zip"));

        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_provision_offline_payload_with_pruning() {
        let temp_dir = std::env::temp_dir().join(format!("offline_prov_test_{}", std::process::id()));
        let _ = fs::remove_dir_all(&temp_dir);

        let zip_bytes = create_test_zip_buffer();
        let offline_zip_file = temp_dir.join("offline_payload.zip");
        fs::create_dir_all(&temp_dir).unwrap();
        fs::write(&offline_zip_file, &zip_bytes).unwrap();

        let target_dir = temp_dir.join("runtime");
        let config = PayloadProvisionConfig::new(&target_dir)
            .with_offline_payload(&offline_zip_file)
            .with_force_mode(PayloadMode::Offline);

        let result = provision_payload(&config, &[]).unwrap();
        assert_eq!(result.mode, PayloadMode::Offline);
        assert!(!result.was_skipped);
        assert!(result.placeholder_path.exists());

        // Verify placeholder.txt is present
        let placeholder = target_dir.join(PLACEHOLDER_FILENAME);
        assert!(placeholder.exists(), "placeholder.txt must exist in runtime dir");

        // Verify core moodle/index.php is preserved
        assert!(target_dir.join("moodle/index.php").exists());

        // Verify pruning occurred on offline payload
        assert!(
            !target_dir.join("moodle/mod/chat").exists(),
            "mod/chat should have been pruned from offline payload"
        );
        assert!(
            !target_dir.join("moodle/tests").exists(),
            "tests/ should have been pruned from offline payload"
        );

        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_provision_skips_if_already_extracted() {
        let temp_dir = std::env::temp_dir().join(format!("skip_prov_test_{}", std::process::id()));
        let _ = fs::remove_dir_all(&temp_dir);

        let moodle_dir = temp_dir.join("moodle");
        fs::create_dir_all(&moodle_dir).unwrap();
        fs::write(moodle_dir.join("index.php"), b"ready").unwrap();

        let config = PayloadProvisionConfig::new(&temp_dir)
            .with_force_mode(PayloadMode::Offline);

        let result = provision_payload(&config, &[]).unwrap();
        assert!(result.was_skipped);
        assert_eq!(result.extracted_files, 0);
        assert!(temp_dir.join(PLACEHOLDER_FILENAME).exists());

        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_provision_offline_pak_container() {
        use crate::container::pack_container;

        let temp_dir = std::env::temp_dir().join(format!("offline_pak_test_{}", std::process::id()));
        let _ = fs::remove_dir_all(&temp_dir);

        let zip_bytes = create_test_zip_buffer();
        let pak_bytes = pack_container(&zip_bytes, None);
        let offline_pak_file = temp_dir.join("offline_payload.pak");
        fs::create_dir_all(&temp_dir).unwrap();
        fs::write(&offline_pak_file, &pak_bytes).unwrap();

        let target_dir = temp_dir.join("runtime");
        let config = PayloadProvisionConfig::new(&target_dir)
            .with_offline_payload(&offline_pak_file)
            .with_force_mode(PayloadMode::Offline);

        let result = provision_payload(&config, &[]).unwrap();
        assert_eq!(result.mode, PayloadMode::Offline);
        assert!(!result.was_skipped);
        assert!(result.placeholder_path.exists());

        let placeholder = target_dir.join(PLACEHOLDER_FILENAME);
        assert!(placeholder.exists());
        let content = fs::read_to_string(&placeholder).unwrap();
        assert!(content.contains("offline_payload.pak"));

        assert!(target_dir.join("moodle/index.php").exists());
        let _ = fs::remove_dir_all(&temp_dir);
    }
}
