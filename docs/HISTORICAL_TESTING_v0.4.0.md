# Verification — Digimon NXT v0.4.0

The redesign checks below ran on 8 September 2026 against the fresh re-extraction of the supplied v0.3.0 ZIP. The local PHP runtime was PHP 8.5.10 via a WASM CLI. They do not represent a Windows XAMPP run, a live MySQL integration run, browser layout testing or multiplayer load testing.

## Completed checks

- **PHP syntax: PASS.** All 64 runtime/tool PHP files passed the PHP CLI parser. The final shared presentation helpers were rechecked after their last edit.
- **Public PHP rendering: PASS.** Home, login, registration, news, about, release notes, terms, privacy, 404 and setup rendered without PHP warnings, notices, deprecations or fatal errors. These renders used the unavailable-database branches where relevant. Their emitted local artwork, script, stylesheet and icon URLs resolved to files.
- **Authenticated view fixtures: PASS.** Twenty-four cases across eight routes executed the actual view segments and shared helpers with nonconnecting database doubles. Covered active/win/loss/flee/empty battle states; stocked/busy/poor/full-vitals/empty shop states; legacy skin ID 4; collection, DigiDex and materialization; normal/busy/DNA/mode/empty evolution; hub and encounter-field maps. No warnings, fatal errors or missing emitted assets. These fixtures did not execute controllers, live queries or game settlements.
- **Action contracts: PASS.** Original form actions, field names, button values, CSRF fields and input constraints were compared with the supplied archive. All six wild-battle actions remain available, and the command form precedes the transcript. Signup's added avatar field has a matching eight-parameter SQL binding. Native map config keeps its original keys and adds only avatar asset versioning.
- **Asset integration: PASS.** Genuine RGBA transparency for the two 128×128 skins and 256×256 shopkeeper; aligned tamer feet; all six legacy skin URLs; eleven 420×220 route-keyed WebP thumbnails; decorative sprite references and CSS image URLs.
- **Static release checks: PASS.** All 1,000 unique forms and original 256×256 sprites; 768 moves; 1,902 evolution routes; learnsets; Paradox isolation; recovery catalog; SQL bundle; eleven native maps; reachable encounter fields; reciprocal portals; full map graph; native camera tokens and version consistency.
- **Integrity comparison: PASS.** 1,053 protected original files matched the supplied ZIP byte-for-byte, including the Digimon roster artwork, full map artwork/config/collision data, SQL and core gameplay implementations. Health output changes only its release fallback; schema revision stays 6.
- **JavaScript/CSS: PASS.** Both scripts passed Node syntax checks. The replacement CSS parsed without declaration/rule errors; standard selectors were checked, with browser-specific progress pseudo-elements handled separately. Responsive contracts were reviewed from the source. Browser interaction and computed layout were not tested in this environment.

The machine-readable summary is [REDESIGN_VERIFICATION.json](docs/REDESIGN_VERIFICATION.json). Historical v0.3.0 database/HTTP results are retained in [HISTORICAL_TESTING_v0.3.0.md](docs/HISTORICAL_TESTING_v0.3.0.md); they are not new v0.4.0 runtime claims.

## Re-run locally

```sh
python tools/validate_release.py
python tools/validate_presentation.py
php tools/test_gameplay.php
php tools/test_upgrade.php
```

Python validators need Pillow. The release validator explicitly reports a skip if a native PHP executable is absent. The PHP integration suites use the configured MySQL server and create/remove randomly named test databases. They require test-database creation permissions and are not intended to target the game save database.

## XAMPP acceptance

1. Upgrade a backup of the current installation, copying its database settings into the new config while retaining `asset_version` `0.4.0`. Use **Create / Repair Schema**, then check `_health.php` reports version 0.4.0, schema 6 and 1,000 species.
2. Sign in with an existing account. Change between Tai and Matt in Preferences, reconnect, and confirm the selected skin on the map and profile. Register a new account with each choice.
3. Check the gateway, navigation, collection, long move lists, evolution routes, shop, messages and profiles at desktop and phone widths. Confirm readable text, visible focus, working menu disclosures and accessible logout.
4. Check session motion pause, account motion, device reduced motion and compact layout. Confirm animations pause in a hidden tab and controls retain their labels.
5. Enter a data field, begin a battle, use each action type, finish, and check scan/rewards. Attack controls should appear above the transcript. Refresh/reconnect during a battle.
6. Buy/use capsules; verify insufficient Bits, full vitals and active-battle restrictions. Re-run the bundled gameplay/upgrade tests against a disposable database.
7. Open two tamer sessions to check presence, route transfers and avatar cache refresh. No sustained multiplayer load test was performed for this visual release.
