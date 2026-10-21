use std::path::{Path, PathBuf};
use std::process::Command;

use crate::process::{create_silent_command, run_silent_status};

/// Service paths needed for graceful shutdown operations.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ServiceShutdownPaths {
    pub runtime_dir: PathBuf,
    pub nginx_exe: PathBuf,
    pub nginx_dir: PathBuf,
    pub mariadb_admin_exe: PathBuf,
    pub mariadb_dir: PathBuf,
}

impl ServiceShutdownPaths {
    pub fn new<P: AsRef<Path>>(base_dir: P) -> Self {
        let base = base_dir.as_ref().to_path_buf();
        let runtime_dir = if base.ends_with("runtime") || base.join("nginx").exists() {
            base
        } else {
            base.join("runtime")
        };

        let nginx_dir = runtime_dir.join("nginx");
        let nginx_exe = nginx_dir.join("nginx.exe");

        let mariadb_dir = runtime_dir.join("mariadb");
        let mariadb_bin = mariadb_dir.join("bin");

        // Prefer mariadb-admin.exe, fallback to mysqladmin.exe
        let mariadb_admin_exe = if mariadb_bin.join("mariadb-admin.exe").exists() {
            mariadb_bin.join("mariadb-admin.exe")
        } else if mariadb_bin.join("mysqladmin.exe").exists() {
            mariadb_bin.join("mysqladmin.exe")
        } else if mariadb_dir.join("mariadb-admin.exe").exists() {
            mariadb_dir.join("mariadb-admin.exe")
        } else {
            mariadb_bin.join("mariadb-admin.exe")
        };

        Self {
            runtime_dir,
            nginx_exe,
            nginx_dir,
            mariadb_admin_exe,
            mariadb_dir,
        }
    }
}

/// Builds the command to stop Nginx gracefully (`nginx.exe -s stop`).
pub fn build_nginx_stop_command(paths: &ServiceShutdownPaths) -> Command {
    let mut cmd = create_silent_command(&paths.nginx_exe);
    cmd.args(["-s", "stop"]);
    cmd.current_dir(&paths.nginx_dir);
    cmd
}

/// Builds the command to cleanly shutdown MariaDB (`mariadb-admin.exe -u root shutdown`).
pub fn build_mariadb_shutdown_command(paths: &ServiceShutdownPaths, port: u16) -> Command {
    let mut cmd = create_silent_command(&paths.mariadb_admin_exe);
    cmd.args([
        "-u",
        "root",
        "-P",
        &port.to_string(),
        "-h",
        "127.0.0.1",
        "shutdown",
    ]);
    cmd.current_dir(&paths.mariadb_dir);
    cmd
}

/// Builds the command to terminate lingering PHP-CGI worker processes (`taskkill /F /IM php-cgi.exe`).
pub fn build_php_kill_command() -> Command {
    let mut cmd = create_silent_command("taskkill.exe");
    cmd.args(["/F", "/IM", "php-cgi.exe", "/T"]);
    cmd
}

/// Stops all Moodle Portable background services cleanly (NFR-02).
///
/// Order of operations:
/// 1. Stops Nginx web server (`nginx.exe -s stop`) to reject new web connections.
/// 2. Shuts down MariaDB cleanly (`mariadb-admin -u root shutdown`) ensuring all InnoDB buffers
///    are flushed to disk to prevent table corruption.
/// 3. Terminates lingering FastCGI worker processes (`php-cgi.exe`).
pub fn stop_services_gracefully<P: AsRef<Path>>(base_dir: P) -> ShutdownReport {
    stop_services_gracefully_with_port(base_dir, 3306)
}

/// Result report of graceful service termination.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct ShutdownReport {
    pub nginx_stopped: bool,
    pub mariadb_stopped: bool,
    pub php_terminated: bool,
}

/// Stops all background services with a custom MariaDB port.
pub fn stop_services_gracefully_with_port<P: AsRef<Path>>(base_dir: P, port: u16) -> ShutdownReport {
    let paths = ServiceShutdownPaths::new(base_dir);
    let mut report = ShutdownReport::default();

    // 1. Stop Nginx
    if paths.nginx_exe.exists() {
        let mut cmd = build_nginx_stop_command(&paths);
        report.nginx_stopped = run_silent_status(&mut cmd).map(|s| s.success()).unwrap_or(false);
    }

    // 2. Shutdown MariaDB
    if paths.mariadb_admin_exe.exists() {
        let mut cmd = build_mariadb_shutdown_command(&paths, port);
        report.mariadb_stopped = run_silent_status(&mut cmd).map(|s| s.success()).unwrap_or(false);
    }

    // 3. Kill lingering php-cgi.exe
    let mut kill_cmd = build_php_kill_command();
    report.php_terminated = run_silent_status(&mut kill_cmd).map(|s| s.success()).unwrap_or(false);

    report
}

/// Registers a Ctrl+C signal handler to trigger graceful shutdown.
pub fn setup_ctrlc_handler<F>(handler: F) -> Result<(), ctrlc::Error>
where
    F: FnOnce() + Send + 'static,
{
    let handler_cell = std::sync::Mutex::new(Some(handler));
    ctrlc::set_handler(move || {
        if let Ok(mut lock) = handler_cell.lock() {
            if let Some(h) = lock.take() {
                h();
            }
        }
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_shutdown_paths_layout() {
        let paths = ServiceShutdownPaths::new("./runtime");
        assert_eq!(paths.nginx_dir, PathBuf::from("./runtime/nginx"));
        assert_eq!(paths.nginx_exe, PathBuf::from("./runtime/nginx/nginx.exe"));
        assert_eq!(paths.mariadb_dir, PathBuf::from("./runtime/mariadb"));
    }

    #[test]
    fn test_build_nginx_stop_command() {
        let paths = ServiceShutdownPaths::new("./runtime");
        let cmd = build_nginx_stop_command(&paths);
        let debug = format!("{:?}", cmd);

        assert!(debug.contains("nginx.exe"));
        assert!(debug.contains("-s"));
        assert!(debug.contains("stop"));
    }

    #[test]
    fn test_build_mariadb_shutdown_command() {
        let paths = ServiceShutdownPaths::new("./runtime");
        let cmd = build_mariadb_shutdown_command(&paths, 3306);
        let debug = format!("{:?}", cmd);

        assert!(debug.contains("shutdown"));
        assert!(debug.contains("-u"));
        assert!(debug.contains("root"));
        assert!(debug.contains("-P"));
        assert!(debug.contains("3306"));
    }

    #[test]
    fn test_build_php_kill_command() {
        let cmd = build_php_kill_command();
        let debug = format!("{:?}", cmd);

        assert!(debug.contains("taskkill.exe"));
        assert!(debug.contains("php-cgi.exe"));
        assert!(debug.contains("/F"));
    }

    #[test]
    fn test_stop_services_gracefully_nonexistent_dir_does_not_panic() {
        let report = stop_services_gracefully("./non_existent_runtime_dir");
        // Should execute cleanly without panicking
        assert!(!report.nginx_stopped);
        assert!(!report.mariadb_stopped);
    }
}
