@echo off
setlocal
title MeetFlow - reset all passwords (local only)
cd /d "%~dp0.."
rem LOCAL DEVELOPMENT ONLY. Sets every user and the platform owner to the password below.
if not exist "config\config.php" (
  echo Run start-local.bat first, then run this again.
  pause & exit /b 1
)
call "%~dp0env.bat" || exit /b 1
"%PHPEXE%" %PHPOPTS% local\reset-passwords.php "Admin@123" > "%~dp0..\storage\logs\reset-passwords.log" 2>&1
type "%~dp0..\storage\logs\reset-passwords.log"
echo.
pause
