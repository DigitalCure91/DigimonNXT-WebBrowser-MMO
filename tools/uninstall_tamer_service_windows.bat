@echo off
setlocal DisableDelayedExpansion
rem Match NXT_GAME_DIR to the served folder used when installing the task.
set "NXT_GAME_DIR=C:\xampp\htdocs\DigimonNXT"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0install_tamer_service.ps1" -Action Uninstall -GamePath "%NXT_GAME_DIR%"
set "NXT_RESULT=%ERRORLEVEL%"
pause
exit /b %NXT_RESULT%
