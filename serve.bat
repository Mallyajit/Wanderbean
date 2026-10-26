@echo off
rem KOBU local server. Enables PHP's curl/openssl extensions for this run only
rem when php.ini leaves them off, so live APIs work without editing php.ini.
setlocal
set "EXT="
php -m | findstr /i /x "curl" >nul || set "EXT=%EXT% -d extension=curl"
php -m | findstr /i /x "openssl" >nul || set "EXT=%EXT% -d extension=openssl"
php -m | findstr /i /x "mbstring" >nul || set "EXT=%EXT% -d extension=mbstring"
set "PORT=%~1"
if "%PORT%"=="" set "PORT=8000"
echo KOBU running at http://localhost:%PORT%  (Ctrl+C to stop)
php %EXT% -S localhost:%PORT% -t "%~dp0."
