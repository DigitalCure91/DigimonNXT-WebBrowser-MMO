# Verification — v0.3.0

Verified on 7 September 2026 with PHP 8.2.32, mysqli/mysqlnd and MariaDB 10.11.18 in an isolated Linux runtime. These results do not claim a Windows XAMPP run or completed manual browser playtesting.

## Automated results

- **Static release validation: PASS.** 1,000 unique catalog entries and transparent sprite files, 768 move records, all learnset references, 1,902 evolution routes, DNA partner references, all forms obtainable, normal/Paradox isolation, 1–24 varied wild levels, six recovery effects, 32-table SQL bundle and version consistency.
- **Native-map checks: PASS.** All eleven 1254×1254 map assets, 38×38 grids at 33 px, collision/encounter separation, reachable fields, safe portal landings, reciprocal sector links, complete map graph, retained native tracked-camera code.
- **Gameplay integration: PASS.** Every form materialized at 200% scan, received correct starting ABI, vitals and initial moves, and earned valid scan rewards. All source stat anchors and Paradox multipliers checked. All 768 moves executed without invalid vitals or runtime warnings. Focused tests covered INT damage, fixed damage, complete multi-hit acceleration, statuses, buffs, recovery and revival.
- **Evolution integration: PASS.** ABI boundaries, every implemented route's ABI calculation, level/EXP resets, inheritance, signature removal, Armor, mode-change preservation, DNA partner consumption/inheritance, ownership, discovery gates and training. Paradox forms remain Paradox; normal-to-Paradox shortcuts rejected.
- **Recovery/settlement integration: PASS.** Prices, quantities, stack limits, wallet updates, duplicate purchase receipts, exact clamped healing, foreign/full targets, battle item turns, active-battle locks, invalid-move rollback, stale-turn rejection and single battle settlement.
- **Upgrade integration: PASS.** Imported the supplied v0.2.0 revision-5 SQL and a fixture account; upgraded to revision 6 / 1,000 forms. Verified account credentials, wallet, lifetime EXP, original 48 species IDs, instance IDs, nicknames, personality, party slots, scan data, inventory, proportional HP/SP/EXP progress, retained KO state and idempotent repeat migration.
- **Fresh SQL import: PASS.** Imported the generated SQL into a new empty database, verified all 1,000 forms and zero fixture users, then ran Create / Repair without duplicating the catalog.
- **HTTP integration: PASS.** Actual signup, session, starter and inventory creation; 13 gameplay pages with their sprite URLs; buying and using capsules; duplicate POST protection; Paradox materialization; authenticated map movement → encounter → battle; second login resuming the same database turn; stale turn rejected; health endpoint reporting schema 6 / 1,000 species.
- **Syntax: PASS.** Runtime and tooling PHP files; existing JavaScript checked with Node.

## Re-run the checks

```sh
python tools/validate_release.py
php tools/test_gameplay.php
php tools/test_upgrade.php
```

Python needs Pillow. PHP tests create and remove new randomly named test databases using `config/app.php` credentials. No fixture accounts are packaged into the install SQL. `tools/build_sql.php` produces the three matching fresh-install SQL copies from a temporary database.

## XAMPP acceptance check

1. Upgrade a backup of the previous game using **Create / Repair Schema**. Check `_health.php` and sign into the old account.
2. On a desktop and phone-sized viewport, inspect the shop, a long moveset, both roster filters and the evolution selector. Confirm keyboard navigation and visible labels.
3. Walk through a data field, start a battle, use a skill and a capsule, switch, finish, and inspect scan/reward history. Refresh and reconnect during another battle.
4. Convert at 100% and 200%, evolve/de-evolve, inspect ABI and inherited moves, and verify a free Imperialdramon mode change.
5. Use two browser profiles to check shared map presence and a simultaneous purchase/turn request. Database account locks and receipts protect those paths; the automated HTTP run covered separate sessions and replay, not a sustained multiplayer load test.
6. Review rare Paradox behavior using the test suite or a disposable test database. Do not increase production rarity solely to force a test encounter.

Historical v0.2.0 camera/map audit files are retained as baseline documentation; they are not new visual acceptance claims.
