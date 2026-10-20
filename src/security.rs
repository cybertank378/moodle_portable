use std::fs;
use std::io;
use std::path::{Path, PathBuf};

/// Layout configuration for the runtime sandbox environment (NFR-04).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SandboxLayout {
    pub runtime_dir: PathBuf,
    pub moodle_dir: PathBuf,
    pub moodledata_dir: PathBuf,
    pub nginx_dir: PathBuf,
    pub nginx_conf_dir: PathBuf,
    pub nginx_conf_file: PathBuf,
}

impl SandboxLayout {
    /// Creates a new SandboxLayout based on the base runtime directory.
    ///
    /// Following NFR-04, `moodledata` is located strictly outside `moodle_dir`,
    /// structured as a parallel directory inside `runtime_dir`.
    pub fn new<P: AsRef<Path>>(runtime_dir: P) -> Self {
        let runtime = runtime_dir.as_ref().to_path_buf();
        let moodle_dir = runtime.join("moodle");
        let moodledata_dir = runtime.join("moodledata");
        let nginx_dir = runtime.join("nginx");
        let nginx_conf_dir = nginx_dir.join("conf");
        let nginx_conf_file = nginx_conf_dir.join("nginx.conf");

        Self {
            runtime_dir: runtime,
            moodle_dir,
            moodledata_dir,
            nginx_dir,
            nginx_conf_dir,
            nginx_conf_file,
        }
    }

    /// Verifies that `moodledata` is strictly isolated from the `moodle` web root directory.
    pub fn is_moodledata_isolated(&self) -> bool {
        // moodledata must not reside within moodle_dir
        !self.moodledata_dir.starts_with(&self.moodle_dir)
    }

    /// Initializes and prepares the directory sandbox structure on disk.
    pub fn initialize_directories(&self) -> io::Result<()> {
        fs::create_dir_all(&self.runtime_dir)?;
        fs::create_dir_all(&self.moodle_dir)?;
        fs::create_dir_all(&self.moodledata_dir)?;
        fs::create_dir_all(&self.nginx_conf_dir)?;
        Ok(())
    }

    /// Generates and writes the secure `nginx.conf` file into the sandbox.
    pub fn write_secure_nginx_conf(&self, listen_port: u16) -> io::Result<()> {
        self.initialize_directories()?;
        let content = generate_secure_nginx_conf(listen_port);
        fs::write(&self.nginx_conf_file, content)?;
        Ok(())
    }
}

/// Generates a secure `nginx.conf` string configured specifically for Moodle.
///
/// Features:
/// - Root directory points strictly to `../moodle` (outside `moodledata`).
/// - Blocks direct access to hidden files (`.git`, `.env`, `.htaccess`).
/// - Blocks access to sensitive extensions (`.lock`, `.yml`, `.sql`, `.bak`).
/// - Blocks any direct URL path queries towards `moodledata`.
/// - Supports Moodle slasharguments routing.
pub fn generate_secure_nginx_conf(listen_port: u16) -> String {
    format!(
r#"worker_processes 1;
error_log logs/error.log warn;
pid logs/nginx.pid;

events {{
    worker_connections 1024;
}}

http {{
    include mime.types;
    default_type application/octet-stream;
    sendfile on;
    keepalive_timeout 65;

    server {{
        listen {listen_port};
        server_name localhost;

        # Web root strictly isolated to moodle core files (NFR-04)
        root ../moodle;
        index index.php index.html index.htm;

        # Block direct access to hidden files and folders (.git, .env, etc.)
        location ~ /\. {{
            deny all;
            return 403;
        }}

        # Block direct access to sensitive configuration and repository files
        location ~* \.(env|git|lock|yml|yaml|bak|config|sql)$ {{
            deny all;
            return 403;
        }}

        # Block any attempt to access moodledata directly through web server
        location ~* /(moodledata|temp|cache)/ {{
            deny all;
            return 404;
        }}

        # Moodle slasharguments and standard URL rewrite
        location / {{
            try_files $uri $uri/ /index.php$is_args$args;
        }}

        # FastCGI PHP configuration with slasharguments support
        location ~ [^/]\.php(/|$) {{
            fastcgi_split_path_info ^(.+\.php)(/.+)$;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param PATH_INFO $fastcgi_path_info;
        }}
    }}
}}
"#
    )
}

/// Validates that an `nginx.conf` string adheres to Moodle Portable security specifications.
pub fn validate_nginx_security(conf: &str) -> Result<(), &'static str> {
    if !conf.contains("root ../moodle;") && !conf.contains("root moodle;") {
        return Err("Nginx root directive does not point strictly to moodle web root");
    }

    if !conf.contains(r"location ~ /\.") {
        return Err("Missing protection rule for hidden files (.git, .env)");
    }

    if !conf.contains("env") || !conf.contains("git") {
        return Err("Missing protection rule for sensitive file extensions");
    }

    if !conf.contains("moodledata") {
        return Err("Missing explicit protection block for moodledata access");
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_moodledata_is_isolated_outside_moodle() {
        let sandbox = SandboxLayout::new("./runtime");
        assert!(sandbox.is_moodledata_isolated(), "moodledata must be outside moodle_dir");
        assert_eq!(sandbox.moodle_dir, PathBuf::from("./runtime/moodle"));
        assert_eq!(sandbox.moodledata_dir, PathBuf::from("./runtime/moodledata"));
        assert_ne!(sandbox.moodle_dir, sandbox.moodledata_dir);
    }

    #[test]
    fn test_generate_and_validate_secure_nginx_conf() {
        let conf = generate_secure_nginx_conf(80);
        let validation = validate_nginx_security(&conf);
        assert!(validation.is_ok(), "Generated nginx.conf should pass security validation: {:?}", validation.err());

        assert!(conf.contains("root ../moodle;"));
        assert!(conf.contains(r"location ~ /\."));
        assert!(conf.contains("return 403;"));
        assert!(conf.contains("moodledata"));
        assert!(conf.contains("return 404;"));
    }

    #[test]
    fn test_write_secure_nginx_conf_to_disk() {
        let temp_dir = std::env::temp_dir().join(format!("moodle_sandbox_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        let sandbox = SandboxLayout::new(&temp_dir);

        sandbox.write_secure_nginx_conf(8080).expect("Writing secure nginx conf should succeed");

        assert!(sandbox.moodle_dir.exists());
        assert!(sandbox.moodledata_dir.exists());
        assert!(sandbox.nginx_conf_file.exists());

        let written_content = fs::read_to_string(&sandbox.nginx_conf_file).expect("Must read written nginx conf");
        assert!(written_content.contains("listen 8080;"));
        assert!(written_content.contains("root ../moodle;"));

        // Cleanup
        let _ = fs::remove_dir_all(&temp_dir);
    }
}
