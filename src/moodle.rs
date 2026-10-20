use std::fs;
use std::io;
use std::path::{Path, PathBuf};
use std::process::Command;

use crate::process::{create_silent_command, run_silent_status};

/// Dynamic wwwroot PHP code replacing static `http://localhost` (FR-03).
pub const DYNAMIC_WWWROOT_SNIPPET: &str =
r#"// Dynamic HTTP_HOST routing for Moodle Portable LAN support (FR-03)
if (!empty($_SERVER['HTTP_HOST'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $CFG->wwwroot = $protocol . $_SERVER['HTTP_HOST'];
} else {
    $CFG->wwwroot = 'http://localhost';
}"#;

/// Configuration options for unattended Moodle CLI installation.
#[derive(Debug, Clone)]
pub struct MoodleInstallOptions {
    pub wwwroot: String,
    pub dataroot: PathBuf,
    pub db_type: String,
    pub db_host: String,
    pub db_name: String,
    pub db_user: String,
    pub db_pass: String,
    pub db_port: u16,
    pub site_fullname: String,
    pub site_shortname: String,
    pub admin_user: String,
    pub admin_pass: String,
    pub admin_email: String,
}

impl Default for MoodleInstallOptions {
    fn default() -> Self {
        Self {
            wwwroot: "http://localhost".to_string(),
            dataroot: PathBuf::from("./runtime/moodledata"),
            db_type: "mariadb".to_string(),
            db_host: "127.0.0.1".to_string(),
            db_name: "moodle".to_string(),
            db_user: "root".to_string(),
            db_pass: String::new(),
            db_port: 3306,
            site_fullname: "Moodle Portable".to_string(),
            site_shortname: "moodle".to_string(),
            admin_user: "admin".to_string(),
            admin_pass: "Admin123!".to_string(),
            admin_email: "admin@localhost".to_string(),
        }
    }
}

/// Paths associated with the Moodle runtime environment.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MoodlePaths {
    pub runtime_dir: PathBuf,
    pub moodle_dir: PathBuf,
    pub moodledata_dir: PathBuf,
    pub config_php: PathBuf,
    pub install_cli: PathBuf,
    pub php_exe: PathBuf,
}

impl MoodlePaths {
    pub fn new<P: AsRef<Path>>(base_dir: P) -> Self {
        let base = base_dir.as_ref().to_path_buf();
        // If base is runtime, use it; otherwise check if it has a runtime/ subfolder
        let runtime_dir = if base.ends_with("runtime") || base.join("moodle").exists() {
            base
        } else {
            base.join("runtime")
        };

        let moodle_dir = runtime_dir.join("moodle");
        let moodledata_dir = runtime_dir.join("moodledata");
        let config_php = moodle_dir.join("config.php");
        let install_cli = moodle_dir.join("admin").join("cli").join("install.php");
        let php_exe = runtime_dir.join("php").join("php.exe");

        Self {
            runtime_dir,
            moodle_dir,
            moodledata_dir,
            config_php,
            install_cli,
            php_exe,
        }
    }
}

/// Constructs the CLI command for unattended Moodle installation (`admin/cli/install.php`).
pub fn build_install_command(paths: &MoodlePaths, options: &MoodleInstallOptions) -> Command {
    let mut cmd = create_silent_command(&paths.php_exe);
    let dataroot_str = options.dataroot.to_string_lossy().replace('\\', "/");

    cmd.arg(&paths.install_cli);
    cmd.arg("--non-interactive");
    cmd.arg("--agree-license");
    cmd.arg(format!("--wwwroot={}", options.wwwroot));
    cmd.arg(format!("--dataroot={}", dataroot_str));
    cmd.arg(format!("--dbtype={}", options.db_type));
    cmd.arg(format!("--dbhost={}", options.db_host));
    cmd.arg(format!("--dbname={}", options.db_name));
    cmd.arg(format!("--dbuser={}", options.db_user));
    if !options.db_pass.is_empty() {
        cmd.arg(format!("--dbpass={}", options.db_pass));
    }
    cmd.arg(format!("--dbport={}", options.db_port));
    cmd.arg(format!("--fullname={}", options.site_fullname));
    cmd.arg(format!("--shortname={}", options.site_shortname));
    cmd.arg(format!("--adminuser={}", options.admin_user));
    cmd.arg(format!("--adminpass={}", options.admin_pass));
    cmd.arg(format!("--adminemail={}", options.admin_email));

    cmd.current_dir(&paths.moodle_dir);
    cmd
}

/// Executes unattended Moodle CLI installer (`admin/cli/install.php`).
///
/// Skips installation cleanly if `config.php` already exists.
pub fn install_moodle<P: AsRef<Path>>(base_dir: P) -> io::Result<bool> {
    let paths = MoodlePaths::new(base_dir);
    if paths.config_php.exists() {
        // Already installed
        return Ok(false);
    }

    let mut options = MoodleInstallOptions::default();
    options.dataroot = paths.moodledata_dir.clone();

    let mut cmd = build_install_command(&paths, &options);
    let status = run_silent_status(&mut cmd)?;

    if status.success() {
        // Automatically patch config.php for dynamic IP routing
        let _ = patch_moodle_config_for_dynamic_ip(&paths.runtime_dir);
        Ok(true)
    } else {
        Err(io::Error::new(
            io::ErrorKind::Other,
            format!("Moodle CLI installer exited with code: {:?}", status.code()),
        ))
    }
}

/// Checks whether `config.php` content already contains dynamic HTTP_HOST routing.
pub fn is_config_patched(content: &str) -> bool {
    content.contains("$_SERVER['HTTP_HOST']") || content.contains("$_SERVER[\"HTTP_HOST\"]")
}

/// Patches the text content of `config.php`, replacing static `$CFG->wwwroot`
/// with the dynamic `HTTP_HOST` evaluation snippet.
pub fn patch_config_content(content: &str) -> String {
    if is_config_patched(content) {
        return content.to_string();
    }

    let mut patched_lines = Vec::new();
    let mut found_wwwroot = false;

    for line in content.lines() {
        let trimmed = line.trim();
        if trimmed.starts_with("$CFG->wwwroot") && trimmed.contains('=') && trimmed.ends_with(';') {
            patched_lines.push(DYNAMIC_WWWROOT_SNIPPET);
            found_wwwroot = true;
        } else {
            patched_lines.push(line);
        }
    }

    if !found_wwwroot {
        // Fallback: If no exact single-line $CFG->wwwroot was matched, append it before the end
        patched_lines.push(DYNAMIC_WWWROOT_SNIPPET);
    }

    patched_lines.join("\r\n")
}

/// Modifies `config.php` in the specified directory to replace static wwwroot
/// with dynamic `$_SERVER['HTTP_HOST']` evaluation.
pub fn patch_moodle_config_for_dynamic_ip<P: AsRef<Path>>(base_dir: P) -> io::Result<bool> {
    let paths = MoodlePaths::new(base_dir);
    if !paths.config_php.exists() {
        return Err(io::Error::new(
            io::ErrorKind::NotFound,
            format!("Moodle config.php not found at: {}", paths.config_php.display()),
        ));
    }

    let original_content = fs::read_to_string(&paths.config_php)?;
    if is_config_patched(&original_content) {
        return Ok(false); // Already patched, no changes needed
    }

    let patched_content = patch_config_content(&original_content);
    fs::write(&paths.config_php, patched_content)?;
    Ok(true)
}

#[cfg(test)]
mod tests {
    use super::*;

    const SAMPLE_RAW_CONFIG_PHP: &str = r#"<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = '127.0.0.1';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'root';
$CFG->dbpass    = '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => 3306,
  'dbsocket' => '',
);

$CFG->wwwroot   = 'http://localhost';
$CFG->dataroot  = 'D:\\Project\\moodle_installer\\runtime\\moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
"#;

    #[test]
    fn test_patch_config_content_replaces_static_wwwroot() {
        assert!(!is_config_patched(SAMPLE_RAW_CONFIG_PHP));

        let patched = patch_config_content(SAMPLE_RAW_CONFIG_PHP);
        assert!(is_config_patched(&patched));
        assert!(patched.contains("$_SERVER['HTTP_HOST']"));
        assert!(!patched.contains("$CFG->wwwroot   = 'http://localhost';"));
        assert!(patched.contains("$CFG->dbtype    = 'mariadb';"));
    }

    #[test]
    fn test_patch_config_idempotent() {
        let patched_first = patch_config_content(SAMPLE_RAW_CONFIG_PHP);
        let patched_second = patch_config_content(&patched_first);

        assert_eq!(patched_first, patched_second, "Patching already-patched config must produce identical output");
    }

    #[test]
    fn test_build_install_command_arguments() {
        let paths = MoodlePaths::new("./runtime");
        let options = MoodleInstallOptions::default();
        let cmd = build_install_command(&paths, &options);
        let debug_cmd = format!("{:?}", cmd);

        assert!(debug_cmd.contains("--non-interactive"));
        assert!(debug_cmd.contains("--agree-license"));
        assert!(debug_cmd.contains("--wwwroot=http://localhost"));
        assert!(debug_cmd.contains("--dbtype=mariadb"));
        assert!(debug_cmd.contains("--dbname=moodle"));
        assert!(debug_cmd.contains("--adminuser=admin"));
    }

    #[test]
    fn test_patch_moodle_config_file_on_disk() {
        let temp_dir = std::env::temp_dir().join(format!("moodle_patch_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        let moodle_dir = temp_dir.join("runtime").join("moodle");
        fs::create_dir_all(&moodle_dir).unwrap();

        let config_file = moodle_dir.join("config.php");
        fs::write(&config_file, SAMPLE_RAW_CONFIG_PHP).unwrap();

        let modified = patch_moodle_config_for_dynamic_ip(&temp_dir).expect("Patching should succeed");
        assert!(modified, "First patch should modify the file");

        let content = fs::read_to_string(&config_file).unwrap();
        assert!(is_config_patched(&content));

        // Re-run to test idempotency on disk
        let modified_second = patch_moodle_config_for_dynamic_ip(&temp_dir).expect("Second patch call should succeed");
        assert!(!modified_second, "Second patch call should not modify file");

        // Cleanup
        let _ = fs::remove_dir_all(&temp_dir);
    }
}
