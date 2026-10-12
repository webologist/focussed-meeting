@echo off
title MeetFlow - push to GitHub (deploys to Hostinger)
setlocal
set BUNDLE=%~dp0..\storage\cache\fm.bundle
set WORK=%TEMP%\fm-push
where git >nul 2>nul || (echo Git is not installed or not on PATH. & pause & exit /b 1)
if exist "%WORK%" rmdir /s /q "%WORK%"
git clone https://github.com/webologist/focussed-meeting "%WORK%" || (echo Clone failed. & pause & exit /b 1)
cd /d "%WORK%"
git pull --ff-only "%BUNDLE%" main || (echo Could not apply the changes - GitHub main has moved on. Tell Claude. & pause & exit /b 1)
git log --oneline -2
git push origin main || (echo Push failed - sign in to GitHub when asked, then run this again. & pause & exit /b 1)
echo.
echo Pushed. Hostinger will deploy it automatically in a minute or two.
pause
