@echo off
setlocal
title MeetFlow - load demo data
cd /d "%~dp0.."
rem Loads (or reloads) the two demo companies. Your own companies are not touched.

if not exist "config\config.php" (
  echo Run start-local.bat first, then run this again.
  pause & exit /b 1
)
call "%~dp0env.bat" || exit /b 1
"%PHPEXE%" %PHPOPTS% database\migrate.php || (echo. & echo Start the app with start-local.bat first, then run this again. & pause & exit /b 1)
"%PHPEXE%" %PHPOPTS% database\seed.php --fresh
echo.
pause
