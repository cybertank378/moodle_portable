use std::env;
use std::process;
use std::time::Duration;
use aksaventra_installer::{
    build_mariadb_shutdown_command, create_database_with_params, ensure_my_ini,
    install_moodle, patch_moodle_config_for_dynamic_ip, provision_payload,
    resolve_target_directory, run_silent_status, start_mariadb,
    stop_services_gracefully_with_port, validate_target_directory, wait_for_mariadb_ready,
    InstallStage, InstallationPaths, PayloadMode, PayloadProvisionConfig,
    ProgressTracker, PAYLOAD_ZIP,
};

fn print_help() {
    println!("Aksaventra Portable Engine Launcher");
    println!("Usage:");
    println!("  aksaventra_installer.exe [OPTIONS]");
    println!();
    println!("Options:");
    println!("  -d, --install-dir <PATH>              Custom directory to install/extract runtime (default: current directory)");
    println!("  -t, --target <PATH>                   Alias for --install-dir");
    println!("      --pack-container <ZIP> <PAK>      Pack a .zip archive into an encrypted .pak container");
    println!("      --verify-container <PAK>          Verify integrity of an encrypted .pak container");
    println!("  -h, --help                            Display this help information");
    println!();
    println!("Environment Variables:");
    println!("  AKSAVENTRA_INSTALL_DIR                Primary installation directory environment variable");
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
            match aksaventra_installer::pack_file_to_container(input_zip, output_pak, None) {
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
            eprintln!("[ERROR] Usage: aksaventra_installer.exe --pack-container <input.zip> <output.pak>");
            process::exit(1);
        }
    }

    if let Some(pos) = args.iter().position(|a| a == "--verify-container") {
        if pos + 1 < args.len() {
            let pak_path = std::path::Path::new(&args[pos + 1]);
            match std::fs::read(pak_path) {
                Ok(bytes) => match aksaventra_installer::unpack_container_bytes(&bytes, None) {
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
            eprintln!("[ERROR] Usage: aksaventra_installer.exe --verify-container <container.pak>");
            process::exit(1);
        }
    }

    println!("==========================================================");
    println!("          Aksaventra Portable 64-bit Engine Initializing      ");
    println!("==========================================================");

    let mut tracker = ProgressTracker::new().with_bar_width(28);

    // Resolve target installation directory (CLI -> ENV -> current directory)
    let raw_target_dir = resolve_target_directory(&args);
    if let Err(err) = validate_target_directory(&raw_target_dir) {
        eprintln!("[ERROR] Invalid target installation directory: {}", err);
        process::exit(1);
    }
    let target_dir = if raw_target_dir.is_absolute() {
        raw_target_dir
    } else {
        std::env::current_dir().unwrap_or_default().join(&raw_target_dir)
    };
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
    tracker.advance(InstallStage::DatabaseBootstrap, 50, "Configuring MariaDB paths and buffer pool...");
    let maria_paths = paths.to_mariadb_paths();
    if let Err(e) = ensure_my_ini(&maria_paths.base_dir, 3306) {
        eprintln!("[ERROR] Failed to generate MariaDB my.ini: {}", e);
        process::exit(1);
    }

    if !maria_paths.is_data_initialized() {
        tracker.advance(InstallStage::DatabaseBootstrap, 55, "Running MariaDB database installer/initialization...");
        if let Err(e) = maria_paths.run_installer() {
            eprintln!("[ERROR] Failed to run MariaDB installer: {}", e);
            process::exit(1);
        }
        tracker.advance(InstallStage::DatabaseBootstrap, 60, "MariaDB database initialization completed.");
    } else {
        tracker.advance(InstallStage::DatabaseBootstrap, 60, "MariaDB database already initialized.");
    }

    // Start MariaDB temporarily to provision database and install Moodle
    tracker.advance(InstallStage::DatabaseBootstrap, 65, "Booting MariaDB to provision application database...");
    let mut maria_child = match start_mariadb(&maria_paths.base_dir) {
        Ok(child) => child,
        Err(e) => {
            eprintln!("[ERROR] Failed to start MariaDB for database setup: {}", e);
            process::exit(1);
        }
    };

    if !wait_for_mariadb_ready(3306, Duration::from_secs(15)) {
        eprintln!("[ERROR] MariaDB failed to respond on port 3306.");
        let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
        process::exit(1);
    }

    // Provision the 'moodle' database
    if let Err(e) = create_database_with_params(&maria_paths.base_dir, 3306, "moodle") {
        eprintln!("[ERROR] Failed to create database 'moodle': {}", e);
        let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
        process::exit(1);
    }
    tracker.advance(InstallStage::DatabaseBootstrap, 70, "MariaDB database bootstrap completed.");

    // --- STAGE 4: AKSAVENTRA CONFIGURATION (70% - 90%) ---
    let moodle_paths = paths.to_moodle_paths();
    if !moodle_paths.config_php.exists() {
        tracker.advance(InstallStage::MoodleInstallation, 75, "Running automated Aksaventra Core installation (this may take a few minutes)...");
        if let Err(e) = install_moodle(&target_dir) {
            eprintln!("[ERROR] Failed to complete Aksaventra Core installation: {}", e);
            let _ = stop_services_gracefully_with_port(&paths.base_dir, 3306);
            process::exit(1);
        }
        tracker.advance(InstallStage::MoodleInstallation, 85, "Aksaventra Core tables installed.");
        let _ = patch_moodle_config_for_dynamic_ip(&target_dir);
        tracker.advance(InstallStage::MoodleInstallation, 90, "Aksaventra environment verified and dynamic IP patched.");
    } else {
        tracker.advance(InstallStage::MoodleInstallation, 85, "Aksaventra Core already installed.");
        let _ = patch_moodle_config_for_dynamic_ip(&target_dir);
        tracker.advance(InstallStage::MoodleInstallation, 90, "Aksaventra environment verified.");
    }

    // Safely shutdown the temporary MariaDB process now that installation is complete
    let shutdown_paths = paths.to_shutdown_paths();
    let mut shutdown_cmd = build_mariadb_shutdown_command(&shutdown_paths, 3306);
    let _ = run_silent_status(&mut shutdown_cmd);
    let _ = maria_child.wait();

    // --- STAGE 5: SERVICES & LAUNCH READY (90% - 100%) ---
    tracker.advance(InstallStage::ServicesAndLaunch, 95, "Setting up Aksaventra Portable Engine launcher...");

    // Copy aksaventra_engine.exe to target directory if available
    let engine_filename = "aksaventra_engine.exe";
    let target_engine_path = target_dir.join(engine_filename);

    let find_engine = || -> Option<std::path::PathBuf> {
        if let Ok(exe_path) = env::current_exe() {
            if let Some(exe_dir) = exe_path.parent() {
                let candidate = exe_dir.join(engine_filename);
                if candidate.exists() {
                    return Some(candidate);
                }
            }
        }
        let cwd_candidate = std::path::PathBuf::from(engine_filename);
        if cwd_candidate.exists() {
            return Some(cwd_candidate);
        }
        None
    };

    if let Some(src_engine) = find_engine() {
        if src_engine != target_engine_path {
            let _ = std::fs::copy(&src_engine, &target_engine_path);
        }
    }

    tracker.advance(InstallStage::ServicesAndLaunch, 100, "Installation & runtime verification complete!");

    println!();
    println!("Active Installation Components:");
    println!("  - Aksaventra Web Root:     {}", paths.moodle_dir.display());
    println!("  - Aksaventra Data Storage:  {}", paths.moodledata_dir.display());
    println!("  - Nginx Server:            {}", paths.nginx_dir.display());
    println!("  - PHP 8.2 Runtime:         {}", paths.php_dir.display());
    println!("  - MariaDB Database:        {}", paths.mariadb_dir.display());
    if target_engine_path.exists() {
        println!("  - Aksaventra Engine:       {}", target_engine_path.display());
    }
    println!("==========================================================");
    println!(" [SUKSES] Instalasi & Konfigurasi Aksaventra Selesai 100%!");
    println!();
    println!(" Untuk menjalankan server LMS, jalankan berkas:");
    println!("   .\\aksaventra_engine.exe");
    println!(" lalu buka browser di http://localhost");
    println!("==========================================================");
}
