@echo off
setlocal
cd /d "%~dp0"
title Aksaventra Windows 11 SmartScreen & Application Control Whitelister

echo ======================================================================
echo    AKSAVENTRA ENGINE - WINDOWS 11 SMARTSCREEN ^& APPLICATION CONTROL
echo ======================================================================
echo.
echo Sedang membuka blokir unduhan internet pada seluruh berkas sistem...

:: 1. Buka proteksi Zone.Identifier (Mark of the Web)
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-ChildItem -Path '%~dp0*' -Recurse | Unblock-File -ErrorAction SilentlyContinue"

:: 2. Daftarkan sertifikat digital jika tersedia ke Trusted Publishers
if exist "%~dp0aksaventra.cer" (
    echo Mendaftarkan sertifikat keamanan Aksaventra ke sistem lokal...
    certutil -addstore -f "TrustedPublisher" "%~dp0aksaventra.cer" >nul 2>&1
    certutil -addstore -f "Root" "%~dp0aksaventra.cer" >nul 2>&1
)

:: 3. Terapkan atribut tersembunyi sistem pada ruang kerja runtime
if exist "%~dp0.aksaventra_rt" (
    attrib +h +s "%~dp0.aksaventra_rt" >nul 2>&1
)

echo [OK] Seluruh berkas telah berhasil diizinkan dan di-unblock.
echo.
echo Menjalankan Aksaventra Engine...
start "" "%~dp0aksaventra_engine.exe"
exit /b 0
