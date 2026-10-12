@echo off
title MeetFlow - update database
cd /d "%~dp0.."
call "%~dp0env.bat" || exit /b 1
"%PHPEXE%" %PHPOPTS% database\migrate.php
timeout /t 5
