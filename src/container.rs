//! Secure Encrypted Binary Container (`.pak`) engine for Moodle Portable (Issue #31).
//!
//! Provides a custom binary archive format with:
//! - 64-byte structured header with magic signature `AKSA`
//! - Cryptographic SHA-256 anti-tamper integrity verification
//! - Fast stream cipher encryption (CTR-mode keystream with 128-bit dynamic salt)
//! - Direct in-memory extraction to prevent raw .zip files from touching the disk
//! - Rejection by standard archivers (WinRAR, 7-Zip, Explorer) as unknown/corrupt format

use std::fs;
use std::io::{self, Cursor};
use std::path::Path;
use sha2::{Digest, Sha256};
use zip::ZipArchive;

/// 4-byte magic signature for AKSA container archives
pub const CONTAINER_MAGIC: [u8; 4] = *b"AKSA";

/// Current container format version
pub const CONTAINER_VERSION: u16 = 1;

/// Flag indicating CTR stream encryption
pub const FLAG_ENCRYPTED: u16 = 0x0001;

/// Fixed container header size in bytes
pub const HEADER_SIZE: usize = 64;

/// Default filename for offline pak container
pub const DEFAULT_OFFLINE_CONTAINER_NAME: &str = "offline_payload.pak";

/// Default filename for pak container
pub const DEFAULT_CONTAINER_NAME: &str = "payload.pak";

/// Default container encryption key
pub const DEFAULT_CONTAINER_KEY: &[u8] = b"AKSA_MOODLE_PORTABLE_CONTAINER_KEY_2026";

/// Domain separation prefix for integrity checksum calculation
const INTEGRITY_DOMAIN_TAG: &[u8] = b"AKSA_INTEGRITY_CHECK_V1";

/// Errors occurring during container packaging, reading, or extraction.
#[derive(Debug)]
pub enum ContainerError {
    /// Provided data is smaller than the 64-byte header
    HeaderTooShort,
    /// Magic signature does not match `AKSA`
    InvalidMagic([u8; 4]),
    /// Container version is not supported by this engine
    UnsupportedVersion(u16),
    /// SHA-256 integrity check failed (file has been modified or corrupted)
    IntegrityCheckFailed { expected: String, actual: String },
    /// Payload length does not match expected length in header
    PayloadSizeMismatch { expected: u64, actual: u64 },
    /// Standard I/O error
    Io(io::Error),
    /// Zip extraction or format error
    Zip(zip::result::ZipError),
}

impl std::fmt::Display for ContainerError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            ContainerError::HeaderTooShort => write!(f, "Container header is truncated or too short (< 64 bytes)"),
            ContainerError::InvalidMagic(m) => write!(
                f,
                "Invalid container magic: expected 'AKSA' (0x414B5341), found {:02X?}",
                m
            ),
            ContainerError::UnsupportedVersion(v) => write!(f, "Unsupported container version: {v}"),
            ContainerError::IntegrityCheckFailed { expected, actual } => write!(
                f,
                "Container tampering or corruption detected! Checksum mismatch: expected {}, got {}",
                expected, actual
            ),
            ContainerError::PayloadSizeMismatch { expected, actual } => write!(
                f,
                "Payload size mismatch: expected {expected} bytes, got {actual} bytes"
            ),
            ContainerError::Io(e) => write!(f, "I/O error: {e}"),
            ContainerError::Zip(e) => write!(f, "Zip archive error: {e}"),
        }
    }
}

impl std::error::Error for ContainerError {}

impl From<io::Error> for ContainerError {
    fn from(err: io::Error) -> Self {
        ContainerError::Io(err)
    }
}

impl From<zip::result::ZipError> for ContainerError {
    fn from(err: zip::result::ZipError) -> Self {
        ContainerError::Zip(err)
    }
}

/// 64-byte Header definition for `.pak` binary container.
///
/// Layout:
/// - `magic`: 4 bytes (`AKSA`)
/// - `version`: 2 bytes (`u16`, Little-Endian)
/// - `flags`: 2 bytes (`u16`, Little-Endian)
/// - `payload_len`: 8 bytes (`u64`, Little-Endian)
/// - `salt`: 16 bytes (Random or dynamic per-archive salt)
/// - `checksum`: 32 bytes (SHA-256 digest of `salt + ciphertext`)
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ContainerHeader {
    pub magic: [u8; 4],
    pub version: u16,
    pub flags: u16,
    pub payload_len: u64,
    pub salt: [u8; 16],
    pub checksum: [u8; 32],
}

impl ContainerHeader {
    /// Serializes header into a 64-byte array.
    pub fn encode(&self) -> [u8; HEADER_SIZE] {
        let mut buf = [0u8; HEADER_SIZE];
        buf[0..4].copy_from_slice(&self.magic);
        buf[4..6].copy_from_slice(&self.version.to_le_bytes());
        buf[6..8].copy_from_slice(&self.flags.to_le_bytes());
        buf[8..16].copy_from_slice(&self.payload_len.to_le_bytes());
        buf[16..32].copy_from_slice(&self.salt);
        buf[32..64].copy_from_slice(&self.checksum);
        buf
    }

    /// Parses and validates header from a byte slice.
    pub fn decode(bytes: &[u8]) -> Result<Self, ContainerError> {
        if bytes.len() < HEADER_SIZE {
            return Err(ContainerError::HeaderTooShort);
        }

        let mut magic = [0u8; 4];
        magic.copy_from_slice(&bytes[0..4]);
        if magic != CONTAINER_MAGIC {
            return Err(ContainerError::InvalidMagic(magic));
        }

        let version = u16::from_le_bytes([bytes[4], bytes[5]]);
        if version != CONTAINER_VERSION {
            return Err(ContainerError::UnsupportedVersion(version));
        }

        let flags = u16::from_le_bytes([bytes[6], bytes[7]]);
        let payload_len = u64::from_le_bytes([
            bytes[8], bytes[9], bytes[10], bytes[11],
            bytes[12], bytes[13], bytes[14], bytes[15],
        ]);

        let mut salt = [0u8; 16];
        salt.copy_from_slice(&bytes[16..32]);

        let mut checksum = [0u8; 32];
        checksum.copy_from_slice(&bytes[32..64]);

        Ok(Self {
            magic,
            version,
            flags,
            payload_len,
            salt,
            checksum,
        })
    }
}

/// Computes SHA-256 keystream block for CTR mode using `key`, `salt`, and `counter`.
fn compute_keystream_block(key: &[u8], salt: &[u8; 16], counter: u64) -> [u8; 32] {
    let mut hasher = Sha256::new();
    hasher.update(key);
    hasher.update(salt);
    hasher.update(&counter.to_be_bytes());
    let result = hasher.finalize();
    let mut block = [0u8; 32];
    block.copy_from_slice(&result);
    block
}

/// Applies symmetric CTR keystream transformation in-place.
///
/// Since CTR-mode XOR is symmetric, calling this on plaintext encrypts it,
/// and calling it on ciphertext with the same key and salt decrypts it.
pub fn transform_stream(data: &mut [u8], key: &[u8], salt: &[u8; 16]) {
    const CHUNK_SIZE: usize = 32;
    for (block_idx, chunk) in data.chunks_mut(CHUNK_SIZE).enumerate() {
        let keystream = compute_keystream_block(key, salt, block_idx as u64);
        for (i, byte) in chunk.iter_mut().enumerate() {
            *byte ^= keystream[i];
        }
    }
}

/// Computes SHA-256 anti-tamper checksum over `salt` and `ciphertext`.
pub fn compute_checksum(salt: &[u8; 16], ciphertext: &[u8]) -> [u8; 32] {
    let mut hasher = Sha256::new();
    hasher.update(INTEGRITY_DOMAIN_TAG);
    hasher.update(salt);
    hasher.update(ciphertext);
    let result = hasher.finalize();
    let mut checksum = [0u8; 32];
    checksum.copy_from_slice(&result);
    checksum
}

/// Converts a 32-byte digest into a hexadecimal string.
pub fn to_hex(bytes: &[u8; 32]) -> String {
    bytes.iter().map(|b| format!("{:02x}", b)).collect()
}

/// Generates a pseudo-random 16-byte salt from system clock and process ID.
pub fn generate_salt() -> [u8; 16] {
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    let pid = std::process::id();
    let mut hasher = Sha256::new();
    hasher.update(&now.to_le_bytes());
    hasher.update(&pid.to_le_bytes());
    hasher.update(b"AKSA_DYNAMIC_SALT_GEN");
    let result = hasher.finalize();
    let mut salt = [0u8; 16];
    salt.copy_from_slice(&result[0..16]);
    salt
}

/// Checks if byte slice starts with `AKSA` magic signature.
pub fn is_aksa_container(bytes: &[u8]) -> bool {
    bytes.len() >= 4 && bytes[0..4] == CONTAINER_MAGIC
}

/// Encapsulates raw plaintext data into an encrypted `.pak` container binary.
pub fn pack_container(plain_bytes: &[u8], key: Option<&[u8]>) -> Vec<u8> {
    let cipher_key = key.unwrap_or(DEFAULT_CONTAINER_KEY);
    let salt = generate_salt();

    let mut ciphertext = plain_bytes.to_vec();
    transform_stream(&mut ciphertext, cipher_key, &salt);

    let checksum = compute_checksum(&salt, &ciphertext);

    let header = ContainerHeader {
        magic: CONTAINER_MAGIC,
        version: CONTAINER_VERSION,
        flags: FLAG_ENCRYPTED,
        payload_len: plain_bytes.len() as u64,
        salt,
        checksum,
    };

    let mut output = Vec::with_capacity(HEADER_SIZE + ciphertext.len());
    output.extend_from_slice(&header.encode());
    output.extend_from_slice(&ciphertext);
    output
}

/// Verifies container integrity and decrypts ciphertext into plaintext in-memory.
pub fn unpack_container_bytes(container_bytes: &[u8], key: Option<&[u8]>) -> Result<Vec<u8>, ContainerError> {
    if container_bytes.len() < HEADER_SIZE {
        return Err(ContainerError::HeaderTooShort);
    }

    let header = ContainerHeader::decode(&container_bytes[0..HEADER_SIZE])?;
    let ciphertext = &container_bytes[HEADER_SIZE..];

    // Verify integrity BEFORE attempting any decryption
    let calculated_checksum = compute_checksum(&header.salt, ciphertext);
    if calculated_checksum != header.checksum {
        return Err(ContainerError::IntegrityCheckFailed {
            expected: to_hex(&header.checksum),
            actual: to_hex(&calculated_checksum),
        });
    }

    let cipher_key = key.unwrap_or(DEFAULT_CONTAINER_KEY);
    let mut plaintext = ciphertext.to_vec();
    transform_stream(&mut plaintext, cipher_key, &header.salt);

    if plaintext.len() as u64 != header.payload_len {
        return Err(ContainerError::PayloadSizeMismatch {
            expected: header.payload_len,
            actual: plaintext.len() as u64,
        });
    }

    Ok(plaintext)
}

/// Unpacks an encrypted container directly to target directory with ZipSlip protection.
pub fn unpack_container_to_dir(
    container_bytes: &[u8],
    target_dir: &Path,
    key: Option<&[u8]>,
) -> Result<usize, ContainerError> {
    let plaintext_zip = unpack_container_bytes(container_bytes, key)?;

    if !target_dir.exists() {
        fs::create_dir_all(target_dir)?;
    }

    let reader = Cursor::new(plaintext_zip);
    let mut archive = ZipArchive::new(reader)?;

    let mut count = 0;
    for i in 0..archive.len() {
        let mut file = archive.by_index(i)?;

        // Guard against Zip Slip path traversal attacks
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

        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            if let Some(mode) = file.unix_mode() {
                let _ = fs::set_permissions(&outpath, fs::Permissions::from_mode(mode));
            }
        }
    }

    Ok(count)
}

/// Convenience function to pack a `.zip` file on disk into a `.pak` container file.
pub fn pack_file_to_container(
    input_zip_path: &Path,
    output_pak_path: &Path,
    key: Option<&[u8]>,
) -> Result<usize, ContainerError> {
    let zip_bytes = fs::read(input_zip_path)?;
    let pak_bytes = pack_container(&zip_bytes, key);
    fs::write(output_pak_path, &pak_bytes)?;
    Ok(pak_bytes.len())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;
    use zip::write::FileOptions;
    use zip::ZipWriter;

    fn create_test_zip() -> Vec<u8> {
        let mut buf = Vec::new();
        {
            let mut writer = ZipWriter::new(Cursor::new(&mut buf));
            let options = FileOptions::default().compression_method(zip::CompressionMethod::Stored);

            writer.start_file("moodle/index.php", options).unwrap();
            writer.write_all(b"<?php // Secure Moodle").unwrap();

            writer.start_file("php/php.exe", options).unwrap();
            writer.write_all(b"PE32+ 64-bit PHP binary").unwrap();

            writer.finish().unwrap();
        }
        buf
    }

    #[test]
    fn test_header_encode_decode_roundtrip() {
        let salt = [0x55; 16];
        let checksum = [0xAA; 32];
        let header = ContainerHeader {
            magic: CONTAINER_MAGIC,
            version: 1,
            flags: FLAG_ENCRYPTED,
            payload_len: 1048576,
            salt,
            checksum,
        };

        let encoded = header.encode();
        assert_eq!(encoded.len(), HEADER_SIZE);

        let decoded = ContainerHeader::decode(&encoded).expect("Decoding header must succeed");
        assert_eq!(decoded, header);
    }

    #[test]
    fn test_header_rejects_non_aksa_magic() {
        let mut bad_header = [0u8; HEADER_SIZE];
        bad_header[0..4].copy_from_slice(b"PK\x03\x04"); // Standard zip magic
        let err = ContainerHeader::decode(&bad_header).unwrap_err();
        match err {
            ContainerError::InvalidMagic(m) => assert_eq!(&m, b"PK\x03\x04"),
            _ => panic!("Expected InvalidMagic error"),
        }
    }

    #[test]
    fn test_header_rejects_unsupported_version() {
        let header = ContainerHeader {
            magic: CONTAINER_MAGIC,
            version: 99,
            flags: 0,
            payload_len: 100,
            salt: [0; 16],
            checksum: [0; 32],
        };
        let encoded = header.encode();
        let err = ContainerHeader::decode(&encoded).unwrap_err();
        match err {
            ContainerError::UnsupportedVersion(v) => assert_eq!(v, 99),
            _ => panic!("Expected UnsupportedVersion error"),
        }
    }

    #[test]
    fn test_pack_and_unpack_bytes_roundtrip() {
        let raw_data = b"Critical secret payload that must not be readable by WinRAR or 7-Zip";
        let pak_bytes = pack_container(raw_data, None);

        assert!(is_aksa_container(&pak_bytes));
        assert_eq!(&pak_bytes[0..4], b"AKSA");

        // Plaintext must not appear anywhere in raw ciphertext
        assert!(!pak_bytes.windows(raw_data.len()).any(|w| w == raw_data));

        let decrypted = unpack_container_bytes(&pak_bytes, None).expect("Decryption must succeed");
        assert_eq!(decrypted, raw_data);
    }

    #[test]
    fn test_anti_tamper_detection_fails_on_single_byte_mutation() {
        let raw_data = b"Tamper-proof payload contents";
        let mut pak_bytes = pack_container(raw_data, None);

        // Mutate a byte in the ciphertext payload
        let target_idx = HEADER_SIZE + 5;
        pak_bytes[target_idx] ^= 0xFF;

        let result = unpack_container_bytes(&pak_bytes, None);
        assert!(result.is_err(), "Mutated container must fail integrity check");
        match result.unwrap_err() {
            ContainerError::IntegrityCheckFailed { expected, actual } => {
                assert_ne!(expected, actual);
            }
            other => panic!("Expected IntegrityCheckFailed, got {:?}", other),
        }
    }

    #[test]
    fn test_unpack_container_to_dir_extracts_files() {
        let zip_bytes = create_test_zip();
        let pak_bytes = pack_container(&zip_bytes, None);

        let temp_dir = std::env::temp_dir().join(format!("aksa_test_{}", std::process::id()));
        let _ = fs::remove_dir_all(&temp_dir);

        let count = unpack_container_to_dir(&pak_bytes, &temp_dir, None).expect("Extraction must succeed");
        assert_eq!(count, 2);

        assert!(temp_dir.join("moodle/index.php").exists());
        assert!(temp_dir.join("php/php.exe").exists());

        let moodle_content = fs::read_to_string(temp_dir.join("moodle/index.php")).unwrap();
        assert_eq!(moodle_content, "<?php // Secure Moodle");

        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_pack_file_to_container_file_on_disk() {
        let temp_dir = std::env::temp_dir().join(format!("aksa_file_test_{}", std::process::id()));
        fs::create_dir_all(&temp_dir).unwrap();

        let zip_path = temp_dir.join("input.zip");
        let pak_path = temp_dir.join("output.pak");

        let zip_data = create_test_zip();
        fs::write(&zip_path, &zip_data).unwrap();

        let bytes_written = pack_file_to_container(&zip_path, &pak_path, None).unwrap();
        assert!(bytes_written > HEADER_SIZE);
        assert!(pak_path.exists());

        let read_pak = fs::read(&pak_path).unwrap();
        assert!(is_aksa_container(&read_pak));

        let out_dir = temp_dir.join("extracted");
        let count = unpack_container_to_dir(&read_pak, &out_dir, None).unwrap();
        assert_eq!(count, 2);

        let _ = fs::remove_dir_all(&temp_dir);
    }
}
