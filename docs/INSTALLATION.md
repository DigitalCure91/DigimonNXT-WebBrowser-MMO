# Install or upgrade v0.7.2 — blank-password edition

Use [START_HERE.txt](../START_HERE.txt) for the XAMPP walkthrough. This release installs the Dawn map world while retaining schema 8, 43 tables and the existing administrator console.

## Requirements and defaults

The web game requires PHP 8.1+ with mysqli/mysqlnd, MariaDB 10.4+ or MySQL 8, and a modern browser. The local administrator console additionally requires Windows PowerShell 5.1 and an elevated physical Windows console session on the server PC.

The supplied `config/app.php` uses database `digimon_nxt`, host `127.0.0.1`, port `3306`, user `root`, a **blank password**, and base path `/DigimonNXT`. Configure these to match your existing MySQL account. Installation does not change the MySQL account password.

## Fresh installation

1. Start Apache and MySQL in XAMPP.
2. Keep the extracted source outside the web root. Copy only `public_html/` contents into `C:\xampp\htdocs\DigimonNXT`.
3. Check the installed `config/app.php`.
4. On the server PC, open `http://localhost/DigimonNXT/_setup.php` and choose **Create / Repair Schema**.
5. Check `_health.php` for `ok:true`, version `0.7.2`, schema `8`, 43 tables, 1,000 species and 1,000 bots.
6. Install the persistent tamer task, create a player account and open **Dawn World**.

All game artwork, maps, collision and audio are bundled. No ROM, emulator or external content API is required at runtime. Click **Enable sound** once to allow browser playback.

## Upgrade or repair saved accounts

**From v0.7.1:** the small Battle Animations Update ZIP contains only the changed application files and leaves `config/app.php` intact. No new schema migration is required. Copy its `public_html/` contents into the installed game folder, restart the tamer task and reload your browser/client. The full-source procedure below also supports older saves.

Back up the database and installed files. Preserve the installed `config/app.php` while copying updated `public_html/` contents. Keep your database name, host, port, username, password and game base path. This package does not change MySQL users or other MMO configurations. Copy the new `config/version.php` and all other application files. It supplies the release label independently of your connection settings.

Open `_setup.php` through localhost on the server PC and choose **Create / Repair Schema**. Repair can be repeated and keeps player progression and existing rival accounts. **Fresh Rebuild deletes the configured game database; do not use it for an upgrade.** Another MMO's database is not changed.

Restart the background tamer task after copying the updated PHP application. Previous Cyberspace URLs resolve through compatibility aliases. Saved map positions are checked on entry; positions that no longer match valid floor are moved to safe landings as needed. There is no wholesale player reset or database-map reimport to perform.

The new package excludes the retired generated map images and sector thumbnails. Copying over an old installation may leave unreferenced files on disk. After backing up, you may remove only these retired folders from the installed web root if they remain:

- `html/static/images/worlds/cyberspace`
- `assets/ui/sectors`

The new maps are under `html/static/images/worlds/dawn`. Keep that directory and your installed configuration.

## Background tamers and local administration

For a fresh installation, run `tools/install_tamer_service_windows.bat` once. Set `NXT_GAME_DIR` to your installed game folder if it differs from `C:\xampp\htdocs\DigimonNXT`. The task uses that copy's configuration and reconnects after database restarts. Keep the computer awake and MySQL running. [The tamer service guide](../tools/README_TAMER_SERVICE.txt) covers custom paths and task management.

To reload an existing task after upgrading, use `tools/stop_tamer_service_windows.bat`, then `tools/start_tamer_service_windows.bat`.

Run `tools/start_world_server_windows.bat` locally on the physical server PC and accept administrator elevation. Type `help` or `status`. The console retains all 52 commands and remains separate from Apache, MySQL and the tamer task. [Console guide](WORLD_SERVER_CONSOLE.md) · [Command reference](../WORLD_SERVER_COMMANDS.txt)

## Windows client and asset delivery

The separately supplied Windows x64 client v1.1.0 includes the Dawn maps and current game assets. Extract the complete client ZIP into a new folder and launch `DigimonNXT.exe`; existing local preferences and login profiles are retained. Server settings support IP addresses, domains, subdomains, ports and custom folders. The default is `http://127.0.0.1/DigimonNXT/`.

The game now includes `_client_manifest.php`. Copy the current file during the server upgrade; no separate manifest add-on is needed. The client uses bundled files only when their hashes and sizes match the selected server, and fetches requested changed assets as needed. Manifest checks are periodic; server caching can delay recognition of asset changes. Use the client's Reload/F5 after an update. The client retains its 200% default game zoom and Windows DPI support. This release does not rebuild Android.

The game, background worker and console must use the same installed configuration. See [TESTING.md](../TESTING.md) for actual validation and platform acceptance limits.
