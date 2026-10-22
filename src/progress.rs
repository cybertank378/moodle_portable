use std::env;
use std::fmt;
use std::path::{Path, PathBuf};

/// Distinct sequential stages of the Moodle Portable installation pipeline (Issue #28).
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum InstallStage {
    /// Stage 1: Payload extraction and dual-mode provisioning (0% - 25%)
    PayloadProvisioning,
    /// Stage 2: Runtime sandbox configuration and core pruning (25% - 45%)
    SandboxAndPruning,
    /// Stage 3: MariaDB bootstrap initialization and readiness probe (45% - 70%)
    DatabaseBootstrap,
    /// Stage 4: Moodle unattended CLI installation and dynamic config (70% - 90%)
    MoodleInstallation,
    /// Stage 5: Daemon services execution and browser trigger (90% - 100%)
    ServicesAndLaunch,
}

impl InstallStage {
    /// Returns 1-based index and total count of stages.
    pub fn step_number(&self) -> (usize, usize) {
        let current = match self {
            InstallStage::PayloadProvisioning => 1,
            InstallStage::SandboxAndPruning => 2,
            InstallStage::DatabaseBootstrap => 3,
            InstallStage::MoodleInstallation => 4,
            InstallStage::ServicesAndLaunch => 5,
        };
        (current, 5)
    }

    /// Title description of the stage.
    pub fn title(&self) -> &'static str {
        match self {
            InstallStage::PayloadProvisioning => "Payload Provisioning",
            InstallStage::SandboxAndPruning => "Sandbox Setup & Pruning",
            InstallStage::DatabaseBootstrap => "MariaDB Database Bootstrap",
            InstallStage::MoodleInstallation => "Aksaventra Core Installation",
            InstallStage::ServicesAndLaunch => "Services Launch & Ready",
        }
    }
}

impl fmt::Display for InstallStage {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let (cur, total) = self.step_number();
        write!(f, "Stage {}/{}: {}", cur, total, self.title())
    }
}

/// Snapshot of an installation progress update.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ProgressEvent {
    pub stage: InstallStage,
    pub percentage: u8,
    pub message: String,
}

impl ProgressEvent {
    pub fn new<S: Into<String>>(stage: InstallStage, percentage: u8, message: S) -> Self {
        Self {
            stage,
            percentage: percentage.min(100),
            message: message.into(),
        }
    }
}

/// Renders a text-based ASCII progress bar of given width.
pub fn format_progress_bar(percentage: u8, width: usize) -> String {
    let p = (percentage as usize).min(100);
    let filled = (p * width) / 100;
    let mut bar = String::with_capacity(width + 2);
    bar.push('[');
    for i in 0..width {
        if i < filled {
            bar.push('=');
        } else if i == filled && filled > 0 && filled < width {
            bar.push('>');
        } else {
            bar.push(' ');
        }
    }
    bar.push(']');
    bar
}

/// Formats a complete single-line progress status string.
pub fn format_progress_status(event: &ProgressEvent, bar_width: usize) -> String {
    let bar = format_progress_bar(event.percentage, bar_width);
    let (cur, total) = event.stage.step_number();
    format!(
        "{} {:>3}% [Stage {}/{}] {} - {}",
        bar, event.percentage, cur, total, event.stage.title(), event.message
    )
}

/// Stateful progress tracker with optional event listener callback.
pub struct ProgressTracker {
    last_event: Option<ProgressEvent>,
    bar_width: usize,
}

impl ProgressTracker {
    pub fn new() -> Self {
        Self {
            last_event: None,
            bar_width: 25,
        }
    }

    pub fn with_bar_width(mut self, width: usize) -> Self {
        self.bar_width = width.max(10);
        self
    }

    /// Records progress and prints the formatted progress line to stdout.
    pub fn advance<S: Into<String>>(&mut self, stage: InstallStage, percentage: u8, message: S) -> ProgressEvent {
        let event = ProgressEvent::new(stage, percentage, message);
        println!("{}", format_progress_status(&event, self.bar_width));
        self.last_event = Some(event.clone());
        event
    }

    pub fn last_event(&self) -> Option<&ProgressEvent> {
        self.last_event.as_ref()
    }
}

impl Default for ProgressTracker {
    fn default() -> Self {
        Self::new()
    }
}

// --- CUSTOM INSTALLATION DIRECTORY RESOLUTION ---

pub const DEFAULT_RUNTIME_DIR: &str = "./runtime";
pub const ENV_INSTALL_DIR: &str = "AKSAVENTRA_INSTALL_DIR";
pub const FALLBACK_ENV_INSTALL_DIR: &str = "MOODLE_INSTALL_DIR";

/// Resolves the target installation directory from CLI arguments, environment variable, or default.
///
/// Precedence:
/// 1. CLI flag: `--install-dir <PATH>`, `-d <PATH>`, `--target <PATH>`, or `-t <PATH>`
/// 2. CLI flag format: `--install-dir=<PATH>` or `--target=<PATH>`
/// 3. Environment variable `AKSAVENTRA_INSTALL_DIR` (or `MOODLE_INSTALL_DIR`)
/// 4. Fallback default: `./runtime`
pub fn resolve_target_directory(args: &[String]) -> PathBuf {
    if let Some(cli_path) = parse_install_dir_from_args(args) {
        return cli_path;
    }

    if let Ok(env_val) = env::var(ENV_INSTALL_DIR) {
        let trimmed = env_val.trim();
        if !trimmed.is_empty() {
            return PathBuf::from(trimmed);
        }
    }

    if let Ok(env_val) = env::var(FALLBACK_ENV_INSTALL_DIR) {
        let trimmed = env_val.trim();
        if !trimmed.is_empty() {
            return PathBuf::from(trimmed);
        }
    }

    PathBuf::from(DEFAULT_RUNTIME_DIR)
}

/// Parses `--install-dir` or related flags from command-line arguments slice.
pub fn parse_install_dir_from_args(args: &[String]) -> Option<PathBuf> {
    let mut iter = args.iter().peekable();
    while let Some(arg) = iter.next() {
        if arg == "--install-dir" || arg == "-d" || arg == "--target" || arg == "-t" {
            if let Some(val) = iter.next() {
                if !val.starts_with('-') {
                    return Some(PathBuf::from(val));
                }
            }
        } else if let Some(val) = arg.strip_prefix("--install-dir=") {
            if !val.trim().is_empty() {
                return Some(PathBuf::from(val.trim()));
            }
        } else if let Some(val) = arg.strip_prefix("--target=") {
            if !val.trim().is_empty() {
                return Some(PathBuf::from(val.trim()));
            }
        }
    }
    None
}

/// Validates that the provided installation path is safe and well-formed.
pub fn validate_target_directory<P: AsRef<Path>>(path: P) -> Result<(), &'static str> {
    let s = path.as_ref().to_string_lossy();
    if s.trim().is_empty() {
        return Err("Installation path cannot be empty");
    }

    // Disallow illegal Windows characters
    let illegal_chars = ['<', '>', '"', '|', '?', '*'];
    for c in illegal_chars {
        if s.contains(c) {
            return Err("Installation path contains illegal characters (< > \" | ? *)");
        }
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_stage_step_numbers_and_titles() {
        assert_eq!(InstallStage::PayloadProvisioning.step_number(), (1, 5));
        assert_eq!(InstallStage::SandboxAndPruning.step_number(), (2, 5));
        assert_eq!(InstallStage::DatabaseBootstrap.step_number(), (3, 5));
        assert_eq!(InstallStage::MoodleInstallation.step_number(), (4, 5));
        assert_eq!(InstallStage::ServicesAndLaunch.step_number(), (5, 5));

        assert_eq!(InstallStage::PayloadProvisioning.title(), "Payload Provisioning");
        assert_eq!(InstallStage::ServicesAndLaunch.title(), "Services Launch & Ready");
    }

    #[test]
    fn test_format_progress_bar_rendering() {
        assert_eq!(format_progress_bar(0, 10), "[          ]");
        assert_eq!(format_progress_bar(50, 10), "[=====>    ]");
        assert_eq!(format_progress_bar(100, 10), "[==========]");
        // Clamp over 100%
        assert_eq!(format_progress_bar(120, 10), "[==========]");
    }

    #[test]
    fn test_format_progress_status_contains_details() {
        let event = ProgressEvent::new(InstallStage::DatabaseBootstrap, 55, "Creating moodle database");
        let status = format_progress_status(&event, 20);

        assert!(status.contains("55%"));
        assert!(status.contains("Stage 3/5"));
        assert!(status.contains("MariaDB Database Bootstrap"));
        assert!(status.contains("Creating moodle database"));
    }

    #[test]
    fn test_progress_tracker_state() {
        let mut tracker = ProgressTracker::new().with_bar_width(15);
        assert!(tracker.last_event().is_none());

        let evt = tracker.advance(InstallStage::PayloadProvisioning, 20, "Extracting payload.zip");
        assert_eq!(evt.percentage, 20);
        assert_eq!(tracker.last_event().unwrap().percentage, 20);
        assert_eq!(tracker.last_event().unwrap().stage, InstallStage::PayloadProvisioning);
    }

    #[test]
    fn test_parse_install_dir_from_args_various_flags() {
        // Space separated
        let args1 = vec!["aksaventra_installer.exe".to_string(), "--install-dir".to_string(), "D:/MoodleTest".to_string()];
        assert_eq!(parse_install_dir_from_args(&args1), Some(PathBuf::from("D:/MoodleTest")));

        let args2 = vec!["aksaventra_installer.exe".to_string(), "-d".to_string(), "C:/Server/Moodle".to_string()];
        assert_eq!(parse_install_dir_from_args(&args2), Some(PathBuf::from("C:/Server/Moodle")));

        let args3 = vec!["aksaventra_installer.exe".to_string(), "--target".to_string(), "E:/Portable".to_string()];
        assert_eq!(parse_install_dir_from_args(&args3), Some(PathBuf::from("E:/Portable")));

        // Equals separated
        let args4 = vec!["--install-dir=D:/EqualTest".to_string()];
        assert_eq!(parse_install_dir_from_args(&args4), Some(PathBuf::from("D:/EqualTest")));

        let args5 = vec!["--target=F:/TargetEqual".to_string()];
        assert_eq!(parse_install_dir_from_args(&args5), Some(PathBuf::from("F:/TargetEqual")));

        // Empty args fallback
        let empty_args: Vec<String> = vec!["aksaventra_installer.exe".to_string()];
        assert_eq!(parse_install_dir_from_args(&empty_args), None);
    }

    #[test]
    fn test_resolve_target_directory_fallback_chain() {
        // 1. With CLI args
        let args = vec!["--install-dir".to_string(), "D:/FromCLI".to_string()];
        assert_eq!(resolve_target_directory(&args), PathBuf::from("D:/FromCLI"));

        // 2. Default when no args and env is unset/empty
        let default_dir = resolve_target_directory(&[]);
        assert_eq!(default_dir, PathBuf::from(DEFAULT_RUNTIME_DIR));
    }

    #[test]
    fn test_validate_target_directory() {
        assert!(validate_target_directory("D:/Valid/Path").is_ok());
        assert!(validate_target_directory("./runtime").is_ok());
        assert!(validate_target_directory("").is_err());
        assert!(validate_target_directory("D:/Invalid*Path").is_err());
        assert!(validate_target_directory("C:/Illegal?Dir").is_err());
    }
}
