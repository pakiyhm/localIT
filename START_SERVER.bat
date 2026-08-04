@echo off
chcp 65001 >nul
title IT Asset Management System
:: ============================================
::  IT Asset Management System — Quick Start
:: ============================================
:: Double-click this file to start the system
:: ============================================

set PORT=8030

echo.
echo ========================================================
echo   IT Asset Management System
echo   Starting server on port %PORT%...
echo ========================================================
echo.

:: Check PHP
where php >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] PHP not found!
    echo.
    echo How to install PHP on Windows:
    echo    1. Download PHP from https://windows.php.net/download
    echo       Choose "VS16 x64 Thread Safe" ZIP
    echo    2. Extract to C:\php
    echo    3. Copy php.ini-production to php.ini
    echo    4. In php.ini, enable: extension=pdo_sqlite and extension=sqlite3
    echo    5. Add C:\php to System PATH
    echo    6. Open new CMD and verify: php -v
    echo.
    echo    Or use Chocolatey:  choco install php
    echo    Or use Scoop:       scoop install php
    echo.
    pause
    exit /b 1
)

for /f "tokens=*" %%i in ('php -v 2^>^&1') do (
    set PHP_VER=%%i
    goto :found
)
:found
echo [OK] %PHP_VER%
echo [OK] Project: %~dp0
echo [OK] URL: http://localhost:%PORT%
echo.
echo --------------------------------------------------------
echo   Open your browser: http://localhost:%PORT%
echo   Press Ctrl+C to stop the server
echo --------------------------------------------------------
echo.

:: Auto-open browser
start "" "http://localhost:%PORT%"

:: Start PHP built-in server (0.0.0.0 = accessible from LAN)
php -S 0.0.0.0:%PORT% -t "%~dp0"

pause
