@echo off
set FM_DEBUG=1
call "%~dp0start-local.bat" > "%~dp0..\storage\logs\start-debug.log" 2>&1
