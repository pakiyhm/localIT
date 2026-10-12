@echo off
chcp 65001 >nul
title IT Asset Management System
:: ============================================
::  IT Asset Management System — Quick Start
:: ============================================

set PORT=8030
set PHP_BIN=php

:: 1. Check if php is in C:\php\php.exe
if exist "C:\php\php.exe" (
    set PHP_BIN=C:\php\php.exe
    goto :start_server
)

:: 2. Check if php is in System PATH
where php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    set PHP_BIN=php
    goto :start_server
)

:: 3. Check XAMPP
if exist "C:\xampp\php\php.exe" (
    set PHP_BIN=C:\xampp\php\php.exe
    goto :start_server
)

:: If PHP not found anywhere
echo.
echo [ERROR] PHP not found in C:\php\php.exe or PATH!
echo.
pause
exit /b 1

:start_server
echo.
echo ========================================================
echo   IT Asset Management System
echo   Starting server on port %PORT%...
echo ========================================================
echo.
echo [OK] Using PHP: %PHP_BIN%
echo [OK] URL: http://localhost:%PORT%
echo.
echo --------------------------------------------------------
echo   Open your browser: http://localhost:%PORT%
echo   Press Ctrl+C to stop the server
echo --------------------------------------------------------
echo.

:: Auto-open browser
start "" "http://localhost:%PORT%"

:: Start PHP built-in server
"%PHP_BIN%" -S 0.0.0.0:%PORT% -t "%~dp0"

pause
