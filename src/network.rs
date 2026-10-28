use std::net::{SocketAddr, TcpListener, TcpStream};
use std::thread;
use std::time::{Duration, Instant};

/// Memeriksa apakah suatu port TCP lokal tersedia untuk digunakan
pub fn is_port_available(port: u16) -> bool {
    let addr: SocketAddr = format!("127.0.0.1:{}", port).parse().unwrap();
    TcpListener::bind(addr).is_ok()
}

/// Mendeteksi alamat IP lokal aktif (LAN/Wi-Fi) yang digunakan mesin
pub fn get_active_local_ip() -> String {
    // 1. Coba hubungkan socket UDP semu ke gateway / DNS publik tanpa mengirim paket data
    if let Ok(socket) = std::net::UdpSocket::bind("0.0.0.0:0") {
        if socket.connect("8.8.8.8:80").is_ok() {
            if let Ok(addr) = socket.local_addr() {
                let ip = addr.ip().to_string();
                if ip != "0.0.0.0" && !ip.starts_with("127.") {
                    return ip;
                }
            }
        }
    }

    // 2. Fallback: coba hubungkan ke router LAN standar
    if let Ok(socket) = std::net::UdpSocket::bind("0.0.0.0:0") {
        if socket.connect("192.168.1.1:80").is_ok() {
            if let Ok(addr) = socket.local_addr() {
                let ip = addr.ip().to_string();
                if ip != "0.0.0.0" && !ip.starts_with("127.") {
                    return ip;
                }
            }
        }
    }

    // 3. Fallback: 127.0.0.1 jika mesin terisolasi / offline tanpa router
    "127.0.0.1".to_string()
}

/// Menemukan port yang tersedia mulai dari port yang diinginkan (preferred)
pub fn find_available_port(preferred_port: u16) -> u16 {
    if is_port_available(preferred_port) {
        return preferred_port;
    }

    // Cari port berikutnya di rentang preferred..preferred+200
    for offset in 1..=200 {
        let candidate = preferred_port.wrapping_add(offset);
        if is_port_available(candidate) {
            println!(
                "[NETWORK] Port {} sedang dipakai. Mengalihkan ke port {}.",
                preferred_port, candidate
            );
            return candidate;
        }
    }

    // Fallback: minta sistem operasi memberikan port dinamis bebas
    TcpListener::bind("127.0.0.1:0")
        .and_then(|listener| listener.local_addr())
        .map(|addr| addr.port())
        .unwrap_or(preferred_port)
}

/// Menunggu hingga suatu port TCP siap menerima koneksi (misal MariaDB, PHP-CGI, Nginx)
pub fn wait_for_port(port: u16, timeout: Duration) -> bool {
    let addr: SocketAddr = format!("127.0.0.1:{}", port).parse().unwrap();
    let start = Instant::now();

    while start.elapsed() < timeout {
        if TcpStream::connect_timeout(&addr, Duration::from_millis(200)).is_ok() {
            return true;
        }
        thread::sleep(Duration::from_millis(100));
    }

    false
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_is_port_available_and_bind() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();

        // Karena listener aktif, port tidak tersedia
        assert!(!is_port_available(port));

        drop(listener);
        // Setelah listener ditutup, port harus kembali tersedia
        assert!(is_port_available(port));
    }

    #[test]
    fn test_find_available_port_fallback() {
        let port = find_available_port(8080);
        assert!(port > 0);
        assert!(is_port_available(port));
    }

    #[test]
    fn test_wait_for_port_readiness() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();

        let ready = wait_for_port(port, Duration::from_secs(1));
        assert!(ready);
    }
}
