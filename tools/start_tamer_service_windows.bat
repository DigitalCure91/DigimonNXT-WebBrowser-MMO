@echo off
setlocal DisableDelayedExpansion
rem Match these paths to the served folder and PHP used during installation.
set "NXT_GAME_DIR=C:\xampp\htdocs\DigimonNXT"
set "NXT_PHP=C:\xampp\php\php.exe"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0install_tamer_service.ps1" -Action Start -GamePath "%NXT_GAME_DIR%" -PhpPath "%NXT_PHP%"
set "NXT_RESULT=%ERRORLEVEL%"
pause
exit /b %NXT_RESULT%
