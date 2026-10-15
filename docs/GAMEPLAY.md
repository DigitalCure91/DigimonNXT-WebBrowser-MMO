# Gameplay rules — v0.7.0

## Dawn World exploration

The atlas contains 264 supplied Dawn maps in 30 descriptive environment groups. Search by area name or number, filter wild/rest areas, and choose a destination card. Area 001 is a rest area; the other 263 areas support wild encounters. NXT travel gates connect areas, and **Area landings** reach all 481 separate floor sections. Original collision blocks walls and edges.

Wild Digimon and nearby tamers remain visible on the map. Select a Digimon or press **E** for the nearest signal, then approach along clear ground until it is in range. A signal cannot be challenged through a wall or from a disconnected platform. Map travel is blocked during an active wild battle. See [Dawn World](DAWN_WORLD.md) for controls, travel and source-map scope.

## Scan, materialize and train

Starting a validated wild battle awards scan data once for each member of the enemy formation. Merely viewing a roaming signal grants nothing. A loss or disconnect keeps the scan; victories increment win records separately. Standard scan increments: Baby 40%, In-Training 30%, Rookie 20–35%, Champion/Armor 12–22%, Ultimate 8–16%, Mega/Ultra 5–10%. Each scanned Paradox gives 1–3%. Data stops at 200%.

Conversion consumes all stored data and gives `min(5, floor((scan - 100) / 20))` starting ABI. The new partner is level 1, fully recovered, and fills an empty party slot if one is available. The collection accepts additional partners when all six party slots are filled.

Normal and Paradox versions have independent scan and discovery records. The Paradox pool is rolled separately at 0.2% of encounter signals; after the roll, each of its 500 forms is equally likely. This is deliberately an extremely rare collection system. Its exact rarity is editable in `config/paradox_encounters.json`.

## ABI

Let L be the level before changing form. Round down each division.

| Current stage | Evolve to next stage | De-evolve to previous stage |
|---|---|---|
| Baby | floor((L + 5) / 10) | — |
| In-Training | 1 + floor(L / 10) | 1 + floor(L / 5) |
| Rookie | 1 + floor((L + 5) / 10) | 2 + floor(L / 5) |
| Champion | 2 + floor(L / 10) | 3 + floor(L / 5) |
| Ultimate | 2 + floor((L + 5) / 10) | 4 + floor(L / 5) |
| Mega | 3 + floor(L / 10) | 5 + floor(L / 5) |
| Ultra | — | 6 + floor(L / 5) |

| Special relationship | Forward gain | Reverse gain |
|---|---|---|
| Rookie ↔ Armor | 2 + floor(L / 10) | 4 + floor(L / 5) |
| Champion ↔ Armor | floor(L / 10) | 1 + floor(L / 5) |
| Same-stage evolution | floor(L / 10) | floor(L / 5) |
| Champion ↔ Mega | 4 + floor(L / 10) | 9 + floor(L / 5) |
| Rookie ↔ Ultimate | 3 + floor((L + 5) / 10) | 7 + floor(L / 5) |
| Ultimate ↔ Ultra | 5 + floor(L / 10) | 11 + floor(L / 5) |
| Rookie ↔ Mega | 6 + floor(L / 10) | 12 + floor(L / 5) |

ABI cannot exceed 200. A free **mode change** is separate from same-stage evolution: it gives no ABI and preserves level/EXP. It preserves the proportion of current HP/SP, with rounding down, so toggling modes cannot become free recovery.

DNA retains the main partner's ABI plus the usual gain, personality, nickname and training. The selected second partner is consumed and contributes inherited moves, not ABI. Both must meet the specified CAM requirement. De-evolution requires discovery of the target. Missing Cyber Sleuth story/egg/spirit progression is represented by target discovery in NXT, and is marked in the route data.

Inheritable skills already learned remain through form changes and transfers. Signature skills belong to the current form. The current form's level gates future skills. Training is a direct NXT allocation system, not a timed DigiFarm simulation.

## Wild combat

Three active partners face three wild Digimon. The party holds six Digimon total: up to three active and three reserves. Every round queues active Digimon in descending effective SPD, with randomized ties. Each Digimon receives an action when its turn arrives. Guard, reserve swaps and capsules each use that action. Enemy commands use the same move, status and targeting engine as player commands.

Single-target skills require an eligible active target; whole-team moves affect the active formation. Revival can select a defeated partner. Defeated active Digimon are replaced by available reserves. Disconnect ends the encounter, retains already secured scan and restores the party. Invalid commands roll back without spending a turn, SP or an item. New command tokens prevent duplicate execution.

Victory, defeat and disconnect all restore the **entire party** to maximum HP and SP, clear temporary battle effects and commit settlement once. Older wounded parties can enter a new encounter at full strength. There is no required healing trip.

Physical attacks use ATK and DEF; magic uses INT. Vaccine beats Virus, Virus beats Data, Data beats Vaccine. Elemental advantages apply to normal and piercing attacks. Fixed and percentage-HP effects ignore ordinary attack damage modifiers. Status duration and secondary effects follow the structured move records using NXT's turn model. Boss-like Paradox forms resist instant-death finishers.

EXP pacing is adjusted for the level-24 wild cap. Required EXP per level is `max(100, 40L + 4L²)`. Higher evolutionary stages award substantially more EXP. Existing level progress is migrated proportionally; owned levels and historical total EXP are retained. The cap for owned partners is 99.

## Recovery shop

| Capsule | Restores | Price |
|---|---:|---:|
| Small HP | 250 HP | 120 Bits |
| Medium HP | 500 HP | 300 Bits |
| Large HP | 1,000 HP | 720 Bits |
| Small SP | 50 SP | 140 Bits |
| Medium SP | 100 SP | 340 Bits |
| Large SP | 250 SP | 780 Bits |

Each stacks to 99. Recovery clamps to the target's maximum and reports the actual amount restored. Full, foreign, defeated or exchange-locked targets consume nothing. During battle, defeated Digimon need a revival skill; the full party revives automatically when combat ends. Free recovery, evolution, training and party changes are blocked during active wild battles. Buying supplies remains available.

## Battle Park and autonomous rivals

See [Ranked play and bots](RANKED_AND_BOTS.md) for the live rules, energy, points, seasons, rewards, worker schedule and supported autonomous actions.
