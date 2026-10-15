DIGIMON NXT - ALWAYS-ON TAMERS ON WINDOWS / XAMPP
================================================

The 1,000 tamer accounts need a running server worker to take their turns.
This installer keeps that worker running in Windows Task Scheduler without
a browser tab, player login, open console, or signed-in Windows user.

ONE-TIME INSTALLATION

1. Copy the updated public_html contents into the actual served game folder,
   normally C:\xampp\htdocs\DigimonNXT. This folder contains index.php,
   config\app.php, and includes\tamer_worker.php.

2. Keep the database settings in that served folder's config\app.php correct.
   Complete the game's Create / Repair Schema setup before expecting actions.
   Do not use Fresh Rebuild on an existing save to activate the bots.

3. Double-click tools\install_tamer_service_windows.bat and allow Windows'
   administrator prompt. The task starts immediately and starts again when
   Windows boots. You can close the installer and all game browser tabs.

4. Leave MySQL running and the server computer awake. To resume unattended
   after Windows restarts, configure XAMPP MySQL to run as a Windows service.
   The tamer installer does not change the XAMPP service configuration. The
   worker waits and retries while the database is stopped or unavailable.
   Apache is needed for people to access the game; the worker itself uses PHP
   CLI and MySQL directly. No computer can simulate battles while powered off
   or asleep.

5. Open the game's AI Activity page to see completed battles, scan collection,
   team changes and other actions. The background-worker status should show
   a current heartbeat. The browser is only displaying this ongoing work.

CUSTOM INSTALLATION FOLDERS

For the .bat launchers, edit NXT_GAME_DIR and NXT_PHP at the top of the files.
Set NXT_GAME_DIR to the ACTUAL served game folder, not the extracted ZIP root.
Do not point to a second source copy with another config\app.php.

Alternatively, run in PowerShell:

  powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\tools\install_tamer_service.ps1" -Action Install -GamePath "D:\xampp\htdocs\DigimonNXT" -PhpPath "D:\xampp\php\php.exe"

Always use the same -GamePath for subsequent Status, Start, Stop and Uninstall
commands. Every normalized game-folder path receives its own deterministic
DigimonNXT-Tamers-... task name, so separate installations can coexist.
Use a local disk path. SYSTEM cannot use your user-mapped network drives.

CHECK, STOP, RESUME OR REMOVE

  tamer_service_status_windows.bat  Shows task state, target PHP and game path.
  stop_tamer_service_windows.bat    Stops AND disables the automatic task.
  start_tamer_service_windows.bat   Enables and starts the installed task.
  uninstall_tamer_service_windows.bat
                                   Removes only this game's scheduled task.

All controls also exist through install_tamer_service.ps1 -Action with values
Status, Start, Stop or Uninstall. Match the game path in each .bat if customized.
The install command can be run again to repair/update the task registration.
Uninstalling does not remove game files, database configuration or saved progress.

WHAT THE TASK DOES

- Executes the served game's includes\tamer_worker.php using your XAMPP PHP.
- Uses the same served config\app.php as the game. No passwords are copied to
  task arguments or requested by this installer.
- Runs as Windows SYSTEM with an AtStartup trigger and no execution time limit.
- Restarts on unexpected failure. A five-minute recovery trigger checks that
  the task is running; IgnoreNew prevents a second scheduled instance.
- Uses the game's database worker lock and shared cadence. Repeated task
  starts and concurrent worker attempts do not overlap bot simulations.
- Stays running if players log out or every browser closes.
- Waits for MySQL if Windows starts the worker before XAMPP is ready.

LOGS AND TROUBLESHOOTING

Worker errors are written to the ACTUAL served game's:

  storage\logs\digimon-nxt.log

Successful actions are recorded in the game database and shown in AI Activity.
The background-worker heartbeat is recorded separately from browser activity.
Task Scheduler's Last Result 267009 means the task is currently running; it
is not a failure code. A running task may still be waiting for MySQL. Check
the log and the game's heartbeat if battle counts do not change.

If worker validation fails, copy the complete update into the served folder,
check that PHP has mysqli enabled, and retry. The --check validation requires
the correct worker version and PHP environment; it does not require MySQL to
be running. Do not edit database passwords in these launcher files.

To keep full installer error output visible, open PowerShell as administrator
and run the command above there. The status command does not normally need
elevation; use an administrator window if Windows denies access to the task.

start_bots_windows.bat remains available for FOREGROUND troubleshooting only.
It is not the always-on installation. Close that manual window after installing
the task; the task runs PHP without keeping a console window open.

VALIDATION SCOPE

The worker and gameplay are tested on Linux PHP/MariaDB. These Windows Task
Scheduler scripts were checked against Microsoft documentation and reviewed
for path quoting/settings, but were not executed on Windows in this build.

Microsoft reference documentation:
https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/new-scheduledtasksettingsset
https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/new-scheduledtaskprincipal
https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/new-scheduledtasktrigger
