# Moodle Portable Technical Skill & Competency Requirements

## Core Engineering
- **Rust Systems Programming**: 
  - Subprocess Management (`std::process::Command`, Win32 `creation_flags`).
  - Threading & Concurrency (`std::thread`, Background Loops).
  - File I/O & Embedded Assets (`include_bytes!`, `zip-rs`).
  - Network Socket Inspection (`std::net::UdpSocket`).
- **Windows OS Internals**:
  - Windows Subsystem API (`windows_subsystem = "windows"`).
  - Process Lifecycle & Task Termination.

## Web Stack & Systems Tuning
- **PHP Systems Architecture**:
  - FastCGI / PHP-CGI execution model.
  - OPcache configuration & shared memory tuning.
  - Moodle CLI Automation & Core Internals.
- **Nginx Administration**:
  - FastCGI Pass directive & Slasharguments routing.
  - Static asset caching & performance optimization.
- **MariaDB Administration**:
  - Portable MariaDB initialization & silent SQL execution via CLI.
  - Memory footprint reduction for low-end host systems.