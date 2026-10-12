@echo off
setlocal
title MeetFlow - remove demo data
cd /d "%~dp0.."
rem Deletes the two demo companies and everything in them. Your own companies are not touched.

if not exist "config\config.php" (
  echo Run start-local.bat first, then run this again.
  pause & exit /b 1
)
call "%~dp0env.bat" || exit /b 1
"%PHPEXE%" %PHPOPTS% database\seed.php --remove || (echo. & echo Start the app with start-local.bat first, then run this again. & pause & exit /b 1)
echo.
pause
