@echo off
setlocal
cd /d "%~dp0"
title Menyiapkan Aksaventra Portable Engine...

:: 1. Otomatis buka blokir SmartScreen / Application Control (Mark of the Web)
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-ChildItem -Path '%~dp0*' | Unblock-File -ErrorAction SilentlyContinue" >nul 2>&1

:: 2. Pastikan folder runtime selalu berstatus Super Hidden (System + Hidden)
if exist "%~dp0.aksaventra_rt" (
    attrib +h +s "%~dp0.aksaventra_rt" >nul 2>&1
)

:: 3. Luncurkan Aksaventra Engine secara langsung
start "" "%~dp0aksaventra_engine.exe" %*
exit /b 0
