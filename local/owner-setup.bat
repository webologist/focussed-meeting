@echo off
setlocal
title MeetFlow - platform owner setup
cd /d "%~dp0.."
rem Creates the platform owner (super admin) and opens a one-time page to set the password.
set "OWNER_EMAIL=sunil@verticalinfinity.in"

if not exist "config\config.php" (
  echo Run start-local.bat first, then run this again.
  pause & exit /b 1
)
call "%~dp0env.bat" || exit /b 1
"%PHPEXE%" %PHPOPTS% database\migrate.php || (echo. & echo Start the app with start-local.bat first, then run this again. & pause & exit /b 1)
"%PHPEXE%" %PHPOPTS% database\owner.php %OWNER_EMAIL% --open
echo.
echo A page to set your owner password has opened in your browser.
pause
