use anyhow::Result;
use std::env;
use std::fs;
use std::path::{Path, PathBuf};

#[derive(Clone, Debug)]
pub struct PortablePaths {
    pub base_dir: PathBuf,
    pub runtime_dir: PathBuf,
    pub vault_file: PathBuf,
    pub nginx_dir: PathBuf,
    pub php_dir: PathBuf,
    pub mariadb_dir: PathBuf,
    pub moodle_dir: PathBuf,
    pub moodledata_dir: PathBuf,
}

impl PortablePaths {
    /// Inisialisasi jalur portabel secara otomatis dari direktori executable berjalan
    pub fn init() -> Result<Self> {
        let exe_path = env::current_exe()?;
        let mut base_dir = exe_path
            .parent()
            .ok_or_else(|| anyhow::anyhow!("Gagal mendeteksi direktori induk aplikasi"))?
            .to_path_buf();

        // Jika binary dijalankan dari target/debug atau target/release (misalnya via `cargo run`),
        // dan folder nginx/vault tidak ada di sana, gunakan current_dir jika valid
        if !base_dir.join("nginx").exists() && !base_dir.join("aksaventra_vault.pak").exists() {
            if let Ok(cwd) = env::current_dir() {
                if cwd.join("nginx").exists() || cwd.join("aksaventra_vault.pak").exists() {
                    base_dir = cwd;
                }
            }
        }

        Ok(Self::from_base_dir(base_dir))
    }

    /// Membuat instance PortablePaths dari direktori basis tertentu
    pub fn from_base_dir(base_dir: PathBuf) -> Self {
        let vault_file = base_dir.join("aksaventra_vault.pak");
        let runtime_dir = if vault_file.exists() {
            base_dir.join(".aksaventra_rt")
        } else if base_dir.join("nginx").exists() {
            base_dir.clone()
        } else {
            base_dir.join(".aksaventra_rt")
        };

        Self {
            nginx_dir: runtime_dir.join("nginx"),
            php_dir: runtime_dir.join("php"),
            mariadb_dir: runtime_dir.join("mariadb"),
            moodle_dir: runtime_dir.join("moodle"),
            moodledata_dir: runtime_dir.join("moodledata"),
            base_dir,
            runtime_dir,
            vault_file,
        }
    }

    /// Mengubah slash Windows (\) menjadi forward slash (/) untuk config Nginx, PHP, MariaDB, dan Moodle
    pub fn to_posix_path(path: &Path) -> String {
        path.to_string_lossy().replace('\\', "/")
    }

    /// Menulis ulang konfigurasi agar selalu portabel di komputer/drive mana pun
    pub fn patch_portable_configs(&self, port: u16, profile: &crate::hardware::HardwareProfile) -> Result<()> {
        let moodle_posix = Self::to_posix_path(&self.moodle_dir);
        let mariadb_posix = Self::to_posix_path(&self.mariadb_dir);

        // 1. Patch MariaDB (my.ini)
        let maria_data_dir = self.mariadb_dir.join("data");
        fs::create_dir_all(&maria_data_dir)?;
        let my_ini_path = maria_data_dir.join("my.ini");
        let my_ini_content = format!(
            "[mysqld]\n\
            basedir=\"{mariadb_posix}\"\n\
            datadir=\"{mariadb_posix}/data\"\n\
            port=3306\n\
            bind-address=127.0.0.1\n\
            character-set-server=utf8mb4\n\
            collation-server=utf8mb4_unicode_ci\n\
            innodb_buffer_pool_size={}\n\
            max_connections={}\n\
            max_allowed_packet=64M\n\
            connect_timeout=30\n\
            wait_timeout=600\n",
            profile.buffer_pool,
            profile.max_connections
        );
        fs::write(&my_ini_path, my_ini_content)?;

        // 2. Patch PHP (php.ini) - update extension_dir & session.save_path
        let php_ini_path = self.php_dir.join("php.ini");
        if php_ini_path.exists() {
            let php_ini = fs::read_to_string(&php_ini_path)?;
            let session_dir = self.moodledata_dir.join("sessions");
            fs::create_dir_all(&session_dir)?;
            let session_posix = Self::to_posix_path(&session_dir);

            let mut updated_lines = Vec::new();
            let mut extension_dir_set = false;
            let mut session_save_path_set = false;

            for line in php_ini.lines() {
                let trimmed = line.trim_start();
                if trimmed.starts_with("extension_dir") || trimmed.starts_with(";extension_dir") {
                    updated_lines.push("extension_dir = \"ext\"".to_string());
                    extension_dir_set = true;
                } else if trimmed.starts_with("session.save_path")
                    || trimmed.starts_with(";session.save_path")
                {
                    updated_lines.push(format!("session.save_path = \"{}\"", session_posix));
                    session_save_path_set = true;
                } else {
                    updated_lines.push(line.to_string());
                }
            }

            if !extension_dir_set {
                updated_lines.push("extension_dir = \"ext\"".to_string());
            }
            if !session_save_path_set {
                updated_lines.push(format!("session.save_path = \"{}\"", session_posix));
            }

            fs::write(&php_ini_path, updated_lines.join("\n"))?;
        }

        // 3. Patch Nginx (conf/nginx.conf & conf/sites-enabled/moodle.conf)
        let nginx_posix = Self::to_posix_path(&self.nginx_dir);
        let conf_dir = self.nginx_dir.join("conf");
        let sites_enabled_dir = conf_dir.join("sites-enabled");
        let logs_dir = self.nginx_dir.join("logs");
        let temp_dir = self.nginx_dir.join("temp");

        fs::create_dir_all(&conf_dir)?;
        fs::create_dir_all(&sites_enabled_dir)?;
        fs::create_dir_all(&logs_dir)?;
        fs::create_dir_all(&temp_dir)?;

        // 3a. Tulis virtual host Moodle di sites-enabled/moodle.conf secara dinamis
        // Listen pada seluruh interface (0.0.0.0:{port}) dan server_name _ agar dapat diakses dari IP lokal aktif / Wi-Fi
        let moodle_vhost_path = sites_enabled_dir.join("moodle.conf");
        let moodle_vhost = format!(
            "server {{\n\
                listen {port};\n\
                server_name _;\n\
                root \"{moodle_posix}\";\n\
                index index.php index.html index.htm;\n\
                \n\
                location ~ /\\. {{\n\
                    deny all;\n\
                    return 403;\n\
                }}\n\
                \n\
                location ~* \\.(env|git|lock|yml|yaml|bak|config|sql)$ {{\n\
                    deny all;\n\
                    return 403;\n\
                }}\n\
                \n\
                location ~* /(moodledata|temp|cache)/ {{\n\
                    deny all;\n\
                    return 404;\n\
                }}\n\
                \n\
                location / {{\n\
                    try_files $uri $uri/ /index.php$is_args$args;\n\
                }}\n\
                \n\
                location ~ [^/]+\\.php(/|$) {{\n\
                    fastcgi_split_path_info ^(.+\\.php)(/.+)$;\n\
                    fastcgi_pass 127.0.0.1:9000;\n\
                    fastcgi_index index.php;\n\
                    include fastcgi_params;\n\
                    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
                    fastcgi_param PATH_INFO $fastcgi_path_info;\n\
                    fastcgi_connect_timeout 60s;\n\
                    fastcgi_send_timeout 300s;\n\
                    fastcgi_read_timeout 300s;\n\
                    fastcgi_buffer_size 128k;\n\
                    fastcgi_buffers 16 128k;\n\
                    fastcgi_busy_buffers_size 256k;\n\
                    fastcgi_temp_file_write_size 512k;\n\
                }}\n\
            }}\n"
        );
        fs::write(&moodle_vhost_path, moodle_vhost)?;

        // 3b. Tulis konfigurasi utama Nginx dengan worker pool dan buffer optimal untuk mencegah 503
        let nginx_connections = profile.nginx_connections;
        let nginx_conf_path = conf_dir.join("nginx.conf");
        let nginx_conf = format!(
            "worker_processes 1;\n\
            error_log logs/error.log warn;\n\
            pid logs/nginx.pid;\n\
            \n\
            events {{\n\
                worker_connections {nginx_connections};\n\
            }}\n\
            \n\
            http {{\n\
                include mime.types;\n\
                default_type application/octet-stream;\n\
                \n\
                charset utf-8;\n\
                server_tokens off;\n\
                sendfile on;\n\
                tcp_nopush on;\n\
                tcp_nodelay on;\n\
                keepalive_timeout 65;\n\
                client_max_body_size 512M;\n\
                client_body_buffer_size 128k;\n\
                \n\
                fastcgi_connect_timeout 60s;\n\
                fastcgi_send_timeout 300s;\n\
                fastcgi_read_timeout 300s;\n\
                fastcgi_buffer_size 128k;\n\
                fastcgi_buffers 16 128k;\n\
                fastcgi_busy_buffers_size 256k;\n\
                fastcgi_temp_file_write_size 512k;\n\
                fastcgi_intercept_errors off;\n\
                \n\
                # ============================================================\n\
                # LOAD VIRTUAL HOSTS (DINAMIS PORTABEL)\n\
                # ============================================================\n\
                include \"{nginx_posix}/conf/sites-enabled/*.conf\";\n\
            }}\n"
        );
        fs::write(&nginx_conf_path, nginx_conf)?;

        // 4. Patch Moodle (config.php)
        let moodle_cfg_path = self.moodle_dir.join("config.php");
        if moodle_cfg_path.exists() {
            let cfg_content = fs::read_to_string(&moodle_cfg_path)?;
            let active_ip = crate::network::get_active_local_ip();
            let mut updated_lines = Vec::new();

            for line in cfg_content.lines() {
                let trimmed = line.trim_start();
                if trimmed.starts_with("$CFG->dataroot") {
                    // Gunakan ekspresi dinamis dirname(__DIR__) agar 100% portabel tanpa hardcoded path
                    updated_lines.push("$CFG->dataroot  = dirname(__DIR__) . '/moodledata';".to_string());
                } else if trimmed.starts_with("$CFG->wwwroot") {
                    // Gunakan logika dinamis berbasis HTTP_HOST request atau fallback ke IP lokal aktif
                    let dynamic_wwwroot = format!(
                        "$CFG->wwwroot   = !empty($_SERVER['HTTP_HOST']) ? (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : 'http://{}:{}';",
                        active_ip, port
                    );
                    updated_lines.push(dynamic_wwwroot);
                } else {
                    updated_lines.push(line.to_string());
                }
            }
            fs::write(&moodle_cfg_path, updated_lines.join("\n"))?;
        }

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_to_posix_path_converts_windows_slashes() {
        let win_path = Path::new("D:\\Aksaventra\\runtime\\moodle");
        assert_eq!(
            PortablePaths::to_posix_path(win_path),
            "D:/Aksaventra/runtime/moodle"
        );
    }

    #[test]
    fn test_portable_paths_layout() {
        let base = PathBuf::from("D:/Aksaventra");
        let paths = PortablePaths::from_base_dir(base.clone());

        assert_eq!(paths.base_dir, base);
        assert_eq!(paths.runtime_dir, base.join(".aksaventra_rt"));
        assert_eq!(paths.vault_file, base.join("aksaventra_vault.pak"));
        assert_eq!(paths.nginx_dir, base.join(".aksaventra_rt").join("nginx"));
        assert_eq!(paths.php_dir, base.join(".aksaventra_rt").join("php"));
        assert_eq!(paths.mariadb_dir, base.join(".aksaventra_rt").join("mariadb"));
        assert_eq!(paths.moodle_dir, base.join(".aksaventra_rt").join("moodle"));
        assert_eq!(
            paths.moodledata_dir,
            base.join(".aksaventra_rt").join("moodledata")
        );
    }

    #[test]
    fn test_patch_portable_configs_end_to_end() {
        let timestamp = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let temp_dir = std::env::temp_dir().join(format!("aksaventra_paths_test_{}", timestamp));
        let paths = PortablePaths::from_base_dir(temp_dir.clone());

        // Buat berkas tiruan untuk php.ini dan config.php
        fs::create_dir_all(&paths.php_dir).unwrap();
        fs::write(
            paths.php_dir.join("php.ini"),
            ";extension_dir = \"ext\"\n;session.save_path = \"/tmp\"\nmemory_limit = 512M\n",
        )
        .unwrap();

        fs::create_dir_all(&paths.moodle_dir).unwrap();
        fs::write(
            paths.moodle_dir.join("config.php"),
            "<?php\n$CFG->wwwroot = 'http://old-domain.com';\n$CFG->dataroot = '/old/dataroot';\n",
        )
        .unwrap();

        // Jalankan patching pada port 9090 dengan concurrency default 16
        // Jalankan patching pada port 9090 dengan profil Balanced (16 workers)
        let profile_balanced = crate::hardware::custom_profile(16);
        paths
            .patch_portable_configs(9090, &profile_balanced)
            .expect("patching harus berhasil");

        // 1. Verifikasi MariaDB my.ini
        let my_ini = fs::read_to_string(paths.mariadb_dir.join("data").join("my.ini")).unwrap();
        assert!(my_ini.contains("port=3306"));
        assert!(my_ini.contains("max_connections=250"));
        assert!(my_ini.contains("innodb_buffer_pool_size=256M"));
        let maria_posix = PortablePaths::to_posix_path(&paths.mariadb_dir);
        assert!(my_ini.contains(&format!("basedir=\"{}\"", maria_posix)));

        // 2. Verifikasi PHP php.ini
        let php_ini = fs::read_to_string(paths.php_dir.join("php.ini")).unwrap();
        let session_posix =
            PortablePaths::to_posix_path(&paths.moodledata_dir.join("sessions"));
        assert!(php_ini.contains("extension_dir = \"ext\""));
        assert!(php_ini.contains(&format!("session.save_path = \"{}\"", session_posix)));

        // 3. Verifikasi Nginx nginx.conf & sites-enabled/moodle.conf
        let nginx_conf =
            fs::read_to_string(paths.nginx_dir.join("conf").join("nginx.conf")).unwrap();
        assert!(nginx_conf.contains("worker_connections 2048;"));
        let nginx_posix = PortablePaths::to_posix_path(&paths.nginx_dir);
        assert!(nginx_conf.contains(&format!(
            "include \"{}/conf/sites-enabled/*.conf\";",
            nginx_posix
        )));

        let moodle_conf = fs::read_to_string(
            paths
                .nginx_dir
                .join("conf")
                .join("sites-enabled")
                .join("moodle.conf"),
        )
        .unwrap();
        assert!(moodle_conf.contains("listen 9090;"));
        let moodle_posix = PortablePaths::to_posix_path(&paths.moodle_dir);
        assert!(moodle_conf.contains(&format!("root \"{}\";", moodle_posix)));

        // 4. Verifikasi Moodle config.php
        let moodle_cfg = fs::read_to_string(paths.moodle_dir.join("config.php")).unwrap();
        assert!(moodle_cfg.contains("$CFG->wwwroot") && moodle_cfg.contains(":9090"));
        assert!(moodle_cfg.contains("$CFG->dataroot  = dirname(__DIR__) . '/moodledata';"));

        // 5. Verifikasi dynamic scaling dengan profil Custom (64 workers)
        let profile_custom = crate::hardware::custom_profile(64);
        paths
            .patch_portable_configs(9090, &profile_custom)
            .expect("patching dengan concurrency 64 harus berhasil");
        let scaled_my_ini = fs::read_to_string(paths.mariadb_dir.join("data").join("my.ini")).unwrap();
        assert!(scaled_my_ini.contains("max_connections=512"));
        assert!(scaled_my_ini.contains("innodb_buffer_pool_size=1024M"));
        let scaled_nginx_conf = fs::read_to_string(paths.nginx_dir.join("conf").join("nginx.conf")).unwrap();
        assert!(scaled_nginx_conf.contains("worker_connections 8192;"));

        let _ = fs::remove_dir_all(&temp_dir);
    }
}