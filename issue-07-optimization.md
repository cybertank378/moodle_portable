# Issue 07: Optimasi Performa Nginx, PHP, dan MariaDB untuk Lingkungan Portabel

**Label:** `performance`, `optimization`, `backend`  
**Bergantung pada:** Issue 01, Issue 04  

## Deskripsi
Menerapkan penyesuaian (*tuning*) tingkat lanjut pada berkas konfigurasi `nginx.conf`, `php.ini`, dan `my.ini`. Optimasi ini dirancang agar stack web server lokal mengonsumsi RAM sekecil mungkin saat idle, namun memiliki *throughput* tinggi dan *query caching* yang agresif saat Moodle digunakan oleh banyak siswa secara bersamaan.

---

## 1. Task List Implementasi
- [ ] **Optimasi Nginx (`nginx/conf/nginx.conf`)**:
  - Mengaktifkan *worker connections* dan *multi-accept* untuk menangani ribuan *concurrent requests*.
  - Mengonfigurasi buffer FastCGI yang lebih besar agar tidak terjadi error `upstream sent too big header` saat memuat kuis Moodle yang kompleks.
  - Mengaktifkan kompresi Gzip untuk mempercepat transfer aset statis (JS, CSS, SVG).
- [ ] **Optimasi PHP & OPcache (`php/php.ini`)**:
  - Mengaktifkan dan men-tuning **OPcache** agar seluruh skrip PHP Moodle di-cache langsung di dalam RAM (menghilangkan I/O disk yang lambat pada *flashdisk*).
  - Menaikkan `memory_limit` dan `max_input_vars` untuk mengakomodasi form bank soal Moodle yang besar.
- [ ] **Optimasi MariaDB (`mariadb/data/my.ini`)**:
  - Mengonfigurasi `innodb_buffer_pool_size` secara proporsional untuk perangkat portabel (256MB).
  - Mengatur `innodb_flush_log_at_trx_commit = 2` guna mendongkrak performa tulis database secara drastis saat ujian berlangsung, sekaligus menjaga keamanan data.
  - Mematikan resolusi DNS (`skip-name-resolve`) agar koneksi database lokal instan tanpa jeda.

---

## 2. Detail Konfigurasi Optimasi

### A. Konfigurasi Nginx yang Dioptimalkan (`nginx/conf/nginx.conf`)
```nginx
worker_processes  auto;
error_log  logs/error.log warn;
pid        logs/nginx.pid;

events {
    worker_connections  2048;
    use epoll; # Dioptimalkan untuk I/O Windows/Linux
    multi_accept on;
}

http {
    include       mime.types;
    default_type  application/octet-stream;

    client_body_temp_path temp/client_body_temp;
    proxy_temp_path       temp/proxy_temp;
    fastcgi_temp_path     temp/fastcgi_temp;

    sendfile        on;
    tcp_nopush      on;
    tcp_nodelay     on;
    keepalive_timeout  65;

    # Batas ukuran upload file & kuis besar
    client_max_body_size 512M;
    client_body_buffer_size 512k;

    # Kompresi Gzip untuk mempercepat load asset Moodle
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;

    server {
        listen       127.0.0.1:8080;
        server_name  localhost 127.0.0.1;
        root         "../moodle";
        index        index.php index.html;

        # Cache file statis agar performa browser ringan
        location ~* \.(ico|css|js|gif|jpe?g|png|woff2?|eot|ttf|svg)$ {
            expires 30d;
            add_header Cache-Control "public, no-transform";
            access_log off;
        }

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ [^/]+\.php(/|$) {
            fastcgi_split_path_info  ^(.+\.php)(/.+)$;
            fastcgi_index            index.php;
            fastcgi_pass             127.0.0.1:9000;
            include                  fastcgi_params;
            fastcgi_param   PATH_INFO       $fastcgi_path_info;
            fastcgi_param   SCRIPT_FILENAME $document_root$fastcgi_script_name;
            
            # Fastcgi Buffer Tuning untuk Moodle
            fastcgi_read_timeout 600;
            fastcgi_buffers 32 32k;
            fastcgi_buffer_size 64k;
            fastcgi_busy_buffers_size 128k;
        }

        location ~ /\. {
            deny all;
        }
    }
}