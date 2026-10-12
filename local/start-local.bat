@echo off
if defined FM_DEBUG echo on
setlocal EnableDelayedExpansion
title MeetFlow (local)
cd /d "%~dp0.."
set "LS=%APPDATA%\Local\lightning-services"

call "%~dp0env.bat" || exit /b 1

rem ---- Own MySQL server for this app (no LocalWP needed): port 3310, data in storage\mysql ----
set "DBPORT=3310"
set "DATADIR=%CD%\storage\mysql"
set "MYSQLD="
for /d %%D in ("%LS%\mysql-*" "%LS%\mariadb-*") do (
  if not defined MYSQLD for /f "delims=" %%F in ('dir /b /s "%%D\mysqld.exe" 2^>nul') do if not defined MYSQLD set "MYSQLD=%%F"
)
if not defined MYSQLD if exist "C:\xampp\mysql\bin\mysqld.exe" set "MYSQLD=C:\xampp\mysql\bin\mysqld.exe"
if not defined MYSQLD (
  echo Could not find MySQL on this computer.
  pause & exit /b 1
)
set "ISMARIA=0"
echo "%MYSQLD%" | findstr /i "maria xampp" >nul && set "ISMARIA=1"
set "EXTRA=--mysqlx=OFF"
if "%ISMARIA%"=="1" set "EXTRA="

netstat -ano | findstr /r /c:":%DBPORT% .*LISTENING" >nul
if errorlevel 1 (
  if not exist "%DATADIR%\mysql" (
    echo.
    echo Preparing the database folder. One time only, takes about 20 seconds...
    if not exist "%DATADIR%" mkdir "%DATADIR%"
    if "%ISMARIA%"=="1" (
      for %%F in ("%MYSQLD%") do "%%~dpFmariadb-install-db.exe" --datadir="%DATADIR%"
    ) else (
      "%MYSQLD%" --initialize-insecure --datadir="%DATADIR%"
    )
  )
  echo Starting the database...
  start "MeetFlow database - keep open" /min "%MYSQLD%" --datadir="%DATADIR%" --port=%DBPORT% --bind-address=127.0.0.1 %EXTRA% --console
)

rem ---- First run: create database and config ----
if not exist "config\config.php" (
  echo.
  echo First-time setup...
  set "FM_DB_PORT=%DBPORT%"
  "%PHPEXE%" %PHPOPTS% local\setup-local.php || (pause & exit /b 1)
)

rem ---- Bring the database up to date (safe every time) ----
"%PHPEXE%" %PHPOPTS% database\migrate.php

rem ---- Background jobs every 5 minutes, in a separate minimised window ----
start "MeetFlow jobs" /min "%~dp0jobs.bat"

echo.
echo MeetFlow is running at http://localhost:8080
echo Keep this window open. Close it to stop the app.
start "" http://localhost:8080
"%PHPEXE%" %PHPOPTS% -S localhost:8080 -t public server.php
