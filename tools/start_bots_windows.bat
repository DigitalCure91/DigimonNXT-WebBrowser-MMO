@echo off
setlocal DisableDelayedExpansion
rem Foreground diagnostic launcher. For unattended operation, use
rem install_tamer_service_windows.bat once instead.
rem Point to the ACTUAL served folder containing index.php and config.
set "NXT_GAME_DIR=C:\xampp\htdocs\DigimonNXT"
set "NXT_PHP=C:\xampp\php\php.exe"
if not exist "%NXT_PHP%" (
  echo PHP was not found at C:\xampp\php\php.exe.
  echo Edit NXT_PHP in this file to your XAMPP PHP location.
  pause
  exit /b 1
)
if not exist "%NXT_GAME_DIR%\includes\tamer_worker.php" (
  echo The served game does not have the updated tamer worker.
  echo Copy the updated public_html contents into your served game folder.
  echo Edit NXT_GAME_DIR in this file if your game uses another folder.
  pause
  exit /b 1
)
echo Starting the foreground tamer worker against the served game's configuration.
echo For always-on operation, close this and run install_tamer_service_windows.bat.
echo Keep this window open for foreground mode. Ctrl+C stops this worker.
"%NXT_PHP%" "%NXT_GAME_DIR%\includes\tamer_worker.php" --batch=40 --interval=5
set "NXT_RESULT=%ERRORLEVEL%"
pause
exit /b %NXT_RESULT%
