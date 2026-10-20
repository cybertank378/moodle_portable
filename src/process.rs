use std::ffi::OsStr;
use std::io;
use std::process::{Child, Command, ExitStatus, Stdio};

#[cfg(windows)]
use std::os::windows::process::CommandExt;

/// Win32 process creation flag to prevent console window creation.
/// Value: 0x08000000 (CREATE_NO_WINDOW)
pub const CREATE_NO_WINDOW: u32 = 0x0800_0000;

/// Extension trait to configure `std::process::Command` for silent execution.
pub trait SilentCommandExt {
    /// Applies `CREATE_NO_WINDOW` and redirects stdout & stderr to `Stdio::null()`.
    fn make_silent(&mut self) -> &mut Self;

    /// Applies `CREATE_NO_WINDOW` flag without altering stdout/stderr.
    fn set_silent_flags(&mut self) -> &mut Self;
}

impl SilentCommandExt for Command {
    fn make_silent(&mut self) -> &mut Self {
        self.set_silent_flags();
        self.stdout(Stdio::null());
        self.stderr(Stdio::null());
        self
    }

    fn set_silent_flags(&mut self) -> &mut Self {
        #[cfg(windows)]
        {
            self.creation_flags(CREATE_NO_WINDOW);
        }
        self
    }
}

/// Convenience factory to create a new `Command` pre-configured for silent background execution.
pub fn create_silent_command<P: AsRef<OsStr>>(program: P) -> Command {
    let mut cmd = Command::new(program);
    cmd.make_silent();
    cmd
}

/// Spawns a silent subprocess without creating any console window.
pub fn spawn_silent(cmd: &mut Command) -> io::Result<Child> {
    cmd.make_silent();
    cmd.spawn()
}

/// Runs a silent subprocess to completion and returns its `ExitStatus`.
pub fn run_silent_status(cmd: &mut Command) -> io::Result<ExitStatus> {
    cmd.make_silent();
    cmd.status()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_create_silent_command() {
        let mut cmd = create_silent_command("cmd.exe");
        cmd.args(["/C", "echo", "silent test"]);
        let status = cmd.status();
        assert!(status.is_ok(), "Silent command should execute without error");
        assert!(status.unwrap().success(), "Silent command should exit with success");
    }

    #[test]
    fn test_spawn_silent() {
        let mut cmd = Command::new("cmd.exe");
        cmd.args(["/C", "exit", "0"]);
        let mut child = spawn_silent(&mut cmd).expect("Failed to spawn silent process");
        let status = child.wait().expect("Failed to wait on child process");
        assert!(status.success(), "Child process should exit with 0");
    }

    #[test]
    fn test_run_silent_status() {
        let mut cmd = Command::new("cmd.exe");
        cmd.args(["/C", "exit", "0"]);
        let status = run_silent_status(&mut cmd).expect("Failed to run silent command");
        assert!(status.success(), "Command status should be success");
    }
}
