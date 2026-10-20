use std::net::{IpAddr, UdpSocket};

pub const DEFAULT_FALLBACK_IP: &str = "127.0.0.1";
pub const DEFAULT_PROBE_TARGET: &str = "8.8.8.8:80";

/// Resolves the active LAN IP address of the host machine (FR-03).
///
/// Uses an OS routing table UDP probe to determine the primary outbound network interface
/// without transmitting any packets over the network.
/// If the machine is isolated or offline without any network interface, cleanly falls back to `127.0.0.1`.
pub fn get_local_ip() -> String {
    resolve_local_ip(DEFAULT_PROBE_TARGET).unwrap_or_else(|| DEFAULT_FALLBACK_IP.to_string())
}

/// Attempts to resolve the local IP address by probing the routing table for `probe_target`.
pub fn resolve_local_ip(probe_target: &str) -> Option<String> {
    let socket = UdpSocket::bind("0.0.0.0:0").ok()?;
    socket.connect(probe_target).ok()?;
    let local_addr = socket.local_addr().ok()?;

    match local_addr.ip() {
        IpAddr::V4(ipv4) if !ipv4.is_unspecified() && !ipv4.is_broadcast() => Some(ipv4.to_string()),
        IpAddr::V6(ipv6) if !ipv6.is_unspecified() => Some(ipv6.to_string()),
        _ => None,
    }
}

/// Formats a complete accessible HTTP URL for the Moodle service based on the local IP and port.
pub fn get_service_url(port: u16) -> String {
    let ip = get_local_ip();
    if port == 80 {
        format!("http://{}", ip)
    } else {
        format!("http://{}:{}", ip, port)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::str::FromStr;

    #[test]
    fn test_get_local_ip_returns_valid_ip() {
        let ip_str = get_local_ip();
        assert!(!ip_str.is_empty(), "Local IP string should not be empty");

        let parsed = IpAddr::from_str(&ip_str);
        assert!(parsed.is_ok(), "Resolved IP '{}' must be a valid IP address", ip_str);

        // Must not be 0.0.0.0
        let ip = parsed.unwrap();
        assert!(!ip.is_unspecified(), "Resolved IP must not be unspecified (0.0.0.0)");
    }

    #[test]
    fn test_resolve_local_ip_fallback_on_invalid_target() {
        // Using an invalid probe target should return None
        let result = resolve_local_ip("invalid-address:99999");
        assert_eq!(result, None);
    }

    #[test]
    fn test_get_service_url_standard_and_custom_port() {
        let ip = get_local_ip();

        let url_80 = get_service_url(80);
        assert_eq!(url_80, format!("http://{}", ip));

        let url_custom = get_service_url(8080);
        assert_eq!(url_custom, format!("http://{}:8080", ip));
    }
}
