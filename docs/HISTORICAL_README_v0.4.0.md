# Digimon NXT v0.4.0 — Cyber Command Edition

A complete visual rebuild from the supplied v0.3.0 PHP / MySQL game package, with a unified Cyber Sleuth-inspired hacker command center. Includes **1,000 playable supplied forms: 500 standard and 500 Paradox**, 768 executable moves, and 1,902 forward evolution routes with reverse routes available in the DigiLab.

## What's new in this redesign

- One visual system across all 32 screens: public gateway and authentication, command center, collection, party, DigiLab, DigiDex, map directory and native map, battles, supply shop, exchange, arena, operations, community, messages, clans, profile, preferences, notices, documentation, setup and errors.
- Dark navy panels, readable type, cyan holograms, electric yellow actions, restrained digital rain and scanlines. Partner sprites, portal indicators, shopkeeper and landing-page displays animate; device reduced-motion preferences, a session motion switch and the account motion setting control the effects.
- Playable **Tai and Matt** skins derived from the supplied tamer collage, exported as transparent 128×128 PNGs. Selection applies to map actors, identity panels, public profiles and community views. Legacy IDs 1/3/5 map to Tai and 2/4/6 to Matt.
- The supplied hooded **shopkeeper** is now a transparent, correctly sized shop hero and command-center shop thumbnail.
- Bounded partner artwork, actions before the battle transcript, a scrollable battle log, responsive navigation and working compact-interface preferences.
- All **1,000 original Digimon sprites**, eleven full-resolution maps, collision data, economy/combat rules and schema revision 6 remain intact. The sector directory uses eleven lightweight thumbnails instead of downloading every full map.

This is a complete XAMPP source package. No external art or data API is required at runtime. See [design and asset notes](docs/CYBER_COMMAND.md).

## Install or upgrade

1. Start Apache and MySQL in XAMPP. Copy the contents of `public_html/` into `C:\xampp\htdocs\DigimonNXT\`.
2. Check `config/app.php` for your database connection and `/DigimonNXT` base path. When updating an existing install, copy your existing connection settings into the new file, keeping `asset_version` at `0.4.0`.
3. Open `http://localhost/DigimonNXT/_setup.php` and choose **Create / Repair Schema**. This also upgrades existing saves. Back up your current database before upgrading. **Fresh Rebuild deletes the configured game database** and is only for starting over.
4. The setup should report **1,000 species and 32 tables**. `_health.php` should return `ok: true`, version `0.4.0`, and schema `6`.
5. Create an account or log back in. Your existing partner IDs, party slots, collection, inventory, wallet and scan data remain available after the upgrade.

Requires PHP 8.1+ with mysqli/mysqlnd, and MySQL 8 or MariaDB 10.4+. This redesign was checked with PHP 8.5.10 through a WASM CLI, static validators and template fixtures. It has not been run against a live MySQL server or inside Windows XAMPP in this redesign session. Historical v0.3.0 database test results are retained separately in TESTING.md.

For a new database, `database/digimon_nxt_full.sql` is the standalone phpMyAdmin alternative. Existing saves must use the PHP upgrade above, which also migrates vitals and EXP progress.

## What is playable

- All 1,000 supplied forms have artwork, stats, moves, wild obtainability, scan progress, materialization, party support, battle support, exchange support and evolution/de-evolution routes.
- Every wild encounter uses a minimum **and** maximum level with at least two possible results. All wild levels are **24 or lower**. Owned partners can grow to level 99; arena opponents keep their own level progression.
- Paradox signals are a separate **0.2% roll per successful wild encounter**. Their 500 forms share that rare pool. They use **1.75× untrained base stats**, remain powerful after materializing, award **1–3% scan per victory**, and have double normal EXP/Bits rewards.
- Paradox evolution stays within the Paradox roster. Standard evolution cannot manufacture a Paradox form. DNA requires two partners of the correct variant.
- Scan data is per form and capped at 200%. Materialization requires 100%, consumes the entire stored amount, and creates a level 1 partner. Starting ABI is 0 at 100%, then +1 per extra 20%, up to 5 at 200%.
- Cyber Sleuth / Hacker's Memory ABI calculations, including stage skips, Armor, same-stage evolution, DNA and free mode changes. Regular evolution resets level/EXP, preserves inherited skills and identity, and adds ABI. Free mode changes retain level, EXP and ABI.
- ABI caps at 200. Training allows `50 + floor(ABI / 2)` bonus points; one HP point is worth 10 HP. CAM increases for conscious party members after wild victories.
- Turn-based wild combat supports physical/magic attacks, SP costs, learned signatures and inherited moves, affinities, multi-hit attacks, piercing and fixed damage, buffs/debuffs, statuses, cures, healing, revival, guarding, switching and capsule use. Faster active Digimon move first on attack/skill turns.
- Battle turns and their effects persist in the database. Reloading, logging out, using a second login or reposting an old turn cannot duplicate the settlement.
- A working recovery shop sells three sizes each of HP and SP capsules. Purchases support quantities, wallet checks, inventory caps and repeat-request protection. Capsules work in combat and in the collection; in combat they consume a turn.
- DigiDex, materialization and owned storage include search, pagination and standard/Paradox filters.
- The original eleven 1254×1254 Cyberspace maps, collision data, portals, native scroll camera and multiplayer presence remain included.

## Data accuracy and NXT adaptations

**ABI arithmetic follows the referenced Cyber Sleuth / Hacker's Memory formulas.** The supplied assets cover forms absent from those games, so a literal Cyber Sleuth moveset does not exist for every supplied character.

The 280 standard forms covered by the referenced Cyber Sleuth dataset retain recorded move names, SP costs, move types, powers and learning levels, plus available evolution requirements. Paradox forms inherit their corresponding base data; the Paradox-only Gigadramon adds one more covered base profile. Other Digimon use official Digimon Encyclopedia special-move names with explicitly authored NXT numeric values and learning schedules. The two EX-Eraser characters and their variants are bonus digital entities with NXT profiles.

Wild combat uses NXT's single-active-partner turn model. Stat growth interpolates the source's level 1/50/99 values. Training allocation, EXP pacing, non-Cyber-Sleuth branches, story/item unlock substitutes, special-move values outside Cyber Sleuth and Paradox power are NXT adaptations. The existing arena remains an automatic team-score challenge.

See [data sources and adaptations](docs/DATA_SOURCES.md), [gameplay rules](docs/GAMEPLAY.md), [asset coverage](docs/ASSET_COVERAGE.md), and [verification](TESTING.md).

## Validation and maintenance

From the project root, with Python/Pillow and PHP available:

```sh
python tools/validate_release.py
python tools/validate_presentation.py
php tools/test_gameplay.php
php tools/test_upgrade.php
```

The PHP tests use the configured database server and create/delete new, randomly named test databases. They do not run against your game save database; the configured database account must have permission to create test databases.

Runtime data lives in `public_html/config/digimon_species.json`, `digimon_moves.json`, `digivolution_rules.json`, `recovery_items.json`, `paradox_encounters.json`, and `worlds/cyberspace_encounters.php`. No external API is needed to play. `tools/build_sql.php` rebuilds the three fresh-install SQL copies from the actual PHP schema and seeds.
