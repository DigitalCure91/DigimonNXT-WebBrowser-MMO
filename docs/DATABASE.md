# Database revision 8

The original revision-5 schema remains the foundation. Revision 6 adds:

- `digimon_instances.abi`, `cam`, `intelligence_stat`, `training_json`.
- `digivolution_log.abi_gained`, `direction` (`up`, `down`, `mode`).
- `digimon_learned_moves` for inherited moves tied to instance identity.
- `digimon_battle_state` for per-owner durable turns, enemy state and statuses.
- `digimon_action_receipts` for committed item request tokens/results.

Revision 7 adds `nxt_bots`, `nxt_ranked_profiles`, `nxt_ranked_seasons`, `nxt_ranked_tickets`, `nxt_ranked_matches`, `nxt_bot_activity` and `nxt_worker_state`. Revision 7 introduced 39 tables. Avatar fields support SMALLINT IDs. Species are synchronized by slug, preserving the existing 48 species IDs. The catalog has 1,000 rows. The detailed curves, learnsets, mechanics, variant metadata and routes are versioned JSON; SQL contains the indexed species projection used by existing systems.

Revision 8 adds `members.session_version` and the four World Server tables: `nxt_world_settings`, `nxt_world_bans`, `nxt_world_ip_bans` and `nxt_world_admin_audit`. The current fresh installation has **43 tables**. Dawn World v0.7.0 keeps schema 8; its map definitions and collision live in versioned files. Existing operation IDs and progress are preserved while their area labels are updated. Old saved map rows remain available; canonical Dawn positions are initialized and validated when visited.

An account row lock serializes battle turns, conversion, evolution, training, party edits, recovery, shop purchases, arena settlement and exchange changes. Purchases spanning two accounts lock owners in ascending ID order. Battle tokens rotate on committed turns. Inventory spending, vitals, money, scan and reward updates share transactions.

The upgrade recalculates pre-revision-6 partner stats with proportional HP/SP and level progress. A transaction marker prevents reapplying the conversion if installation is interrupted later. It preserves KO status, IDs, nicknames, personality, party links and stored scan percentages. Existing partners start with the new ABI/CAM fields at zero and learn the inherited moves due at their current level.

The three packaged SQL files match byte-for-byte and are **fresh-install seeds only**. Upgrade existing saves through `_setup.php` so the data migration also runs. `tools/build_sql.php` creates those SQL copies from the same actual migration code in a new temporary database; it includes exactly 1,000 AI-only accounts and their starter teams, with no human accounts or test battle data. The login endpoint explicitly rejects AI accounts.

Revision 7 preserves current team-combat saves (`combat_version=2`). Older combat states are retired transactionally and their linked parties restored without awarding rewards. Ranked request tokens are unique and settlement locks both owners in ascending ID order. Bot workers use database advisory locks and a persistent cursor.
