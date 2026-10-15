# Battle Park and autonomous tamers

Digimon NXT 0.7.0 retains the weekly Battle Park, 1,000 persistent AI tamers, a recorded activity feed and a personal rival hub. Ranked fights, player wild fights and bot wild fights all use `includes/team_combat.php`: skills, SP, speed order, targets, attributes, damage, status effects and defeat determine the outcome. No team-score roll selects a winner.

## Dawn World map update

Rivals explore the 264-map Dawn world using the same collision, legal floor sections and encounter profiles as players. Their positions and battles remain visible; existing rival accounts, collections, scans and ranked progress are preserved. The atlas contains one rest area and 263 wild areas, with 481 accessible floor sections. AI tamers are distributed across those maps rather than guaranteed to appear in every area at once.

After upgrading the server, restart the persistent tamer task so its long-running PHP process loads the new map code. The zero-player worker, fair activity schedule, ranked rules and local administrator console remain in place. The update does not require reseeding rivals or rebuilding the database. [Dawn World guide](DAWN_WORLD.md)

## What is reproduced, and what is adapted

ReArise's Battle Park was asynchronous five-versus-five player-team combat with weekly placement rewards. NXT uses the requested **three-versus-three** active-team format and its existing Cyber Sleuth-inspired stat, move, scan and evolution systems. NXT's match points, energy values, tier thresholds, matchmaking, rewards and season boundary below are explicit project balance rules. They are not claimed to be a reverse-engineering of ReArise's closed servers, proprietary formulas, iOS client, ranking brackets or every Cyber Sleuth system.

A contemporaneous firsthand player guide describes ReArise's five-versus-five asynchronous Battle Park and weekly rank rewards: [Digimon ReArise Game Guide Part 1 (2019)](https://www.reddit.com/r/DigimonReArise/comments/dethxz/digimon_rearise_game_guide_part_1/). This establishes the inspiration; it does not verify NXT's numerical settings against the original service.

## Entering Battle Park

1. Open **Battle Park** from the Network menu.
2. Your first three available party members form your attacking and defending team. An older account with fewer than three partners can still enter; fill the remaining slots for a complete team.
3. Check the rival's actual Digimon, levels and attributes. The **Player** and **AI Tamer** badges distinguish humans from bots.
4. Press **Enter battle**. Both teams start fully restored and automatically choose actions using the shared combat engine.
5. Read the result, rewards and per-action battle report. Your entire party finishes with full HP and SP.

Opponents defend asynchronously from a real formation snapshot. They do not need to be online. A defense does not interrupt their wild battle, consume their energy, change their current HP/SP, or alter their weekly points. It creates a defense record, counts toward rival history, and updates defense wins/losses. Ranked battles are not real-time multiplayer duels.

The matchmaker considers season points and team strength, samples across the full eligible tamer population, and gives a human-opponent candidate pool priority on 35% of searches when human teams are available. A short-lived server ticket binds the selected opponent and formation to the attacking account. The browser cannot submit arbitrary opponent stats.

## NXT ranked rules

| Setting | Behavior |
|---|---|
| Format | First three available partners per side; no ranked reserves |
| Season | Monday 00:00 UTC through the following Monday 00:00 UTC |
| Battle energy | Maximum 5; one point regenerates every 30 minutes |
| Entry cost | 1 energy, charged only with a successful completed settlement |
| Victory | +20 points, plus 0–5 additional points from the ongoing win streak |
| Defeat | −10 points; points never fall below zero |
| Draw | No points gained or lost; streak ends |
| Victory rewards | 150 Bits, 90 EXP per participating partner, 3 BP medals |
| Defeat / draw | 25 / 50 Bits, 30 EXP per participating partner, 1 BP medal |
| Draw limit | 60 rounds, with a finite action cap |
| Ranking ties | Points descending, wins descending, then tamer ID ascending |
| Restoration | Full HP and SP after the battle |
| Match ticket | 10-minute expiry; one successful use; refreshed opponent invalidates the previous ticket |

A season rollover during a calculation aborts settlement without spending energy or granting rewards. Find a new-season matchup and play again.

| Tier | Minimum season points |
|---|---:|
| I | 0 |
| H | 40 |
| G | 100 |
| F | 200 |
| E | 350 |
| D | 550 |
| C | 800 |
| B | 1,100 |
| A | 1,500 |
| S | 2,000 |

Weekly points and streaks reset through separate season records. Lifetime results, owned Digimon, levels, inventory, medals and historical matches remain. Historical placement is frozen when the completed season is first finalized for rewards, so later account changes do not reorder already-finalized rewards. At least one attacking match is required to claim a season reward; merely appearing in the defender pool does not qualify.

| Placement | Bits | BP medals |
|---|---:|---:|
| 1 | 20,000 | 150 |
| 2–10 | 12,000 | 100 |
| 11–100 | 7,000 | 70 |
| 101–500 | 4,000 | 40 |
| 501 onward | 2,000 | 20 |

The medal exchange purchases real recovery capsules for your inventory. Costs are 8 medals for Medium HP, 10 for Medium SP, 18 for Large HP and 20 for Large SP. Stock limits, balances and receipt replay protection apply. Capsules remain useful during wild battles even though parties restore afterward.

## Tamer rivals and activity

**Tamer Rivals** combines your attacks and defenses across seasons. It displays the latest rival, the most encountered rival, the least encountered rival, your win/loss/draw record, and incoming challenges. “Least encountered” means the least among tamers you have actually battled, not a fabricated relationship with an unknown tamer. Filter by player/AI identity, search names and sort by recency or encounter count.

**AI Activity** displays records produced by completed actions. Wild reports include actual outcome, encountered species, levels, scan gained, EXP, Bits and battle highlights. Ranked entries link internally to persisted match identities and report their actual point change. Materialization, party rotation, training, evolution, shop purchases and season claims only appear after those actions complete. The page reports the worker heartbeat and how many tamers acted during the last 15 minutes.

## What the 1,000 bots actually do

Setup creates 1,000 unique accounts and 3,000 owned level-5 rookie starters. Their passwords are random, unexposed and additionally blocked by the login route. Setup is repeatable: existing tamers and progress are preserved rather than reset or duplicated. Each bot has a stable numbered identity, one of the selectable tamer avatars, its own Digimon, party, inventory, money, scan records, evolution history and ranked energy.

The worker performs these genuine actions:

- Explore encounter sectors using the same level cap and rare Paradox signal roll as the player's encounter system. Persist legal map positions and visible AI tamer presence.
- Fight real wild teams with up to three active partners and the full six-slot party available as reserves. Strong encounters can defeat the bot; victories and defeats are never forced to meet a ratio.
- Gain scan data once for each actual encounter, including defeats. Wins increment scan-win statistics separately. A timeout is recorded as a draw rather than a fabricated defeat.
- Earn ordinary wild victory Bits, party EXP and CAM, learn moves through leveling, and contribute to supported area operations through the shared battle-record hook.
- Convert sufficient scan data into real owned partners through the normal materialization function, consuming the actual accumulated scan.
- Rotate owned Digimon into the active slots without deleting displaced party members.
- Digivolve when the shared route and stat/level/ABI/CAM/discovery requirements are satisfied. The current policy selects upward non-DNA routes.
- Spend valid bonus training points using the shared training function.
- Buy a small supply of real recovery capsules with earned Bits through the normal shop and receipt system.
- Play ranked matches against real players' defending teams and other persistent bots, subject to the same energy and reward rules.
- Claim completed weekly rewards once.

Bots do not send chat, friend requests or unsolicited messages. They do not automatically manage clans, list/buy partners on the marketplace, perform DNA/de-digivolution strategies, or reproduce every human decision. They currently stock capsules; their automatic combat policy uses learned skills rather than consuming inventory items. These are supported autonomous gameplay loops, not a claim that every browser feature has a bot policy.

## Persistent worker on XAMPP / Windows

Run `tools/install_tamer_service_windows.bat` **once** after installing the files. It registers an elevated Windows startup task running as SYSTEM, starts it immediately, and targets `C:\xampp\htdocs\DigimonNXT\includes\tamer_worker.php` by default. It reads the **installed game's** `config/app.php`, avoiding the old separate-source configuration mismatch. Custom PHP/game paths and controls are documented in `tools/README_TAMER_SERVICE.txt`.

Afterward every browser may close, with no players online. The worker continues, restarts at Windows startup, and retries when MySQL is unavailable. Keep the host awake and MySQL running; configure MySQL as a Windows service if it should start automatically at boot. This cannot perform work while the server computer is switched off. The startup task requires one Windows administrator approval to install; ordinary gameplay does not.

Tamer Activity distinguishes **Background worker connected** from browser-only activity. The startup task can be checked, stopped, started or removed using the matching tools scripts. Stopping disables the task so its automatic recovery trigger will not immediately restart it. Uninstalling only removes that game path's task; it does not remove accounts, saves or files. The task launches the served PHP file directly, so the extracted installer folder need not remain in place afterward.

For foreground diagnosis (keep its terminal open), use `tools/start_bots_windows.bat`, or:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\DigimonNXT\includes\tamer_worker.php --batch=40 --interval=5
```

For a single diagnostic batch from a full source checkout:

```bat
C:\xampp\php\php.exe tools\run_bots.php --game-dir="C:\xampp\htdocs\DigimonNXT" --once
```

On Linux, run the installed `includes/tamer_worker.php` under a process supervisor with restart enabled and the appropriate local PHP path. The game does not require players to drive this process. The CLI entry point rejects HTTP access.

### Automatic browser fallback and shared scheduling

Every authenticated game page sends a protected POST pulse automatically. It processes at most 40 due tamers with a 1.5-second soft budget between complete actions. The persistent worker uses a 2-second soft budget and defaults to 40 due tamers per loop, followed by a five-second pause. Actual throughput depends on the host and combat duration. Each bot still has its own 120–180 second cooldown, and a durable fair cursor advances across all 1,000 bots.

The browser and background worker use the same database-scoped advisory lock and five-second global cadence. Throttled pulses do not move the heartbeat or cursor. Multiple tabs/users cannot multiply the action rate. Each action settles before yielding, so the budget does not interrupt a battle partway through. Browser sessions are released before simulation; untrusted requests cannot select a bot, batch size, action or server time. Authentication, CSRF and active human account checks are enforced. The browser fallback pauses if every tab closes or the browser suspends it; install the startup task for guaranteed independence from players.

Wild and ranked results appear from the first batches, because initial phases are offset by tamer number without faking progress. The activity feed and leaderboard refresh every 15 seconds while visible, with additional refreshes after actual completed batches. Open battle reports and challenge forms are preserved. No historical results are prefilled during installation.

### Action cadence

Each bot advances through a ten-step cycle, staggered by its tamer number: two ranked opportunities, one materialization opportunity, one evolution/party-management opportunity, and wild exploration in the remaining slots. Every third cycle substitutes one training action; another cycle substitutes a shop visit. When an optional action has no valid target, insufficient scan, or no ranked energy, that opportunity becomes a wild battle. Consequently ranked cannot crowd out scanning and collection growth. Regular weekly reward claims are checked alongside this cycle.

## Persistence and operational notes

- Ranked settlement atomically saves rewards, energy, point changes, defense statistics, the battle report, receipt consumption and bot ranked feed entry.
- Wild bot settlement atomically saves scans, rewards, battle history, outcome counters, feed and map presence. Every party is restored in that settlement.
- Materialization, evolution, training and purchases use their existing transactional game services. Their authoritative game records survive even if the worker stops before its separate activity-feed annotation is added; an abrupt stop in that narrow window may omit a feed entry, not fabricate one or undo the owned Digimon change.
- Recent bot activity is pruned after 30 days in bounded worker batches. Ranked reports and game battle records remain in the database; administrators should plan regular database backups and retention for long-running busy servers.
- This is a local/XAMPP release tested functionally, not a load-test certification for public servers with thousands of simultaneous humans.

## Verification

Run against a local test-capable MySQL/MariaDB account:

```bat
C:\xampp\php\php.exe tools\test_ranked.php
```

The test creates and removes a uniquely named isolated database. It checks all 1,000 accounts and 3,000 real starter instances, repeatable seeding, energy timing, genuine ranked calculation and snapshots, replay and ownership rejection, a full 1,000-tamer cursor sweep, genuine wild wins/losses, defeated-party scans, restoration, materialization, formation preservation, training, evolution, shop inventory spending, map presence, season reward replay protection and frozen ranks, medal exchange receipts, and worker overlap locks. It does not delete or reset the configured game database.
