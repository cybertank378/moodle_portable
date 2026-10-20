use std::path::Path;
use moodle_installer::{extract_payload, ExtractionResult};

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

    println!("Runtime verification completed.");
}
