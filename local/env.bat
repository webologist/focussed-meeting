@echo off
rem Finds PHP and sets PHPEXE and PHPOPTS for the other scripts in this folder.
set "LS=%APPDATA%\Local\lightning-services"
rem ---- Find a PHP 8 binary: LocalWP's bundled PHP, XAMPP, or PHP on PATH ----
set "PHPEXE="
for /d %%D in ("%LS%\php-8*") do (
  if exist "%%D\bin\win64\php.exe" set "PHPEXE=%%D\bin\win64\php.exe"
)
if not defined PHPEXE if exist "C:\xampp\php\php.exe" set "PHPEXE=C:\xampp\php\php.exe"
if not defined PHPEXE for %%P in (php.exe) do if not "%%~$PATH:P"=="" set "PHPEXE=%%~$PATH:P"
if not defined PHPEXE (
  echo Could not find PHP 8 on this computer.
  pause & exit /b 1
)
for %%F in ("%PHPEXE%") do set "PHPDIR=%%~dpF"
echo Using PHP: %PHPEXE%

set PHPOPTS=-n -d extension_dir="%PHPDIR%ext" -d extension=pdo_mysql -d extension=openssl -d extension=curl -d extension=mbstring -d extension=fileinfo -d upload_max_filesize=32M -d post_max_size=32M -d memory_limit=256M

exit /b 0
