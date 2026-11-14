@echo off
setlocal
cd /d "%~dp0"
title Aksaventra Windows 11 SmartScreen Whitelister

echo ======================================================================
echo    AKSAVENTRA ENGINE - WINDOWS 11 SMARTSCREEN ^& APPLICATION CONTROL
echo ======================================================================
echo.
echo Sedang membuka blokir unduhan internet pada seluruh berkas sistem...

:: Buka proteksi Zone.Identifier
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-ChildItem -Path '%~dp0*' -Recurse | Unblock-File -ErrorAction SilentlyContinue"

:: Terapkan atribut tersembunyi sistem pada ruang kerja runtime
if exist "%~dp0.aksaventra_rt" (
    attrib +h +s "%~dp0.aksaventra_rt" >nul 2>&1
)

echo [OK] Seluruh berkas telah berhasil diizinkan dan di-unblock.
echo.
echo Menjalankan Aksaventra Engine...
start "" "%~dp0aksaventra_engine.exe"
exit /b 0
