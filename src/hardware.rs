use std::thread;

#[derive(Debug, Clone, PartialEq)]
pub struct HardwareSpecs {
    pub cpu_cores: usize,
    pub total_ram_bytes: u64,
    pub total_ram_gb: f64,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct HardwareProfile {
    pub name: String,
    pub workers: u32,
    pub buffer_pool: String,
    pub max_connections: u32,
    pub nginx_connections: u32,
    pub estimated_cbt_users: String,
}

#[cfg(target_os = "windows")]
#[repr(C)]
struct MEMORYSTATUSEX {
    dw_length: u32,
    dw_memory_load: u32,
    ull_total_phys: u64,
    ull_avail_phys: u64,
    ull_total_page_file: u64,
    ull_avail_page_file: u64,
    ull_total_virtual: u64,
    ull_avail_virtual: u64,
    ull_avail_extended_virtual: u64,
}

#[cfg(target_os = "windows")]
extern "system" {
    fn GlobalMemoryStatusEx(lp_buffer: *mut MEMORYSTATUSEX) -> i32;
}

pub fn get_total_ram_bytes() -> u64 {
    #[cfg(target_os = "windows")]
    unsafe {
        let mut status = MEMORYSTATUSEX {
            dw_length: std::mem::size_of::<MEMORYSTATUSEX>() as u32,
            dw_memory_load: 0,
            ull_total_phys: 0,
            ull_avail_phys: 0,
            ull_total_page_file: 0,
            ull_avail_page_file: 0,
            ull_total_virtual: 0,
            ull_avail_virtual: 0,
            ull_avail_extended_virtual: 0,
        };
        if GlobalMemoryStatusEx(&mut status) != 0 {
            return status.ull_total_phys;
        }
    }
    // Fallback: anggap 8 GB jika gagal deteksi
    8 * 1024 * 1024 * 1024
}

/// Mendeteksi spesifikasi perangkat keras komputer host yang aktif
pub fn detect_hardware() -> HardwareSpecs {
    let cpu_cores = thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(4);
    let total_ram_bytes = get_total_ram_bytes();
    let total_ram_gb = total_ram_bytes as f64 / (1024.0 * 1024.0 * 1024.0);

    HardwareSpecs {
        cpu_cores,
        total_ram_bytes,
        total_ram_gb,
    }
}

/// Memberikan rekomendasi profil performa terbaik berdasarkan RAM dan Core CPU host
pub fn recommend_profile(specs: &HardwareSpecs) -> HardwareProfile {
    let ram_gb = specs.total_ram_gb;
    let cores = specs.cpu_cores;

    if ram_gb >= 28.0 && cores >= 12 {
        // Profil Extreme / Dedicated High-End Server (>=32GB RAM, >=12 Cores)
        HardwareProfile {
            name: "Extreme (Dedicated Exam Server)".to_string(),
            workers: 64,
            buffer_pool: "2048M".to_string(),
            max_connections: 1000,
            nginx_connections: 8192,
            estimated_cbt_users: "300 - 500+ Siswa Ujian Serentak".to_string(),
        }
    } else if ram_gb >= 14.0 && cores >= 6 {
        // Profil High Performance (>=16GB RAM, >=6 Cores)
        HardwareProfile {
            name: "High Performance (Optimal)".to_string(),
            workers: 32,
            buffer_pool: "1024M".to_string(),
            max_connections: 500,
            nginx_connections: 4096,
            estimated_cbt_users: "150 - 250 Siswa Ujian Serentak".to_string(),
        }
    } else if ram_gb >= 6.0 && cores >= 4 {
        // Profil Balanced / Standard Laptop (>=8GB RAM, >=4 Cores)
        HardwareProfile {
            name: "Balanced (Standar Laptop)".to_string(),
            workers: 16,
            buffer_pool: "256M".to_string(),
            max_connections: 250,
            nginx_connections: 2048,
            estimated_cbt_users: "50 - 100 Siswa Ujian Serentak".to_string(),
        }
    } else {
        // Profil Eco / Low Resource (<=4GB RAM atau Dual-Core)
        HardwareProfile {
            name: "Eco (Hemat Daya / Spesifikasi Rendah)".to_string(),
            workers: 8,
            buffer_pool: "128M".to_string(),
            max_connections: 150,
            nginx_connections: 1024,
            estimated_cbt_users: "25 - 50 Siswa Ujian Serentak".to_string(),
        }
    }
}

/// Mengambil profil performa berdasarkan nama preset (--profile eco|normal|high|extreme)
pub fn profile_from_name(name: &str) -> Option<HardwareProfile> {
    match name.to_lowercase().as_str() {
        "eco" | "hemat" | "low" => Some(HardwareProfile {
            name: "Eco (Hemat Daya / Spesifikasi Rendah)".to_string(),
            workers: 8,
            buffer_pool: "128M".to_string(),
            max_connections: 150,
            nginx_connections: 1024,
            estimated_cbt_users: "25 - 50 Siswa Ujian Serentak".to_string(),
        }),
        "normal" | "balanced" | "standar" => Some(HardwareProfile {
            name: "Balanced (Standar Laptop)".to_string(),
            workers: 16,
            buffer_pool: "256M".to_string(),
            max_connections: 250,
            nginx_connections: 2048,
            estimated_cbt_users: "50 - 100 Siswa Ujian Serentak".to_string(),
        }),
        "high" | "performance" | "tinggi" => Some(HardwareProfile {
            name: "High Performance (Optimal)".to_string(),
            workers: 32,
            buffer_pool: "1024M".to_string(),
            max_connections: 500,
            nginx_connections: 4096,
            estimated_cbt_users: "150 - 250 Siswa Ujian Serentak".to_string(),
        }),
        "extreme" | "exam" | "maksimal" => Some(HardwareProfile {
            name: "Extreme (Dedicated Exam Server)".to_string(),
            workers: 64,
            buffer_pool: "2048M".to_string(),
            max_connections: 1000,
            nginx_connections: 8192,
            estimated_cbt_users: "300 - 500+ Siswa Ujian Serentak".to_string(),
        }),
        _ => None,
    }
}

/// Menghasilkan profil khusus berdasarkan jumlah worker eksplisit (-c / --workers <N>)
pub fn custom_profile(workers: u32) -> HardwareProfile {
    let workers = workers.clamp(4, 256);
    let max_connections = std::cmp::max(250, workers * 8);
    let buffer_pool = if workers <= 8 {
        "128M"
    } else if workers <= 16 {
        "256M"
    } else if workers <= 32 {
        "512M"
    } else if workers <= 64 {
        "1024M"
    } else {
        "2048M"
    };
    let nginx_connections = std::cmp::max(2048, workers * 128);
    let estimated = format!("~{} Siswa Ujian Serentak", workers * 5);

    HardwareProfile {
        name: format!("Custom ({} Workers)", workers),
        workers,
        buffer_pool: buffer_pool.to_string(),
        max_connections,
        nginx_connections,
        estimated_cbt_users: estimated,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_detect_hardware_returns_positive_values() {
        let specs = detect_hardware();
        assert!(specs.cpu_cores > 0);
        assert!(specs.total_ram_bytes > 0);
        assert!(specs.total_ram_gb > 0.0);
    }

    #[test]
    fn test_recommend_profile_tiers() {
        // High-end server (32GB, 16 cores)
        let high_server = HardwareSpecs {
            cpu_cores: 16,
            total_ram_bytes: 32 * 1024 * 1024 * 1024,
            total_ram_gb: 32.0,
        };
        let p_extreme = recommend_profile(&high_server);
        assert_eq!(p_extreme.workers, 64);
        assert_eq!(p_extreme.buffer_pool, "2048M");

        // 16GB laptop (8 cores)
        let mid_server = HardwareSpecs {
            cpu_cores: 8,
            total_ram_bytes: 16 * 1024 * 1024 * 1024,
            total_ram_gb: 16.0,
        };
        let p_high = recommend_profile(&mid_server);
        assert_eq!(p_high.workers, 32);
        assert_eq!(p_high.buffer_pool, "1024M");

        // 8GB standard laptop (4 cores)
        let standard_laptop = HardwareSpecs {
            cpu_cores: 4,
            total_ram_bytes: 8 * 1024 * 1024 * 1024,
            total_ram_gb: 8.0,
        };
        let p_balanced = recommend_profile(&standard_laptop);
        assert_eq!(p_balanced.workers, 16);
        assert_eq!(p_balanced.buffer_pool, "256M");

        // 4GB low laptop (2 cores)
        let low_laptop = HardwareSpecs {
            cpu_cores: 2,
            total_ram_bytes: 4 * 1024 * 1024 * 1024,
            total_ram_gb: 4.0,
        };
        let p_eco = recommend_profile(&low_laptop);
        assert_eq!(p_eco.workers, 8);
        assert_eq!(p_eco.buffer_pool, "128M");
    }

    #[test]
    fn test_profile_from_name_preset() {
        assert_eq!(profile_from_name("eco").unwrap().workers, 8);
        assert_eq!(profile_from_name("high").unwrap().workers, 32);
        assert_eq!(profile_from_name("extreme").unwrap().workers, 64);
        assert!(profile_from_name("invalid_name").is_none());
    }

    #[test]
    fn test_custom_profile_scaling() {
        let custom = custom_profile(48);
        assert_eq!(custom.workers, 48);
        assert_eq!(custom.buffer_pool, "1024M");
        assert_eq!(custom.max_connections, 48 * 8);
    }
}
