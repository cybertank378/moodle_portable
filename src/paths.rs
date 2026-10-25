use anyhow::{ Result };
use std::env;
use std::fs;
use std::path::{ Path, PathBuf };

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
    pub fn init() -> Result<Self> {
        let exe_path = env::current_exe()?;
        let base_dir = exe_path.parent().unwrap().to_path_buf();
        let runtime_dir = base_dir.join(".aksaventra_rt");
        let vault_file = base_dir.join("aksaventra_vault.pak");

        Ok(Self {
            nginx_dir: runtime_dir.join("nginx"),
            php_dir: runtime_dir.join("php"),
            mariadb_dir: runtime_dir.join("mariadb"),
            moodle_dir: runtime_dir.join("moodle"),
            moodledata_dir: runtime_dir.join("moodledata"),
            base_dir,
            runtime_dir,
            vault_file,
        })
    }

    /// Mengubah slash Windows (\) menjadi forward slash (/) untuk config Nginx, PHP, MariaDB, dan Moodle
    fn to_posix_path(path: &Path) -> String {
        path.to_string_lossy().replace('\\', "/")
    }

    /// Menulis ulang konfigurasi agar selalu portabel di komputer/drive mana pun
    pub fn patch_portable_configs(&self, port: u16) -> Result<()> {
        let moodle_posix = Self::to_posix_path(&self.moodle_dir);
        let moodledata_posix = Self::to_posix_path(&self.moodledata_dir);
        let php_posix = Self::to_posix_path(&self.php_dir);
        let mariadb_posix = Self::to_posix_path(&self.mariadb_dir);

        // 1. Patch MariaDB (my.ini)
        let my_ini_path = self.mariadb_dir.join("data").join("my.ini");
        let my_ini_content = format!(
            "[mysqld]\n\
            basedir=\"{mariadb_posix}\"\n\
            datadir=\"{mariadb_posix}/data\"\n\
            port=3306\n\
            bind-address=127.0.0.1\n\
            character-set-server=utf8mb4\n\
            collation-server=utf8mb4_unicode_ci\n\
            innodb_buffer_pool_size=256M\n"
        );
        fs::write(&my_ini_path, my_ini_content)?;

        // 2. Patch PHP (php.ini) - update extension_dir & session.save_path
        let php_ini_path = self.php_dir.join("php.ini");
        if php_ini_path.exists() {
            let mut php_ini = fs::read_to_string(&php_ini_path)?;
            let temp_dir = self.moodledata_dir.join("temp");
            let _ = fs::create_dir_all(&temp_dir);
            
            // Pastikan extension_dir selalu mengarah ke folder ext relatif/portabel
            php_ini = php_ini.replace(
                ";extension_dir = \"ext\"",
                &format!("extension_dir = \"{}/ext\"", php_posix)
            );
            fs::write(&php_ini_path, php_ini)?;
        }

        // 3. Patch Nginx (conf/nginx.conf)
        let nginx_conf_path = self.nginx_dir.join("conf").join("nginx.conf");
        let nginx_conf = format!(
            "worker_processes auto;\n\
            events {{ worker_connections 1024; }}\n\
            http {{\n\
                include mime.types;\n\
                default_type application/octet-stream;\n\
                sendfile on;\n\
                keepalive_timeout 65;\n\
                client_max_body_size 512M;\n\
                server {{\n\
                    listen 127.0.0.1:{port};\n\
                    server_name localhost;\n\
                    root \"{moodle_posix}\";\n\
                    index index.php index.html;\n\
                    location / {{\n\
                        try_files $uri $uri/ /index.php?$query_string;\n\
                    }}\n\
                    location ~ [^/]+\\.php(/|$) {{\n\
                        fastcgi_split_path_info ^(.+\\.php)(/.+)$;\n\
                        fastcgi_pass 127.0.0.1:9000;\n\
                        fastcgi_index index.php;\n\
                        include fastcgi_params;\n\
                        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
                        fastcgi_param PATH_INFO $fastcgi_path_info;\n\
                        fastcgi_read_timeout 300;\n\
                    }}\n\
                }}\n\
            }}\n"
        );
        fs::write(&nginx_conf_path, nginx_conf)?;

        // 4. Patch Moodle (config.php)
        let moodle_cfg_path = self.moodle_dir.join("config.php");
        if moodle_cfg_path.exists() {
            let cfg_content = fs::read_to_string(&moodle_cfg_path)?;
            let mut updated_lines = Vec::new();

            for line in cfg_content.lines() {
                if line.trim_start().starts_with("$CFG->dataroot") {
                    updated_lines.push(format!("$CFG->dataroot  = '{}';", moodledata_posix));
                } else if line.trim_start().starts_with("$CFG->wwwroot") {
                    updated_lines.push(format!("$CFG->wwwroot   = 'http://127.0.0.1:{}';", port));
                } else {
                    updated_lines.push(line.to_string());
                }
            }
            fs::write(&moodle_cfg_path, updated_lines.join("\n"))?;
        }

        Ok(())
    }
}