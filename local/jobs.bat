@echo off
title MeetFlow background jobs
cd /d "%~dp0.."
:loop
"%PHPEXE%" %PHPOPTS% cron\run.php
timeout /t 300 /nobreak >nul
goto loop
