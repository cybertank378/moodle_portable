use std::fs;
use std::io;
use std::net::{TcpStream, SocketAddr};
use std::path::{Path, PathBuf};
use std::process::{Child, Command};
use std::time::{Duration, Instant};

use crate::process::{create_silent_command, run_silent_status};

pub const DEFAULT_MARIADB_PORT: u16 = 3306;
pub const DEFAULT_DB_NAME: &str = "moodle";
pub const DEFAULT_READY_TIMEOUT: Duration = Duration::from_secs(5);

/// Configuration layout for portable MariaDB.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MariaDbPaths {
    pub base_dir: PathBuf,
    pub bin_dir: PathBuf,
    pub data_dir: PathBuf,
    pub my_ini_path: PathBuf,
    pub server_exe: PathBuf,
    pub client_exe: PathBuf,
}

impl MariaDbPaths {
    pub fn new<P: AsRef<Path>>(mariadb_base_dir: P) -> Self {
        let base_dir = mariadb_base_dir.as_ref().to_path_buf();
        let bin_dir = base_dir.join("bin");
        let data_dir = base_dir.join("data");
        let my_ini_path = base_dir.join("my.ini");

        // Prefer bin/mariadbd.exe, fallback to bin/mysqld.exe or direct exe
        let server_exe = if bin_dir.join("mariadbd.exe").exists() {
            bin_dir.join("mariadbd.exe")
        } else if bin_dir.join("mysqld.exe").exists() {
            bin_dir.join("mysqld.exe")
        } else if base_dir.join("mariadbd.exe").exists() {
            base_dir.join("mariadbd.exe")
        } else {
            bin_dir.join("mariadbd.exe")
        };

        let client_exe = if bin_dir.join("mysql.exe").exists() {
            bin_dir.join("mysql.exe")
        } else if bin_dir.join("mariadb.exe").exists() {
            bin_dir.join("mariadb.exe")
        } else {
            bin_dir.join("mysql.exe")
        };

        Self {
            base_dir,
            bin_dir,
            data_dir,
            my_ini_path,
            server_exe,
            client_exe,
        }
    }
}

/// Generates a lightweight, memory-efficient `my.ini` for MariaDB (NFR-02 compliance).
pub fn generate_my_ini(data_dir: &Path, port: u16) -> String {
    let data_dir_str = data_dir.to_string_lossy().replace('\\', "/");
    format!(
r#"[mysqld]
port = {port}
datadir = "{data_dir_str}"
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
default-storage-engine = InnoDB
innodb_buffer_pool_size = 64M
max_connections = 50
skip-name-resolve
bind-address = 127.0.0.1

[client]
port = {port}
default-character-set = utf8mb4

[mysql]
default-character-set = utf8mb4
"#
    )
}

/// Ensures `my.ini` exists in the MariaDB directory, writing default if absent.
pub fn ensure_my_ini<P: AsRef<Path>>(mariadb_base_dir: P, port: u16) -> io::Result<PathBuf> {
    let paths = MariaDbPaths::new(mariadb_base_dir);
    if !paths.my_ini_path.exists() {
        if let Some(parent) = paths.my_ini_path.parent() {
            fs::create_dir_all(parent)?;
        }
        let content = generate_my_ini(&paths.data_dir, port);
        fs::write(&paths.my_ini_path, content)?;
    }
    Ok(paths.my_ini_path)
}

/// Constructs the `Command` to boot MariaDB daemon with `my.ini` silently.
pub fn build_start_mariadb_command(paths: &MariaDbPaths) -> Command {
    let mut cmd = create_silent_command(&paths.server_exe);
    cmd.arg(format!("--defaults-file={}", paths.my_ini_path.to_string_lossy()));
    cmd.arg("--console");
    cmd.current_dir(&paths.base_dir);
    cmd
}

/// Boots the MariaDB daemon as a background subprocess with `CREATE_NO_WINDOW`.
pub fn start_mariadb<P: AsRef<Path>>(base_dir: P) -> io::Result<Child> {
    let paths = MariaDbPaths::new(base_dir);
    let mut cmd = build_start_mariadb_command(&paths);
    cmd.spawn()
}

/// Probes TCP port until MariaDB is accepting connections or timeout occurs.
/// Defaults to a 5-second transition delay window (NFR-01).
pub fn wait_for_mariadb_ready(port: u16, timeout: Duration) -> bool {
    let start = Instant::now();
    let addr: SocketAddr = format!("127.0.0.1:{}", port).parse().unwrap();

    while start.elapsed() < timeout {
        if TcpStream::connect_timeout(&addr, Duration::from_millis(300)).is_ok() {
            return true;
        }
        std::thread::sleep(Duration::from_millis(200));
    }

    false
}

/// Constructs the `Command` to create the Moodle database via `mysql.exe`.
pub fn build_create_database_command(paths: &MariaDbPaths, port: u16, db_name: &str) -> Command {
    let mut cmd = create_silent_command(&paths.client_exe);
    cmd.args([
        "-u",
        "root",
        "-P",
        &port.to_string(),
        "-h",
        "127.0.0.1",
        "-e",
        &format!(
            "CREATE DATABASE IF NOT EXISTS `{}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            db_name
        ),
    ]);
    cmd.current_dir(&paths.base_dir);
    cmd
}

/// Executes SQL query via CLI `mysql.exe` to automatically provision the Moodle database.
pub fn create_database<P: AsRef<Path>>(base_dir: P) -> io::Result<()> {
    create_database_with_params(base_dir, DEFAULT_MARIADB_PORT, DEFAULT_DB_NAME)
}

/// Executes database provisioning with custom port and database name.
pub fn create_database_with_params<P: AsRef<Path>>(base_dir: P, port: u16, db_name: &str) -> io::Result<()> {
    let paths = MariaDbPaths::new(base_dir);
    let mut cmd = build_create_database_command(&paths, port, db_name);
    let status = run_silent_status(&mut cmd)?;

    if status.success() {
        Ok(())
    } else {
        Err(io::Error::new(
            io::ErrorKind::Other,
            format!("Failed to create database '{}', exit code: {:?}", db_name, status.code()),
        ))
    }
}

/// Complete database bootstrap workflow:
/// 1. Ensures `my.ini` exists.
/// 2. Starts MariaDB daemon silently.
/// 3. Waits for TCP port 3306 readiness (up to 5s).
/// 4. Provisions the `moodle` database with `utf8mb4_unicode_ci`.
pub fn bootstrap_mariadb<P: AsRef<Path>>(mariadb_dir: P, port: u16) -> io::Result<Child> {
    ensure_my_ini(&mariadb_dir, port)?;
    let child = start_mariadb(&mariadb_dir)?;

    let is_ready = wait_for_mariadb_ready(port, DEFAULT_READY_TIMEOUT);
    if !is_ready {
        eprintln!("[WARN] MariaDB did not respond within {}s probe window", DEFAULT_READY_TIMEOUT.as_secs());
    }

    // Execute database creation query
    let _ = create_database_with_params(&mariadb_dir, port, DEFAULT_DB_NAME);

    Ok(child)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::net::TcpListener;

    #[test]
    fn test_generate_my_ini_content() {
        let data_dir = Path::new("C:/moodle/runtime/mariadb/data");
        let ini = generate_my_ini(data_dir, 3306);

        assert!(ini.contains("port = 3306"));
        assert!(ini.contains("character-set-server = utf8mb4"));
        assert!(ini.contains("collation-server = utf8mb4_unicode_ci"));
        assert!(ini.contains("innodb_buffer_pool_size = 64M"));
    }

    #[test]
    fn test_ensure_my_ini_creation() {
        let temp_dir = std::env::temp_dir().join(format!("mariadb_ini_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        let ini_path = ensure_my_ini(&temp_dir, 3306).expect("ensure_my_ini should succeed");

        assert!(ini_path.exists());
        let content = fs::read_to_string(&ini_path).expect("must read ini");
        assert!(content.contains("character-set-server = utf8mb4"));

        // Cleanup
        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_build_create_database_command_syntax() {
        let paths = MariaDbPaths::new("./runtime/mariadb");
        let cmd = build_create_database_command(&paths, 3306, "moodle");
        let debug_cmd = format!("{:?}", cmd);

        assert!(debug_cmd.contains("CREATE DATABASE IF NOT EXISTS `moodle` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"));
        assert!(debug_cmd.contains("-u"));
        assert!(debug_cmd.contains("root"));
    }

    #[test]
    fn test_wait_for_mariadb_ready_with_mock_listener() {
        // Bind to an arbitrary available port to simulate MariaDB ready state
        let listener = TcpListener::bind("127.0.0.1:0").expect("Failed to bind test listener");
        let port = listener.local_addr().unwrap().port();

        let is_ready = wait_for_mariadb_ready(port, Duration::from_secs(2));
        assert!(is_ready, "Probing an open TCP listener should return true");
    }

    #[test]
    fn test_wait_for_mariadb_ready_timeout_on_closed_port() {
        // Port 1 is typically closed
        let is_ready = wait_for_mariadb_ready(1, Duration::from_millis(400));
        assert!(!is_ready, "Probing closed port should timeout and return false");
    }
}
