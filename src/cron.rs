use std::path::{Path, PathBuf};
use std::process::Command;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant};

use crate::process::{create_silent_command, run_silent_status};

/// Default Moodle cron interval (FR-06).
pub const DEFAULT_CRON_INTERVAL: Duration = Duration::from_secs(60);

/// Granularity of the sleep loop so a stop request is honoured quickly
/// (needed by the graceful shutdown manager, Issue #8).
const SLEEP_SLICE: Duration = Duration::from_millis(250);

/// Paths required to execute Moodle cron.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CronPaths {
    pub php_exe: PathBuf,
    pub moodle_dir: PathBuf,
    pub cron_script: PathBuf,
}

impl CronPaths {
    /// `runtime_dir` is the extracted runtime root (e.g. `./runtime`).
    pub fn new<P: AsRef<Path>>(runtime_dir: P) -> Self {
        let runtime = runtime_dir.as_ref();
        let moodle_dir = runtime.join("moodle");
        Self {
            php_exe: runtime.join("php").join("php.exe"),
            cron_script: moodle_dir.join("admin").join("cli").join("cron.php"),
            moodle_dir,
        }
    }

    /// Cron can only run when both PHP CLI and Moodle's cron script exist.
    pub fn is_ready(&self) -> bool {
        self.php_exe.exists() && self.cron_script.exists()
    }
}

/// Builds the silent (`CREATE_NO_WINDOW`) `php.exe admin/cli/cron.php` command.
pub fn build_cron_command(paths: &CronPaths) -> Command {
    let mut cmd = create_silent_command(&paths.php_exe);
    cmd.arg(&paths.cron_script);
    cmd.current_dir(&paths.moodle_dir);
    cmd
}

/// Runs a single Moodle cron pass synchronously. Returns `true` on success.
pub fn run_cron_once(paths: &CronPaths) -> bool {
    if !paths.is_ready() {
        return false;
    }
    let mut cmd = build_cron_command(paths);
    matches!(run_silent_status(&mut cmd), Ok(status) if status.success())
}

/// Handle to a running background cron thread.
pub struct CronHandle {
    stop_flag: Arc<AtomicBool>,
    run_count: Arc<AtomicU64>,
    thread: Option<JoinHandle<()>>,
}

impl CronHandle {
    /// Number of cron passes executed so far.
    pub fn run_count(&self) -> u64 {
        self.run_count.load(Ordering::SeqCst)
    }

    /// Signals the cron thread to stop (non-blocking).
    pub fn request_stop(&self) {
        self.stop_flag.store(true, Ordering::SeqCst);
    }

    /// Signals the cron thread to stop and waits for it to finish.
    pub fn stop(mut self) {
        self.request_stop();
        if let Some(handle) = self.thread.take() {
            let _ = handle.join();
        }
    }
}

impl Drop for CronHandle {
    fn drop(&mut self) {
        self.stop_flag.store(true, Ordering::SeqCst);
    }
}

/// Generic scheduler: invokes `task` immediately, then every `interval`,
/// on a dedicated background thread until stopped.
pub fn spawn_scheduler<F>(interval: Duration, mut task: F) -> CronHandle
where
    F: FnMut() + Send + 'static,
{
    let stop_flag = Arc::new(AtomicBool::new(false));
    let run_count = Arc::new(AtomicU64::new(0));

    let thread_stop = Arc::clone(&stop_flag);
    let thread_count = Arc::clone(&run_count);

    let thread = thread::Builder::new()
        .name("moodle-cron".to_string())
        .spawn(move || {
            while !thread_stop.load(Ordering::SeqCst) {
                task();
                thread_count.fetch_add(1, Ordering::SeqCst);

                // Sleep in short slices so a stop request is honoured quickly.
                let started = Instant::now();
                while started.elapsed() < interval {
                    if thread_stop.load(Ordering::SeqCst) {
                        return;
                    }
                    thread::sleep(SLEEP_SLICE.min(interval));
                }
            }
        })
        .expect("failed to spawn moodle-cron thread");

    CronHandle {
        stop_flag,
        run_count,
        thread: Some(thread),
    }
}

/// Starts the Moodle cron runner in a background thread, executing
/// `php.exe admin/cli/cron.php` silently every 60 seconds (FR-06).
pub fn start_moodle_cron(base_dir: String) -> CronHandle {
    start_moodle_cron_with_interval(base_dir, DEFAULT_CRON_INTERVAL)
}

/// Same as [`start_moodle_cron`] with a custom interval.
pub fn start_moodle_cron_with_interval(base_dir: String, interval: Duration) -> CronHandle {
    let paths = CronPaths::new(base_dir);
    spawn_scheduler(interval, move || {
        let _ = run_cron_once(&paths);
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_cron_paths_layout() {
        let paths = CronPaths::new("./runtime");
        assert_eq!(paths.php_exe, PathBuf::from("./runtime/php/php.exe"));
        assert_eq!(paths.cron_script, PathBuf::from("./runtime/moodle/admin/cli/cron.php"));
        assert_eq!(paths.moodle_dir, PathBuf::from("./runtime/moodle"));
    }

    #[test]
    fn test_build_cron_command_targets_cron_script() {
        let paths = CronPaths::new("./runtime");
        let debug_cmd = format!("{:?}", build_cron_command(&paths));
        assert!(debug_cmd.contains("php.exe"));
        assert!(debug_cmd.contains("cron.php"));
    }

    #[test]
    fn test_run_cron_once_skips_when_runtime_missing() {
        let paths = CronPaths::new("./non_existent_runtime_dir");
        assert!(!paths.is_ready());
        assert!(!run_cron_once(&paths));
    }

    #[test]
    fn test_scheduler_runs_periodically_in_background() {
        let handle = spawn_scheduler(Duration::from_millis(50), || {});
        thread::sleep(Duration::from_millis(700));
        let count = handle.run_count();
        handle.stop();
        assert!(count >= 2, "Scheduler should run multiple passes, got {count}");
    }

    #[test]
    fn test_scheduler_stops_promptly_during_long_interval() {
        let handle = spawn_scheduler(Duration::from_secs(60), || {});
        thread::sleep(Duration::from_millis(100));

        let started = Instant::now();
        handle.stop();
        assert!(
            started.elapsed() < Duration::from_secs(2),
            "Stop must not wait for the full 60s interval"
        );
    }

    #[test]
    fn test_start_moodle_cron_does_not_block_main_thread() {
        let started = Instant::now();
        let handle = start_moodle_cron("./non_existent_runtime_dir".to_string());
        assert!(started.elapsed() < Duration::from_millis(500));
        handle.stop();
    }
}
