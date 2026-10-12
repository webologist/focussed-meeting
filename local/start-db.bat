@echo off
setlocal EnableDelayedExpansion
title MeetFlow - start database
cd /d "%~dp0.."
rem Starts only the MeetFlow database (use this if the app shows "No connection could be made").
set "LS=%APPDATA%\Local\lightning-services"
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

echo.
netstat -ano | findstr /r /c:":%DBPORT% .*LISTENING" >nul && (echo The database is already running.) || (echo The database is starting. Refresh the page in your browser in a few seconds.)
timeout /t 6
