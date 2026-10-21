use std::path::Path;
use moodle_installer::{
    provision_payload, InstallationPaths, PayloadMode, PayloadProvisionConfig, PAYLOAD_ZIP,
};

fn main() {
    println!("=== Moodle Portable Engine Starting ===");

    let runtime_dir = Path::new("./runtime");
    println!("Checking runtime environment at: {}", runtime_dir.display());

    let config = PayloadProvisionConfig::new(runtime_dir);
    println!("Detecting network environment (Online vs Offline)...");

    match provision_payload(&config, PAYLOAD_ZIP) {
        Ok(result) => {
            match result.mode {
                PayloadMode::Online => {
                    println!("[ONLINE MODE] Internet connection verified. Package provisioning active.");
                }
                PayloadMode::Offline => {
                    println!("[OFFLINE MODE] No internet detected. Using offline payload provisioning.");
                }
            }

            if result.was_skipped {
                println!("[SKIP] Runtime directory already present. Using cached components ({}ms).", result.duration_ms);
            } else {
                println!(
                    "[OK] Extracted {} files in {}ms.",
                    result.extracted_files, result.duration_ms
                );
            }

            println!(
                "[OK] Placeholder verified at: {}",
                result.placeholder_path.display()
            );

            if let Some(prune) = result.prune_report {
                println!(
                    "[OK] Pruning completed: removed {} items, freed {} bytes.",
                    prune.removed_items.len(),
                    prune.bytes_freed
                );
            }
        }
        Err(e) => {
            eprintln!("[ERROR] Failed to provision payload: {}", e);
            std::process::exit(1);
        }
    }

    // Initialize & verify sandbox isolation via unified installation paths (NFR-04, Issue #24)
    let paths = InstallationPaths::new(runtime_dir);
    println!("Component Installation Paths:");
    println!("  - Moodle Web Root:   {}", paths.moodle_dir.display());
    println!("  - Moodledata Storage: {}", paths.moodledata_dir.display());
    println!("  - Nginx Server:      {}", paths.nginx_dir.display());
    println!("  - PHP Runtime:       {}", paths.php_dir.display());
    println!("  - MariaDB Database:  {}", paths.mariadb_dir.display());

    if let Err(err) = paths.validate_isolation() {
        eprintln!("[ERROR] Directory layout validation failed: {}", err);
        std::process::exit(1);
    }

    let sandbox = paths.to_sandbox_layout();
    if let Err(e) = sandbox.write_secure_nginx_conf(80) {
        eprintln!("[ERROR] Failed to initialize secure sandbox: {}", e);
        std::process::exit(1);
    }
    println!("[OK] Runtime sandbox & directory isolation verified (moodledata isolated outside web root).");

    println!("Runtime verification completed.");
}
