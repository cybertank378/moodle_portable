@echo off
setlocal
cd /d "%~dp0"
title AKSAVENTRA PORTABLE ENGINE

echo ======================================================
echo       AKSAVENTRA PORTABLE ENGINE v2.2.4
echo       Powered by Rust ^| aksaventra.ai.id
echo ======================================================
echo Membuka proteksi Windows SmartScreen / SAC...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-ChildItem -Path '%~dp0*' -Recurse | Unblock-File" >nul 2>&1
echo Berkas siap dijalankan!
echo.
"%~dp0aksaventra_engine.exe" %*
pause
