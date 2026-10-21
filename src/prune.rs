use std::fs;
use std::io;
use std::path::Path;

/// Relative paths of non-essential Moodle directories and files targeted for pruning (NFR-03).
pub const PRUNE_TARGETS: &[&str] = &[
    // Unneeded activity modules
    "mod/forum",
    "mod/chat",
    "mod/wiki",
    "mod/glossary",
    "mod/workshop",
    "mod/survey",
    "mod/scorm",
    // Development and testing assets
    ".git",
    ".github",
    "node_modules",
    "phpunit.xml.dist",
    "Gruntfile.js",
    "package.json",
    "package-lock.json",
    ".stylelintrc",
    ".eslintrc",
    ".travis.yml",
    // Documentation
    "README.txt",
    "TRADEMARK.txt",
    "COPYING.txt",
];

/// Results of a Moodle pruning operation.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct PruneReport {
    pub removed_items: Vec<String>,
    pub skipped_items: Vec<String>,
    pub bytes_freed: u64,
}

/// Recursively calculates directory size in bytes.
pub fn calculate_dir_size<P: AsRef<Path>>(path: P) -> io::Result<u64> {
    let mut total = 0;
    let path = path.as_ref();
    if path.is_file() {
        return Ok(fs::metadata(path)?.len());
    }
    if path.is_dir() {
        for entry in fs::read_dir(path)? {
            let entry = entry?;
            let meta = entry.metadata()?;
            if meta.is_dir() {
                total += calculate_dir_size(entry.path())?;
            } else {
                total += meta.len();
            }
        }
    }
    Ok(total)
}

/// Recursively removes test and development directories (`tests`, `behat`, `phpunit`)
/// across the entire Moodle tree.
pub fn remove_test_directories<P: AsRef<Path>>(moodle_root: P) -> io::Result<u64> {
    let mut bytes_freed = 0;
    let moodle_root = moodle_root.as_ref();
    if !moodle_root.exists() {
        return Ok(0);
    }

    fn walk_and_prune(dir: &Path, freed: &mut u64) -> io::Result<()> {
        if dir.is_dir() {
            let file_name = dir.file_name().and_then(|n| n.to_str()).unwrap_or("");
            if file_name == "tests" || file_name == "behat" || file_name == "phpunit" {
                *freed += calculate_dir_size(dir).unwrap_or(0);
                let _ = fs::remove_dir_all(dir);
                return Ok(());
            }

            for entry in fs::read_dir(dir)? {
                let entry = entry?;
                let path = entry.path();
                if path.is_dir() {
                    let _ = walk_and_prune(&path, freed);
                }
            }
        }
        Ok(())
    }

    let _ = walk_and_prune(moodle_root, &mut bytes_freed);
    Ok(bytes_freed)
}

/// Prunes non-essential components from the Moodle installation directory (NFR-03).
pub fn prune_moodle<P: AsRef<Path>>(moodle_root: P) -> io::Result<PruneReport> {
    let root = moodle_root.as_ref();
    let mut report = PruneReport::default();

    if !root.exists() {
        return Ok(report);
    }

    // 1. Remove explicit targets
    for target in PRUNE_TARGETS {
        let target_path = root.join(target);
        if target_path.exists() {
            let size = calculate_dir_size(&target_path).unwrap_or(0);
            let res = if target_path.is_dir() {
                fs::remove_dir_all(&target_path)
            } else {
                fs::remove_file(&target_path)
            };

            if res.is_ok() {
                report.bytes_freed += size;
                report.removed_items.push(target.to_string());
            } else {
                report.skipped_items.push(target.to_string());
            }
        }
    }

    // 2. Remove recursive test and dev directories
    report.bytes_freed += remove_test_directories(root)?;

    Ok(report)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_prune_moodle_removes_unneeded_and_preserves_core() {
        let temp_dir = std::env::temp_dir().join(format!("moodle_prune_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        let moodle_dir = temp_dir.join("moodle");

        // Setup mock moodle structure
        fs::create_dir_all(moodle_dir.join("mod").join("forum")).unwrap();
        fs::write(moodle_dir.join("mod").join("forum").join("view.php"), b"forum code").unwrap();

        fs::create_dir_all(moodle_dir.join("mod").join("quiz")).unwrap(); // Essential module!
        fs::write(moodle_dir.join("mod").join("quiz").join("view.php"), b"quiz code").unwrap();

        fs::create_dir_all(moodle_dir.join("node_modules")).unwrap();
        fs::write(moodle_dir.join("node_modules").join("dummy.js"), b"node code").unwrap();

        fs::create_dir_all(moodle_dir.join("course").join("tests")).unwrap();
        fs::write(moodle_dir.join("course").join("tests").join("test.php"), b"test code").unwrap();

        fs::write(moodle_dir.join("README.txt"), b"documentation").unwrap();

        // Run pruning
        let report = prune_moodle(&moodle_dir).expect("Pruning should succeed");

        // Verify unneeded items were removed
        assert!(!moodle_dir.join("mod").join("forum").exists());
        assert!(!moodle_dir.join("node_modules").exists());
        assert!(!moodle_dir.join("course").join("tests").exists());
        assert!(!moodle_dir.join("README.txt").exists());

        // Verify essential modules are preserved!
        assert!(moodle_dir.join("mod").join("quiz").exists());
        assert!(moodle_dir.join("mod").join("quiz").join("view.php").exists());

        assert!(!report.removed_items.is_empty());
        assert!(report.bytes_freed > 0);

        // Cleanup
        let _ = fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_calculate_dir_size_returns_correct_bytes() {
        let temp_dir = std::env::temp_dir().join(format!("moodle_size_test_{}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
        fs::create_dir_all(&temp_dir).unwrap();

        fs::write(temp_dir.join("file1.txt"), b"12345").unwrap(); // 5 bytes
        fs::write(temp_dir.join("file2.txt"), b"1234567890").unwrap(); // 10 bytes

        let size = calculate_dir_size(&temp_dir).unwrap();
        assert_eq!(size, 15);

        // Cleanup
        let _ = fs::remove_dir_all(&temp_dir);
    }
}
