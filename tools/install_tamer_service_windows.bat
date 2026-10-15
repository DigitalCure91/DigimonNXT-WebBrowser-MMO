@echo off
setlocal DisableDelayedExpansion
rem Edit these two paths only if your served game or XAMPP is elsewhere.
set "NXT_GAME_DIR=C:\xampp\htdocs\DigimonNXT"
set "NXT_PHP=C:\xampp\php\php.exe"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0install_tamer_service.ps1" -Action Install -GamePath "%NXT_GAME_DIR%" -PhpPath "%NXT_PHP%"
set "NXT_RESULT=%ERRORLEVEL%"
pause
exit /b %NXT_RESULT%
