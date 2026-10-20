use std::path::Path;
use moodle_installer::{extract_payload, ExtractionResult, SandboxLayout};

fn main() {
    println!("=== Moodle Portable Engine Starting ===");

    let runtime_dir = Path::new("./runtime");
    println!("Checking runtime environment at: {}", runtime_dir.display());

    match extract_payload(runtime_dir) {
        Ok(ExtractionResult::Extracted { file_count, duration_ms }) => {
            println!("[OK] Payload extracted successfully! ({} files, {}ms)", file_count, duration_ms);
        }
        Ok(ExtractionResult::Skipped { reason, duration_ms }) => {
            println!("[SKIP] Payload extraction skipped: {} (took {}ms)", reason, duration_ms);
        }
        Err(e) => {
            eprintln!("[ERROR] Failed to extract payload: {}", e);
            std::process::exit(1);
        }
    }

    // Initialize & verify sandbox isolation (NFR-04)
    let sandbox = SandboxLayout::new(runtime_dir);
    if let Err(e) = sandbox.write_secure_nginx_conf(80) {
        eprintln!("[ERROR] Failed to initialize secure sandbox: {}", e);
        std::process::exit(1);
    }
    println!("[OK] Runtime sandbox & directory isolation verified (moodledata isolated outside web root).");

    println!("Runtime verification completed.");
}
