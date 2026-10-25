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
        let moodle_vhost_path = sites_enabled_dir.join("moodle.conf");
        let moodle_vhost = format!(
            "server {{\n\
                listen 127.0.0.1:{port};\n\
                server_name localhost 127.0.0.1;\n\
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
                    fastcgi_read_timeout 300s;\n\
                    fastcgi_send_timeout 300s;\n\
                }}\n\
            }}\n"
        );
        fs::write(&moodle_vhost_path, moodle_vhost)?;

        // 3b. Tulis konfigurasi utama Nginx dengan memanggil include sites-enabled/*.conf secara dinamis
        let nginx_conf_path = conf_dir.join("nginx.conf");
        let nginx_conf = format!(
            "worker_processes 1;\n\
            error_log logs/error.log warn;\n\
            pid logs/nginx.pid;\n\
            \n\
            events {{\n\
                worker_connections 1024;\n\
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
                fastcgi_buffers 16 16k;\n\
                fastcgi_buffer_size 32k;\n\
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