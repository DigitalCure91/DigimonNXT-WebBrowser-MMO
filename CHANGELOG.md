# v0.7.2 — Battle animations

- Added quick physical attack travel, elemental projectiles, hit recoil and floating per-hit damage, critical and effectiveness feedback for both formations.
- Wild battle commands now resolve in place, with authoritative server event playback, readable healing/status feedback and safe recovery from interrupted requests.
- Integrated short Dawn impact cues with independent music/effects controls; soundtrack continues between commands. Original ROM audio files are retained.
- Added recorded replays for new ranked matches involving a player, including bot defenses. Speed and skip affect presentation only; ranked settlement remains unchanged.
- Preserved reduced-motion preferences, no-JavaScript wild commands, automatic full-party restoration, existing saves, blank database defaults and the world-server console.

# v0.7.1 — Tamer movement direction fix

- Corrected the horizontal animation rows so walking left faces left and walking right faces right for all 64 Dawn tamer appearances.
- Corrected the extraction metadata to describe the existing sheet order: down, right, left, up. Sprite artwork and animation timing are unchanged.
- Updated the release asset version so browsers and native clients discover the changed movement script. No database update is required.

# v0.7.0 — Dawn World

- Replaced the eleven generated Cyberspace maps and thumbnails with all 264 supplied Dawn maps, assembled from 351 source layers at their natural uploaded dimensions.
- Added a searchable, paginated atlas with 30 descriptive environments, wild/rest filters and destination previews.
- Decoded the original ROM collision for every map. Conservative cells preserve walls and edges; safe landings reach all 481 connected floor sections.
- Added NXT travel gates, compatible old-map aliases and safe position repair on map entry, preserving saved accounts and progression.
- Retained visible wild squads, three-versus-three combat, full-party restoration, real tamer presence and autonomous rival activity throughout the new world.
- Removed the retired generated map files from the release and documented optional cleanup for overwrite upgrades.
- Integrated the read-only native-client asset manifest. The separate Windows x64 client v1.1.0 includes the new map pack and updates requested changed assets after verification.
- Kept the blank database-password defaults, schema 8, 43 tables and all 52 local world-console commands. Upgrades use Create / Repair Schema and restart the existing tamer task.

# v0.6.0 — Local world-server console

- Added an elevated, physical-local Windows world console with a live activity feed and editable command prompt in one window.
- Added account administration, session revocation, moderation, player rewards, Digimon management and world inspection commands, with built-in help and a complete command reference.
- Added structured traffic and gameplay events with UTC timestamps, connection IP and account identity where known, plus repeat grouping and bounded log retention.
- Added schema-8 administration records and a save-preserving upgrade/repair path.
- Retained the blank database-password defaults and the independent persistent tamer worker. Closing the console leaves XAMPP and other games running.

# v0.5.1 — Autonomous tamer activation fix

- Added a persistent background worker and Windows startup-task installer for activity with no players online, automatic startup and database reconnection.
- Fixed inactive fresh-install bots: every logged-in game page now schedules bounded real actions, sharing a global cadence and lock with the background worker.
- Staggered wild/ranked/party routines from the first turns without prefilled history or resetting existing progress.
- Added live ranked standings, immediate activity updates, explicit worker status and protected POST activation.
- Kept database settings separate from release metadata for safe hotfix installation.

# v0.5.0 — Dawn Link

- Decoded original Dawn graphics: 64 selectable human appearances, 415 battle records, 361 verified matching standard portraits, 76 battle backgrounds, 530 map visual layers and 162 UI screens. Baseline maps remain playable; decoded map layers are source assets.
- Added the original 46 music sequences and 182 playable sound effects, with browser audio controls and per-scene playback.
- Visible, collision-aware roaming squads with secure click-to-battle signals, cooldowns and keyboard selection.
- Replaced single-partner wild combat with persistent 3v3 teams, reserves, targeted/AoE skills, speed initiative and automatic full-party restoration.
- Scan data is secured on encounter start, including losses and disconnects.
- Replaced the score-only arena with asynchronous Battle Park team simulation, weekly points/ranks, energy, rewards and medal exchange.
- Added 1,000 persistent AI rivals, scheduled gameplay, activity feed and head-to-head rival records.
- Schema 7 additive upgrade, fresh SQL including AI teams, bot login denial, Dawn avatar persistence and local-only setup.
- New accounts begin with three level 5 partners. Existing player saves remain intact.

# Changelog

## 0.4.0 — Cyber Command redesign

- Restart from the supplied v0.3.0 archive and replace the accumulated stylesheet with one documented visual system.
- Rebuild global navigation, public gateway, login, page headers, partner cards, shop hero, setup and error screens; restyle all game modules coherently.
- Integrate transparent Tai/Matt playable skins and hooded shopkeeper derived from the supplied images; retain six legacy skin URLs and cache-bust avatar requests.
- Apply signup skin selection consistently to both existing avatar fields.
- Add ambient matrix effects, sprite motion, reduced-motion support, session pause and working account display preferences.
- Keep battle actions ahead of the scrollable transcript and bound artwork for compact play.
- Add eleven optimized sector thumbnails while preserving original maps and camera math.
- Preserve the 1,000-form roster, progression, combat, economy, multiplayer endpoints, SQL and schema revision 6.
- Add presentation checks and current, explicitly scoped verification notes.

## 0.3.0 — Supplied roster, Paradox, ABI and recovery

- Expand the 48-form launch roster to 1,000 supplied standard/Paradox forms with normalized art and complete runtime registration.
- Add 768 executable moves and 1,902 forward routes, preserving inherited skills and keeping signature moves tied to form.
- Add researched Cyber Sleuth / Hacker's Memory ABI arithmetic, de-evolution, mode changes, DNA inheritance and ABI training.
- Expand normal encounter profiles to all 500 standard forms, use varied ranges capped at level 24, and add the independent 500-form Paradox pool at 0.2% per signal.
- Paradox forms retain 1.75× base stats after conversion/evolution and give 1–3% scan per wild victory.
- Make wild battle state, statuses and tokens durable in the database; settle rewards once and reject stale actions across logins.
- Add functional six-capsule recovery shop, quantity/stack/wallet checks, reusable inventory, battle recovery and duplicate-request receipts.
- Add searchable, paginated storage, DigiDex and scan views, with variant filters and move/route previews.
- Rebalance level pacing for the new wild cap, preserve old level progress proportionally, and upgrade revision 5 saves non-destructively to revision 6.
- Retain the eleven native maps, collision, portals, camera and multiplayer foundation.
- Bundle tested migrations, fresh-install SQL, validation tools, data attribution and asset exception notes.

## 0.2.0 — Supplied baseline

Eleven native 1254×1254 Cyberspace maps, rebuilt collision/encounter fields, bidirectional portals and tamer-tracking scroll camera on the existing MMO foundation. The original camera/map audits remain included.
