pub mod cron;
pub mod database;
pub mod moodle;
pub mod network;
pub mod process;
pub mod security;

pub use cron::{
    build_cron_command, run_cron_once, spawn_scheduler, start_moodle_cron,
    start_moodle_cron_with_interval, CronHandle, CronPaths, DEFAULT_CRON_INTERVAL,
};
pub use database::{
    bootstrap_mariadb, create_database, create_database_with_params, ensure_my_ini,
    generate_my_ini, start_mariadb, wait_for_mariadb_ready, MariaDbPaths, DEFAULT_DB_NAME,
    DEFAULT_MARIADB_PORT, DEFAULT_READY_TIMEOUT,
};
pub use moodle::{
    build_install_command, install_moodle, is_config_patched, patch_config_content,
    patch_moodle_config_for_dynamic_ip, MoodleInstallOptions, MoodlePaths, DYNAMIC_WWWROOT_SNIPPET,
};
pub use network::{get_local_ip, get_service_url, resolve_local_ip, DEFAULT_FALLBACK_IP, DEFAULT_PROBE_TARGET};
pub use process::{create_silent_command, run_silent_status, spawn_silent, SilentCommandExt, CREATE_NO_WINDOW};
pub use security::{generate_secure_nginx_conf, validate_nginx_security, SandboxLayout};

use std::fs;
use std::io::{self, Cursor};
use std::path::Path;
use std::time::Instant;
use zip::ZipArchive;

/// Embedded binary payload containing runtime components
pub static PAYLOAD_ZIP: &[u8] = include_bytes!("../payload.zip");

/// Result of payload extraction attempt
#[derive(Debug, PartialEq, Eq)]
pub enum ExtractionResult {
    /// Payload was extracted into target directory
    Extracted {
        file_count: usize,
        duration_ms: u128,
    },
    /// Extraction was skipped because target directory already exists
    Skipped {
        reason: &'static str,
        duration_ms: u128,
    },
}

/// Extracts embedded payload into `target_dir` if it does not already exist.
///
/// If `target_dir` already exists, extraction is skipped immediately (< 50ms).
pub fn extract_payload(target_dir: &Path) -> io::Result<ExtractionResult> {
    extract_payload_from_bytes(PAYLOAD_ZIP, target_dir)
}

/// Core extraction logic operating on a byte slice representing a zip archive.
pub fn extract_payload_from_bytes(payload_bytes: &[u8], target_dir: &Path) -> io::Result<ExtractionResult> {
    let start = Instant::now();

    // Verification of target directory existence: skip if already present
    if target_dir.exists() {
        let duration_ms = start.elapsed().as_millis();
        return Ok(ExtractionResult::Skipped {
            reason: "Target directory already exists",
            duration_ms,
        });
    }

    fs::create_dir_all(target_dir)?;

    let reader = Cursor::new(payload_bytes);
    let mut archive = ZipArchive::new(reader)
        .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, format!("Failed to read zip archive: {e}")))?;

    let mut extracted_count = 0;

    for i in 0..archive.len() {
        let mut file = archive.by_index(i)
            .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, format!("Invalid zip entry {i}: {e}")))?;

        // Guard against Zip Slip path traversal attacks
        let outpath = match file.enclosed_name() {
            Some(path) => target_dir.join(path),
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
            extracted_count += 1;
        }

        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            if let Some(mode) = file.unix_mode() {
                let _ = fs::set_permissions(&outpath, fs::Permissions::from_mode(mode));
            }
        }
    }

    let duration_ms = start.elapsed().as_millis();
    Ok(ExtractionResult::Extracted {
        file_count: extracted_count,
        duration_ms,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;
    use zip::write::FileOptions;
    use zip::ZipWriter;

    fn create_test_zip() -> Vec<u8> {
        let mut buffer = Vec::new();
        {
            let mut zip = ZipWriter::new(Cursor::new(&mut buffer));
            let options = FileOptions::default().compression_method(zip::CompressionMethod::Stored);

            zip.add_directory("subfolder", options).unwrap();
            zip.start_file("hello.txt", options).unwrap();
            zip.write_all(b"Hello Moodle Portable").unwrap();

            zip.start_file("subfolder/nested.txt", options).unwrap();
            zip.write_all(b"Nested content").unwrap();

            zip.finish().unwrap();
        }
        buffer
    }

    #[test]
    fn test_embedded_payload_is_valid_zip() {
        assert!(!PAYLOAD_ZIP.is_empty(), "PAYLOAD_ZIP must not be empty");
        let reader = Cursor::new(PAYLOAD_ZIP);
        let archive = ZipArchive::new(reader);
        assert!(archive.is_ok(), "Embedded PAYLOAD_ZIP must be a valid zip archive");
    }

    #[test]
    fn test_extract_payload_success_and_preserves_structure() {
        let test_zip = create_test_zip();
        let temp_dir = std::env::temp_dir().join(format!("moodle_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));

        // Ensure directory does not exist initially
        if temp_dir.exists() {
            let _ = fs::remove_dir_all(&temp_dir);
        }

        let result = extract_payload_from_bytes(&test_zip, &temp_dir).expect("Extraction should succeed");
        match result {
            ExtractionResult::Extracted { file_count, .. } => {
                assert_eq!(file_count, 2, "Should extract exactly 2 files");
            }
            _ => panic!("Expected Extracted result"),
        }

        assert!(temp_dir.join("hello.txt").exists());
        assert!(temp_dir.join("subfolder/nested.txt").exists());

        let hello_content = fs::read_to_string(temp_dir.join("hello.txt")).unwrap();
        assert_eq!(hello_content, "Hello Moodle Portable");

        let nested_content = fs::read_to_string(temp_dir.join("subfolder/nested.txt")).unwrap();
        assert_eq!(nested_content, "Nested content");

        // Cleanup
        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_skips_extraction_if_target_dir_exists_under_50ms() {
        let test_zip = create_test_zip();
        let temp_dir = std::env::temp_dir().join(format!("moodle_test_skip_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));

        fs::create_dir_all(&temp_dir).unwrap();

        let result = extract_payload_from_bytes(&test_zip, &temp_dir).expect("Should check directory cleanly");
        match result {
            ExtractionResult::Skipped { duration_ms, .. } => {
                assert!(duration_ms < 50, "Skipping must complete in under 50ms, took {duration_ms}ms");
            }
            _ => panic!("Expected Skipped result when directory already exists"),
        }

        // Cleanup
        let _ = fs::remove_dir_all(&temp_dir);
    }
}
