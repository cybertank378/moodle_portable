fn main() {
    #[cfg(windows)]
    {
        let mut res = winres::WindowsResource::new();
        if std::path::Path::new("app_icon.ico").exists() {
            res.set_icon("app_icon.ico");
        }
        res.set("ProductName", "Moodle Portable");
        res.set("FileDescription", "Moodle Portable Plug-and-Play Server Launcher");
        res.set("LegalCopyright", "Copyright (C) 2026 Moodle Portable");
        if let Err(e) = res.compile() {
            eprintln!("cargo:warning=Failed to compile Windows resources: {}", e);
        }
    }
}
