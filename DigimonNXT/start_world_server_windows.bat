@echo off
setlocal DisableDelayedExpansion
rem The SERVED folder: it must contain index.php and config\app.php.
set "NXT_GAME_DIR=C:\xampp\htdocs\DigimonNXT"
set "NXT_PHP=C:\xampp\php\php.exe"
set "NXT_APACHE_LOG=C:\xampp\apache\logs\access.log"
rem Only this game's traffic is shown. Use / for a dedicated game virtual host.
set "NXT_URL_PATH=/DigimonNXT/"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0world_server.ps1" -GamePath "%NXT_GAME_DIR%" -PhpPath "%NXT_PHP%" -ApacheAccessLog "%NXT_APACHE_LOG%" -ApacheUrlPath "%NXT_URL_PATH%"
set "NXT_RESULT=%ERRORLEVEL%"
if not "%NXT_RESULT%"=="0" pause
exit /b %NXT_RESULT%
