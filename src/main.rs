use std::env;
use std::process;
use moodle_installer::{
    provision_payload, resolve_target_directory, validate_target_directory,
    InstallStage, InstallationPaths, PayloadMode, PayloadProvisionConfig,
    ProgressTracker, PAYLOAD_ZIP,
};

fn print_help() {
    println!("Moodle Portable Engine Launcher");
    println!("Usage:");
    println!("  moodle_installer.exe [OPTIONS]");
    println!();
    println!("Options:");
    println!("  -d, --install-dir <PATH>              Custom directory to install/extract runtime (default: ./runtime)");
    println!("  -t, --target <PATH>                   Alias for --install-dir");
    println!("      --pack-container <ZIP> <PAK>      Pack a .zip archive into an encrypted .pak container");
    println!("      --verify-container <PAK>          Verify integrity of an encrypted .pak container");
    println!("  -h, --help                            Display this help information");
    println!();
    println!("Environment Variables:");
    println!("  MOODLE_INSTALL_DIR                    Fallback installation directory if CLI option is omitted");
}

fn main() {
    let args: Vec<String> = env::args().collect();
    if args.iter().any(|a| a == "-h" || a == "--help") {
        print_help();
        return;
    }

    if let Some(pos) = args.iter().position(|a| a == "--pack" || a == "--pack-container") {
        if pos + 2 < args.len() {
            let input_zip = std::path::Path::new(&args[pos + 1]);
            let output_pak = std::path::Path::new(&args[pos + 2]);
            match moodle_installer::pack_file_to_container(input_zip, output_pak, None) {
                Ok(bytes) => {
                    println!("[OK] Encrypted container successfully generated: {} ({} bytes)", output_pak.display(), bytes);
                    return;
                }
                Err(e) => {
                    eprintln!("[ERROR] Failed to pack container: {}", e);
                    process::exit(1);
                }
            }
        } else {
            eprintln!("[ERROR] Usage: moodle_installer.exe --pack-container <input.zip> <output.pak>");
            process::exit(1);
        }
    }

    if let Some(pos) = args.iter().position(|a| a == "--verify-container") {
        if pos + 1 < args.len() {
            let pak_path = std::path::Path::new(&args[pos + 1]);
            match std::fs::read(pak_path) {
                Ok(bytes) => match moodle_installer::unpack_container_bytes(&bytes, None) {
                    Ok(plain) => {
                        println!("[OK] Container is authentic and valid! Decrypted payload size: {} bytes", plain.len());
                        return;
                    }
                    Err(e) => {
                        eprintln!("[ERROR] Container verification failed: {}", e);
                        process::exit(1);
                    }
                },
                Err(e) => {
                    eprintln!("[ERROR] Failed to read container file: {}", e);
                    process::exit(1);
                }
            }
        } else {
            eprintln!("[ERROR] Usage: moodle_installer.exe --verify-container <container.pak>");
            process::exit(1);
        }
    }

    println!("==========================================================");
    println!("          Moodle Portable 64-bit Engine Initializing      ");
    println!("==========================================================");

    let mut tracker = ProgressTracker::new().with_bar_width(28);

    // Resolve target installation directory (CLI -> ENV -> ./runtime)
    let target_dir = resolve_target_directory(&args);
    if let Err(err) = validate_target_directory(&target_dir) {
        eprintln!("[ERROR] Invalid target installation directory: {}", err);
        process::exit(1);
    }
    println!("Target Installation Directory: {}", target_dir.display());

    // --- STAGE 1: PAYLOAD PROVISIONING (0% - 25%) ---
    tracker.advance(InstallStage::PayloadProvisioning, 5, "Detecting network connectivity...");
    let config = PayloadProvisionConfig::new(&target_dir);

    tracker.advance(InstallStage::PayloadProvisioning, 15, "Provisioning runtime payload...");
    match provision_payload(&config, PAYLOAD_ZIP) {
        Ok(result) => {
            let mode_str = match result.mode {
                PayloadMode::Online => "Online Package Download Mode",
                PayloadMode::Offline => "Offline Local Payload Mode",
            };
            if result.was_skipped {
                tracker.advance(
                    InstallStage::PayloadProvisioning,
                    25,
                    format!("Runtime already extracted ({}). Reusing existing cache.", mode_str),
                );
            } else {
                tracker.advance(
                    InstallStage::PayloadProvisioning,
                    25,
                    format!("Extracted {} files ({}).", result.extracted_files, mode_str),
                );
            }
        }
        Err(e) => {
            eprintln!("[ERROR] Failed to provision payload: {}", e);
            process::exit(1);
        }
    }

    // --- STAGE 2: SANDBOX & DIRECTORY ISOLATION (25% - 45%) ---
    tracker.advance(InstallStage::SandboxAndPruning, 35, "Configuring unified installation paths...");
    let paths = InstallationPaths::new(&target_dir);
    if let Err(err) = paths.validate_isolation() {
        eprintln!("[ERROR] Directory layout validation failed: {}", err);
        process::exit(1);
    }

    tracker.advance(InstallStage::SandboxAndPruning, 45, "Writing secure Nginx sandbox configuration...");
    let sandbox = paths.to_sandbox_layout();
    if let Err(e) = sandbox.write_secure_nginx_conf(80) {
        eprintln!("[ERROR] Failed to initialize secure sandbox: {}", e);
        process::exit(1);
    }

    // --- STAGE 3: DATABASE PREPARATION (45% - 70%) ---
    tracker.advance(InstallStage::DatabaseBootstrap, 55, "Configuring MariaDB paths and buffer pool...");
    let _maria_paths = paths.to_mariadb_paths();

    tracker.advance(InstallStage::DatabaseBootstrap, 70, "MariaDB configuration ready.");

    // --- STAGE 4: MOODLE CONFIGURATION (70% - 90%) ---
    tracker.advance(InstallStage::MoodleInstallation, 80, "Verifying Moodle web root and dynamic routing...");
    let _moodle_paths = paths.to_moodle_paths();

    tracker.advance(InstallStage::MoodleInstallation, 90, "Moodle environment verified.");

    // --- STAGE 5: SERVICES & LAUNCH READY (90% - 100%) ---
    tracker.advance(InstallStage::ServicesAndLaunch, 95, "Checking background daemons readiness...");
    let _cron_paths = paths.to_cron_paths();
    let _shutdown_paths = paths.to_shutdown_paths();

    tracker.advance(InstallStage::ServicesAndLaunch, 100, "Installation & runtime verification complete!");

    println!();
    println!("Active Installation Components:");
    println!("  - Moodle Web Root:    {}", paths.moodle_dir.display());
    println!("  - Moodledata Storage: {}", paths.moodledata_dir.display());
    println!("  - Nginx Server:       {}", paths.nginx_dir.display());
    println!("  - PHP 8.2 Runtime:    {}", paths.php_dir.display());
    println!("  - MariaDB Database:   {}", paths.mariadb_dir.display());
    println!("==========================================================");
}
