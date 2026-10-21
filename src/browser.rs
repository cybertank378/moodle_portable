use std::io;
use std::process::Command;

use crate::network::get_service_url;
use crate::process::{create_silent_command, run_silent_status};

/// Builds the `Command` to launch the system default web browser to `url` (FR-02).
///
/// Uses `cmd.exe /C start "" <url>` with Win32 `CREATE_NO_WINDOW` flag to prevent
/// any console popup window when opening the browser.
pub fn build_open_browser_command(url: &str) -> Command {
    let mut cmd = create_silent_command("cmd.exe");
    // In Windows 'start', the first quoted token is parsed as the window title.
    // Passing an empty string `""` ensures the URL is correctly treated as the target.
    cmd.args(["/C", "start", "", url]);
    cmd
}

/// Opens the system default web browser pointing to `url` silently.
pub fn open_browser(url: &str) -> io::Result<bool> {
    let mut cmd = build_open_browser_command(url);
    let status = run_silent_status(&mut cmd)?;
    Ok(status.success())
}

/// Convenience helper to open the default browser directly to the active Moodle service URL.
pub fn open_moodle_browser(port: u16) -> io::Result<bool> {
    let url = get_service_url(port);
    open_browser(&url)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_build_open_browser_command_syntax() {
        let cmd = build_open_browser_command("http://localhost:8080");
        let debug = format!("{:?}", cmd);

        assert!(debug.contains("cmd.exe"));
        assert!(debug.contains("/C"));
        assert!(debug.contains("start"));
        assert!(debug.contains("http://localhost:8080"));
    }

    #[test]
    fn test_open_moodle_browser_url_resolution() {
        let port = 80;
        let url = get_service_url(port);
        let cmd = build_open_browser_command(&url);
        let debug = format!("{:?}", cmd);

        assert!(debug.contains("http://"));
    }
}
