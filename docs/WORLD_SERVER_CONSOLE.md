# Digimon NXT world-server console

One local command window for the world: see requests and completed game actions arrive, inspect players and rivals, and run administrator commands without leaving the feed. Your current input stays at the prompt while new activity appears above it.

This console ships with **Digimon NXT v0.7.2 / schema 8**. The Dawn World update retains its 52 commands and blank database-password defaults. It manages the installed web game's database. Apache, MySQL and the background tamer task continue doing their existing jobs.

## Open the console

1. Back up your game and database. Install this release's `public_html/` files into the game directory, preserving your existing `config/app.php`.
2. Start Apache and MySQL in XAMPP. On the server PC, open `http://localhost/DigimonNXT/_setup.php` and choose **Create / Repair Schema**. This applies schema 8 without resetting your saves.
3. Restart the background tamer task after installing updated PHP files.
4. Keep the extracted source package outside `htdocs`. On the physical server PC, double-click `tools\start_world_server_windows.bat` and accept the Windows administrator prompt.
5. Type `help` to browse commands and `status` to inspect the connected world.

The supplied default database password is **blank**. Keep your installed connection settings in `config/app.php`; the console reads the same configuration as the web game. It does not change your MySQL password.

Windows PowerShell 5.1 and XAMPP PHP 8.1+ with mysqli/mysqlnd are required. The default game directory is `C:\xampp\htdocs\DigimonNXT`. See the launcher section below for other paths.

## Launcher paths and traffic scope

Right-click `tools\start_world_server_windows.bat`, choose **Edit**, and adjust these four settings when your installation differs:

| Setting | Default | What it selects |
|---|---|---|
| `NXT_GAME_DIR` | `C:\xampp\htdocs\DigimonNXT` | The served game folder containing `index.php` and `config\app.php`; not the extracted source-package root. |
| `NXT_PHP` | `C:\xampp\php\php.exe` | Your XAMPP PHP executable. |
| `NXT_APACHE_LOG` | `C:\xampp\apache\logs\access.log` | Optional Apache Common/Combined access log for static and failed-route traffic. |
| `NXT_URL_PATH` | `/DigimonNXT/` | URL prefix used to filter Apache records to this game. |

Keep the quoted `set "NAME=value"` format. All filesystem paths must be absolute paths on the server's local disk. Network drives and UNC paths are rejected. Keep `world_server.ps1`, `world_console_backend.php` and `world-console/ConsoleHost.cs` together in their supplied `tools` layout.

Use `/` for a game served at a dedicated virtual-host root, together with that virtual host's own access log. A shared access log with the `/` prefix includes other websites, so select the game's own log in that case. No database password belongs in these launcher variables.

The Apache reader is optional. Missing or unreadable Apache logs do not stop structured PHP/game events. Use `console apache off` to disable that extra traffic. The console reads Common/Combined records; custom access-log formats may not parse. Successful `.php` routes and successful directory/index requests are skipped by the Apache reader because the game normally records them itself. Some failed PHP requests can appear both as a structured game failure and an Apache failure.

For an advanced local launch, use Windows PowerShell from the extracted package:

```powershell
.\tools\world_server.ps1 -GamePath 'D:\Games\DigimonNXT' -PhpPath 'C:\xampp\php\php.exe' -ApacheAccessLog 'C:\xampp\apache\logs\access.log' -ApacheUrlPath '/DigimonNXT/'
```

The optional `-NoApache` switch starts without the Apache reader. `-NoElevation` suppresses the automatic UAC relaunch and requires you to have opened PowerShell as administrator already; it does not bypass the administrator or physical-session checks.

## Who can use it

The interactive host requires an elevated Windows administrator in the active physical-console session on the server PC. It rejects Remote Desktop and SSH sessions, redirected input and noninteractive hosts. The console opens no listening port and provides no browser command endpoint. A web-game administrator role does not grant access to this local command window.

Protect access to the server's Windows administrator account and physical desktop. Operating-system checks cannot prevent an already-authorized local administrator or a desktop-control application from acting through that desktop.

The PHP backend is a local command-line program, not an HTTP administration API. Keep the `tools` directory outside your public web directory. Do not turn it into a web route, network listener or remotely shared script. There is no raw SQL command, arbitrary shell command or in-console script execution feature.

## Reading the feed

The structured feed uses six categories: `request`, `auth`, `game`, `bots`, `admin` and `system`. Journal events have a UTC date/time with milliseconds (the console displays seconds), category, event name, connection IP and account identity when known. Dynamic PHP requests that load the game bootstrap include the HTTP method, script path, result code and duration. Gameplay events describe committed outcomes; receiving a request does not by itself prove that a purchase, battle or other action succeeded.

The connection IP comes from the server's actual `REMOTE_ADDR`. Untrusted forwarded headers are not used to claim another address. If you use a reverse proxy, the recorded address is normally the proxy unless the web server itself is configured to supply a trusted real-client address. Background work has no player connection and is labelled local. A guest or unknown identity is shown explicitly; an account is never guessed from an IP address.

Repeated traffic is grouped in the display so movement, polling, music and sprite requests cannot bury the command prompt. Summaries appear every 15 seconds. The default `summary` mode groups static assets and routine traffic; `full` displays more individual requests while retaining a limit of eight request lines per second. Repeated game/authentication events are grouped too. Counts represent actual records, not invented player actions. For each bot action type, the first event in a 15-second window can show the real tamer and IP, subject to the overall display limit. Remaining events use a neutral `AI tamers (grouped)` label; their count excludes the displayed sample. Repeated authentication/game summaries also use neutral event labels, so different outcomes are not attributed to the first record. Grouped AI actions do not imply that all 1,000 tamers acted simultaneously.

The window starts at the current end of the logs. It does not replay the entire retained history when opened. Use `logs` for bounded recent history. Display filtering and pausing affect what you see in this window; they do not change game behaviour or erase the journal. Administration results remain visible. Apache static requests cannot identify a PHP session and are labelled `unattributed`; the console never assigns an account using IP coincidence. Apache's own access-log retention remains under Apache's configuration.

Completed action coverage includes login, signup and logout; wild encounter scans and results; ranked results and reward claims; medal exchanges; materialization, evolution and training; party changes and restoration; shop purchases and item use; trading listings, cancellation and purchases; sector changes; clan creation, joining, leaving and disbanding; sent-message metadata; and profile/options updates. Message subjects and bodies are not copied into the feed. Bot actions use the same completion hooks where applicable. Movement remains visible as HTTP traffic rather than a separate action for every step, and the feed does not print every combat turn.

The application journal is stored under the installed game's `storage/world-console/` directory. It rotates at 10 MiB per file and keeps five rotated files plus the active journal, approximately 60 MiB in total. These are bounded operational logs, not permanent backups. The files have PHP denial headers, the packaged `storage/world-console/.htaccess` denies web access, and the console sanitizes control characters so player-supplied text cannot issue terminal commands. Unix permissions request `0700` for the directory and `0600` for files; on Windows, restrict the directory through the usual filesystem permissions.

Passwords, password hashes, session cookies, CSRF tokens, authorization headers, request bodies and query-string values are not copied to the activity feed. Administrator auditing records the operation and its outcome without recording password values. If journal writes fail or the display falls behind, the console reports the gap rather than claiming complete capture. The game continues if the journal becomes unavailable; investigate disk space and storage permissions.

## Player changes and session safety

Use exact account names or the ID syntax documented by `help`. Look up a player or catalog entry before changing it. The command reference describes the supported value ranges and whether a command needs confirmation.

Password creation and changes use a masked prompt. Enter the password only when prompted; it is not part of the typed command or command history. Ban, kick and password-change operations invalidate existing game sessions. The web game enforces that revocation on the player's next server request; already-downloaded pages cannot be removed from a browser instantly.

Changes to a player's Digimon, party, scan data or inventory are rejected while a supported saved wild battle is active. Have the player finish that battle before retrying. A kick or password change leaves the battle resumable; it does not complete the battle or award rewards. Commands use the game's progression and inventory rules rather than accepting arbitrary database fields.

Read [WORLD_SERVER_COMMANDS.txt](../WORLD_SERVER_COMMANDS.txt) for every command, syntax, range, confirmation rule and example. Built-in help is generated from the same command definitions used by the backend.

## Commands and keyboard controls

[WORLD_SERVER_COMMANDS.txt](../WORLD_SERVER_COMMANDS.txt) contains all **52 backend commands**, the four aliases, every local display command, examples, defaults and limits. These are useful starting points:

| Task | Commands |
|---|---|
| Check the world | `status`, `online`, `bots status` |
| Find a player or catalog entry | `account list`, `account info`, `lookup species`, `lookup items` |
| Account support | `account create`, `account password`, `account unlock`, `account kick` |
| Moderation | `account ban`, `account unban`, `banlist`, `ip ban`, `ip unban`, `ip list` |
| Rewards and corrections | `givemoney`, `setmoney`, `giveitem`, `givedigimon`, `scan give`, `digimon level` |
| Player collection and team | `digimon list`, `digimon info`, `party`, `party set`, `heal` |
| Notices and maintenance | `announce`, `announce clear`, `mail`, `maintenance on`, `maintenance off` |
| Review changes | `audit`, `logs` |
| Change the feed | `console traffic summary`, `console filter bots`, `console filter all`, `console pause`, `console resume` |

Use `help account ban` to see a specific command, or `console help` for local controls. The examples below are illustrative: replace `DemoTamer` and instance `1234` with real values from your world.

```text
account info DemoTamer
digimon list DemoTamer
givemoney DemoTamer 5000
givedigimon DemoTamer agumon 25 40
mail DemoTamer "Support update" "Your replacement supplies have arrived."
audit 20 DemoTamer
```

Destructive or wide-impact operations marked `CONFIRM` in the command reference require the exact uppercase word `CONFIRM` before applying them. Other responses cancel. Press **Esc** or **Ctrl+C** to clear your input or cancel a pending password/confirmation prompt. Cancelling an input prompt does not undo an already-completed command.

**Up/Down** recalls up to 100 non-password commands kept only in memory. **Left/Right**, **Home/End**, **Backspace/Delete** edit the current input. **Tab** completes supported command names. **Ctrl+L** or `console clear` clears the display without deleting logs. `exit` or `quit` closes the console.

Temporary IP bans apply to one exact IPv4 or IPv6 address, without subnets or wildcards. They are a game request policy, not a firewall rule. Players sharing one network address can all be affected; an account ban is more specific. Loopback addresses cannot be banned. Account and IP bans are separate records.

For planned work, use `maintenance on "Short maintenance. Please try again shortly."`, confirm it, perform the work, then use `maintenance off`. Human game admins retain access. A separate `bots pause` remains in effect until `bots resume`; maintenance does not silently clear that manual pause. An autonomous action already running may finish before the pause takes effect.

## Keep bots running independently

Install `tools\install_tamer_service_windows.bat` once for the persistent startup task. It keeps the 1,000 AI tamers progressing when every player is offline. The console lets you inspect the world, but keeping its window open is not the replacement for that task.

Closing the world console closes its viewer and command interface. It does **not** stop Apache, MySQL, the tamer startup task or another MMO. If you intentionally stop MySQL, all games using that database server are affected; this console does not do that for you.

## Troubleshooting

| What you see | What to check |
|---|---|
| Database connection fails | Start MySQL. Verify the installed game's database host, port, account and password in `config/app.php`. Use the same game folder for the web game, worker and console. |
| Schema is too old | Update the installed PHP files, preserve `config/app.php`, then run `_setup.php` → **Create / Repair Schema** locally. Restart the tamer task. |
| No new activity appears | Open the web game and perform an action. Confirm the console points to that installation, the feed is not paused or filtered, and the journal directory is writable. |
| No bots progress with browsers closed | Check the persistent tamer startup task and MySQL. The world console is separate from the worker. |
| Local-only security check fails | Use an elevated physical Windows console session on the server PC. Do not launch through RDP, SSH, PowerShell remoting or redirected input. |
| A player can still see an old page after a kick | The session is rejected on their next request. A page already rendered in the browser does not disappear until it communicates with the server. |
| Logs report skipped or unavailable records | Check disk space, file permissions and unusually heavy traffic. Display grouping is normal; explicit gap/loss warnings need investigation. |
| WebView2 or Android client cannot connect | Check Apache and the client's configured server URL. Game clients use normal web routes; they do not connect to the world console. |

Native Windows console execution still requires acceptance testing on your server PC. See [TESTING.md](../TESTING.md) for the verification actually performed for this package.
