use std::fs;
use std::io;
use std::path::{Path, PathBuf};

use crate::cron::CronPaths;
use crate::database::MariaDbPaths;
use crate::lifecycle::ServiceShutdownPaths;
use crate::moodle::MoodlePaths;
use crate::security::SandboxLayout;

/// Unified installation and runtime paths manager for Moodle, Nginx, PHP, and MariaDB (Issue #24).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InstallationPaths {
    pub base_dir: PathBuf,
    pub moodle_dir: PathBuf,
    pub moodledata_dir: PathBuf,
    pub nginx_dir: PathBuf,
    pub php_dir: PathBuf,
    pub mariadb_dir: PathBuf,
}

impl InstallationPaths {
    /// Constructs default installation paths layout relative to `base_dir`.
    pub fn new<P: AsRef<Path>>(base_dir: P) -> Self {
        let base = base_dir.as_ref().to_path_buf();
        Self {
            moodle_dir: base.join("moodle"),
            moodledata_dir: base.join("moodledata"),
            nginx_dir: base.join("nginx"),
            php_dir: base.join("php"),
            mariadb_dir: base.join("mariadb"),
            base_dir: base,
        }
    }

    /// Sets custom Moodle web root directory.
    pub fn with_moodle_dir<P: AsRef<Path>>(mut self, path: P) -> Self {
        self.moodle_dir = path.as_ref().to_path_buf();
        self
    }

    /// Sets custom Moodledata storage directory.
    pub fn with_moodledata_dir<P: AsRef<Path>>(mut self, path: P) -> Self {
        self.moodledata_dir = path.as_ref().to_path_buf();
        self
    }

    /// Sets custom Nginx server directory.
    pub fn with_nginx_dir<P: AsRef<Path>>(mut self, path: P) -> Self {
        self.nginx_dir = path.as_ref().to_path_buf();
        self
    }

    /// Sets custom PHP runtime directory.
    pub fn with_php_dir<P: AsRef<Path>>(mut self, path: P) -> Self {
        self.php_dir = path.as_ref().to_path_buf();
        self
    }

    /// Sets custom MariaDB server directory.
    pub fn with_mariadb_dir<P: AsRef<Path>>(mut self, path: P) -> Self {
        self.mariadb_dir = path.as_ref().to_path_buf();
        self
    }

    // --- NGINX PATH RESOLVERS ---

    /// Path to `nginx.exe` executable binary.
    pub fn nginx_exe(&self) -> PathBuf {
        self.nginx_dir.join("nginx.exe")
    }

    /// Path to Nginx primary configuration file `nginx.conf`.
    pub fn nginx_conf(&self) -> PathBuf {
        self.nginx_dir.join("conf").join("nginx.conf")
    }

    /// Path to Nginx logs directory.
    pub fn nginx_logs_dir(&self) -> PathBuf {
        self.nginx_dir.join("logs")
    }

    // --- PHP PATH RESOLVERS ---

    /// Path to CLI executable `php.exe`.
    pub fn php_exe(&self) -> PathBuf {
        self.php_dir.join("php.exe")
    }

    /// Path to FastCGI executable `php-cgi.exe`.
    pub fn php_cgi_exe(&self) -> PathBuf {
        self.php_dir.join("php-cgi.exe")
    }

    /// Path to PHP configuration file `php.ini`.
    pub fn php_ini(&self) -> PathBuf {
        self.php_dir.join("php.ini")
    }

    // --- MOODLE PATH RESOLVERS ---

    /// Path to Moodle web root `index.php`.
    pub fn moodle_index(&self) -> PathBuf {
        self.moodle_dir.join("index.php")
    }

    /// Path to Moodle main configuration file `config.php`.
    pub fn moodle_config(&self) -> PathBuf {
        self.moodle_dir.join("config.php")
    }

    /// Path to Moodle unattended CLI installation script `admin/cli/install.php`.
    pub fn moodle_cli_install(&self) -> PathBuf {
        self.moodle_dir.join("admin").join("cli").join("install.php")
    }

    /// Path to Moodle CLI cron execution script `admin/cli/cron.php`.
    pub fn moodle_cli_cron(&self) -> PathBuf {
        self.moodle_dir.join("admin").join("cli").join("cron.php")
    }

    // --- MARIADB PATH RESOLVERS ---

    /// Path to MariaDB daemon executable `mariadbd.exe` (or `mysqld.exe`).
    pub fn mariadbd_exe(&self) -> PathBuf {
        let mariadbd = self.mariadb_dir.join("bin").join("mariadbd.exe");
        if mariadbd.is_file() {
            mariadbd
        } else {
            self.mariadb_dir.join("bin").join("mysqld.exe")
        }
    }

    /// Path to MariaDB client executable `mariadb.exe` (or `mysql.exe`).
    pub fn mariadb_client_exe(&self) -> PathBuf {
        let mariadb = self.mariadb_dir.join("bin").join("mariadb.exe");
        if mariadb.is_file() {
            mariadb
        } else {
            self.mariadb_dir.join("bin").join("mysql.exe")
        }
    }

    /// Path to MariaDB configuration file `my.ini`.
    pub fn mariadb_my_ini(&self) -> PathBuf {
        self.mariadb_dir.join("my.ini")
    }

    /// Path to MariaDB data storage directory `data`.
    pub fn mariadb_data_dir(&self) -> PathBuf {
        self.mariadb_dir.join("data")
    }

    // --- ADAPTER HELPERS ---

    /// Converts paths layout to `SandboxLayout` for security validation.
    pub fn to_sandbox_layout(&self) -> SandboxLayout {
        SandboxLayout {
            runtime_dir: self.base_dir.clone(),
            moodle_dir: self.moodle_dir.clone(),
            moodledata_dir: self.moodledata_dir.clone(),
            nginx_dir: self.nginx_dir.clone(),
            nginx_conf_dir: self.nginx_dir.join("conf"),
            nginx_conf_file: self.nginx_conf(),
        }
    }

    /// Converts paths layout to `MariaDbPaths` for database bootstrapping.
    pub fn to_mariadb_paths(&self) -> MariaDbPaths {
        MariaDbPaths::new(&self.mariadb_dir)
    }

    /// Converts paths layout to `MoodlePaths` for automated CLI installation.
    pub fn to_moodle_paths(&self) -> MoodlePaths {
        MoodlePaths {
            runtime_dir: self.base_dir.clone(),
            moodle_dir: self.moodle_dir.clone(),
            moodledata_dir: self.moodledata_dir.clone(),
            config_php: self.moodle_config(),
            install_cli: self.moodle_cli_install(),
            php_exe: self.php_exe(),
        }
    }

    /// Converts paths layout to `CronPaths` for background scheduler.
    pub fn to_cron_paths(&self) -> CronPaths {
        CronPaths {
            php_exe: self.php_exe(),
            moodle_dir: self.moodle_dir.clone(),
            cron_script: self.moodle_cli_cron(),
        }
    }

    /// Converts paths layout to `ServiceShutdownPaths` for graceful termination.
    pub fn to_shutdown_paths(&self) -> ServiceShutdownPaths {
        let mariadb_bin = self.mariadb_dir.join("bin");
        let mariadb_admin_exe = if mariadb_bin.join("mariadb-admin.exe").exists() {
            mariadb_bin.join("mariadb-admin.exe")
        } else if mariadb_bin.join("mysqladmin.exe").exists() {
            mariadb_bin.join("mysqladmin.exe")
        } else {
            mariadb_bin.join("mariadb-admin.exe")
        };

        ServiceShutdownPaths {
            runtime_dir: self.base_dir.clone(),
            nginx_exe: self.nginx_exe(),
            nginx_dir: self.nginx_dir.clone(),
            mariadb_admin_exe,
            mariadb_dir: self.mariadb_dir.clone(),
        }
    }

    // --- OPERATIONS & VALIDATION ---

    /// Creates all fundamental component directories if they do not already exist.
    pub fn ensure_directories_exist(&self) -> io::Result<()> {
        fs::create_dir_all(&self.base_dir)?;
        fs::create_dir_all(&self.moodle_dir)?;
        fs::create_dir_all(&self.moodledata_dir)?;
        fs::create_dir_all(&self.nginx_dir)?;
        fs::create_dir_all(self.nginx_dir.join("conf"))?;
        fs::create_dir_all(self.nginx_dir.join("logs"))?;
        fs::create_dir_all(&self.php_dir)?;
        fs::create_dir_all(&self.mariadb_dir)?;
        fs::create_dir_all(self.mariadb_dir.join("bin"))?;
        fs::create_dir_all(self.mariadb_dir.join("data"))?;
        Ok(())
    }

    /// Validates security isolation: ensures `moodledata` is NOT inside `moodle` web root (NFR-04).
    pub fn validate_isolation(&self) -> Result<(), &'static str> {
        let moodle_canonical = self.moodle_dir.canonicalize().unwrap_or_else(|_| self.moodle_dir.clone());
        let data_canonical = self.moodledata_dir.canonicalize().unwrap_or_else(|_| self.moodledata_dir.clone());

        if data_canonical.starts_with(&moodle_canonical) {
            return Err("Security Violation: moodledata cannot be located inside Moodle web root (NFR-04)");
        }
        Ok(())
    }

    /// Validates if all critical executable binaries exist on disk.
    pub fn validate_binaries_exist(&self) -> Result<(), Vec<String>> {
        let mut missing = Vec::new();

        if !self.nginx_exe().exists() {
            missing.push(format!("Missing nginx binary: {}", self.nginx_exe().display()));
        }
        if !self.php_exe().exists() {
            missing.push(format!("Missing php CLI binary: {}", self.php_exe().display()));
        }
        if !self.php_cgi_exe().exists() {
            missing.push(format!("Missing php-cgi binary: {}", self.php_cgi_exe().display()));
        }

        if missing.is_empty() {
            Ok(())
        } else {
            Err(missing)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_installation_paths_default_layout() {
        let paths = InstallationPaths::new("C:/portable_runtime");

        assert_eq!(paths.base_dir, PathBuf::from("C:/portable_runtime"));
        assert_eq!(paths.moodle_dir, PathBuf::from("C:/portable_runtime/moodle"));
        assert_eq!(paths.moodledata_dir, PathBuf::from("C:/portable_runtime/moodledata"));
        assert_eq!(paths.nginx_dir, PathBuf::from("C:/portable_runtime/nginx"));
        assert_eq!(paths.php_dir, PathBuf::from("C:/portable_runtime/php"));
        assert_eq!(paths.mariadb_dir, PathBuf::from("C:/portable_runtime/mariadb"));

        // Binary resolvers
        assert_eq!(paths.nginx_exe(), PathBuf::from("C:/portable_runtime/nginx/nginx.exe"));
        assert_eq!(paths.nginx_conf(), PathBuf::from("C:/portable_runtime/nginx/conf/nginx.conf"));
        assert_eq!(paths.php_exe(), PathBuf::from("C:/portable_runtime/php/php.exe"));
        assert_eq!(paths.php_cgi_exe(), PathBuf::from("C:/portable_runtime/php/php-cgi.exe"));
        assert_eq!(paths.php_ini(), PathBuf::from("C:/portable_runtime/php/php.ini"));
        assert_eq!(paths.moodle_index(), PathBuf::from("C:/portable_runtime/moodle/index.php"));
        assert_eq!(paths.moodle_config(), PathBuf::from("C:/portable_runtime/moodle/config.php"));
        assert_eq!(
            paths.moodle_cli_install(),
            PathBuf::from("C:/portable_runtime/moodle/admin/cli/install.php")
        );
        assert_eq!(
            paths.moodle_cli_cron(),
            PathBuf::from("C:/portable_runtime/moodle/admin/cli/cron.php")
        );
    }

    #[test]
    fn test_installation_paths_builder_customization() {
        let paths = InstallationPaths::new("C:/base")
            .with_moodle_dir("D:/custom_moodle")
            .with_moodledata_dir("E:/custom_moodledata")
            .with_nginx_dir("F:/custom_nginx")
            .with_php_dir("G:/custom_php")
            .with_mariadb_dir("H:/custom_mariadb");

        assert_eq!(paths.moodle_dir, PathBuf::from("D:/custom_moodle"));
        assert_eq!(paths.moodledata_dir, PathBuf::from("E:/custom_moodledata"));
        assert_eq!(paths.nginx_dir, PathBuf::from("F:/custom_nginx"));
        assert_eq!(paths.php_dir, PathBuf::from("G:/custom_php"));
        assert_eq!(paths.mariadb_dir, PathBuf::from("H:/custom_mariadb"));

        assert_eq!(paths.nginx_exe(), PathBuf::from("F:/custom_nginx/nginx.exe"));
        assert_eq!(paths.php_exe(), PathBuf::from("G:/custom_php/php.exe"));
    }

    #[test]
    fn test_conversion_to_subsystem_paths() {
        let paths = InstallationPaths::new("C:/test_runtime");

        let sandbox = paths.to_sandbox_layout();
        assert_eq!(sandbox.moodle_dir, PathBuf::from("C:/test_runtime/moodle"));
        assert_eq!(sandbox.moodledata_dir, PathBuf::from("C:/test_runtime/moodledata"));
        assert_eq!(sandbox.nginx_dir, PathBuf::from("C:/test_runtime/nginx"));

        let moodle_paths = paths.to_moodle_paths();
        assert_eq!(moodle_paths.php_exe, PathBuf::from("C:/test_runtime/php/php.exe"));
        assert_eq!(moodle_paths.moodle_dir, PathBuf::from("C:/test_runtime/moodle"));
        assert_eq!(moodle_paths.install_cli, PathBuf::from("C:/test_runtime/moodle/admin/cli/install.php"));

        let cron_paths = paths.to_cron_paths();
        assert_eq!(cron_paths.php_exe, PathBuf::from("C:/test_runtime/php/php.exe"));
        assert_eq!(cron_paths.cron_script, PathBuf::from("C:/test_runtime/moodle/admin/cli/cron.php"));

        let shutdown_paths = paths.to_shutdown_paths();
        assert_eq!(shutdown_paths.nginx_dir, PathBuf::from("C:/test_runtime/nginx"));
        assert_eq!(shutdown_paths.mariadb_dir, PathBuf::from("C:/test_runtime/mariadb"));
        assert_eq!(shutdown_paths.nginx_exe, PathBuf::from("C:/test_runtime/nginx/nginx.exe"));
    }

    #[test]
    fn test_validate_isolation_detects_unsafe_overlap() {
        let safe_paths = InstallationPaths::new("C:/safe_base");
        assert!(safe_paths.validate_isolation().is_ok());

        let unsafe_paths = InstallationPaths::new("C:/safe_base")
            .with_moodle_dir("C:/safe_base/moodle")
            .with_moodledata_dir("C:/safe_base/moodle/moodledata");
        assert!(unsafe_paths.validate_isolation().is_err());
    }

    #[test]
    fn test_ensure_directories_exist_creates_all() {
        let temp_dir = std::env::temp_dir().join(format!("paths_test_{}", std::process::id()));
        let _ = fs::remove_dir_all(&temp_dir);

        let paths = InstallationPaths::new(&temp_dir);
        assert!(paths.ensure_directories_exist().is_ok());

        assert!(paths.moodle_dir.exists());
        assert!(paths.moodledata_dir.exists());
        assert!(paths.nginx_dir.exists());
        assert!(paths.nginx_dir.join("conf").exists());
        assert!(paths.php_dir.exists());
        assert!(paths.mariadb_dir.exists());
        assert!(paths.mariadb_dir.join("data").exists());

        let _ = fs::remove_dir_all(&temp_dir);
    }
}
