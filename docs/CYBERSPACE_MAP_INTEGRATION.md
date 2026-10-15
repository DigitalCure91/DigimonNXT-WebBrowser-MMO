# Cyberspace Native Map Integration — v0.2.0

> Historical design notes for the earlier release. The old Cyberspace map assets and collision builder are retired in v0.7.0. Use [Dawn World](DAWN_WORLD.md) and [Dawn collision conversion](DAWN_COLLISION_NOTES.md) for the current implementation.

## Source map set
The supplied BetterCyberSpaceMaps archive contains eleven 1254×1254 PNG sectors. v0.2.0 integrates every supplied image without downscaling. The runtime maps them to a 38×38 movement grid at exactly 33 display pixels per logical cell.

| Order | Runtime area key | Display name | Runtime asset | Forward portal |
|---:|---|---|---|---|
| 0 | `terminal-plaza` | Plaza Terminal | `plaza-terminal.png` | Cyber Route |
| 1 | `packet-meadow` | Cyber Route | `cyber-route.png` | Dataflow |
| 2 | `cache-grove` | Dataflow | `dataflow.png` | Overclock |
| 3 | `proxy-path` | Overclock | `overclock.png` | Icewall |
| 4 | `firewall-ridge` | Icewall | `icewall.png` | Geargrid |
| 5 | `memory-sector` | Geargrid | `geargrid.png` | Encrypt Field |
| 6 | `data-stream-delta` | Encrypt Field | `encrypt-field.png` | Node Link |
| 7 | `server-ruins` | Node Link | `node-link.png` | Firewall |
| 8 | `kernel-garden` | Firewall | `firewall.png` | Mainframe |
| 9 | `core-nexus` | Mainframe | `mainframe.png` | The Core |
| 10 | `the-core` | The Core | `the-core.png` | — |

The first ten internal keys are deliberately retained from v0.1.1 so existing world-position rows remain compatible. The map page resets an old persisted coordinate to the sector spawn only if the coordinate is outside the new grid or now collides.

## Camera contract
The implementation follows the accepted native-map camera behavior from the predecessor regional maps:

- the map stage keeps its real 1254×1254 size;
- the containing viewport provides scrolling rather than CSS fitting/scaling;
- camera targets are calculated from the map stage's actual origin inside the scroll container;
- scroll correction is instantaneous after an accepted move and is rescheduled after layout, resize, image decode and page restore;
- browser scroll anchoring is disabled for the map viewport;
- multiplayer avatars stay in map/world coordinates and are unaffected by the local player's camera offset.

## Collision generation
`tools/build_cyberspace_collision.py` produces the committed collision JSON. It evaluates the artwork on the exact 38×38 grid, builds a candidate traversable surface from per-cell median luminance, explicitly protects authored entrance/stair/portal corridors, and then retains only the component reachable using the runtime's eight-direction movement plus no-diagonal-corner-cut rule.

The committed result is therefore deterministic and auditable rather than a browser-only collision approximation. Important structures, cliffs, water/void space and map boundaries remain outside the reachable component. Portal source and landing cells are explicitly protected and then validated.

## Portal contract
All ten progression edges are bidirectional. A transition request is resolved by `world_map_move.php` on the server before ordinary collision settlement. The server verifies the destination sector and destination collision cell before writing presence/position state.

Subtle in-world NXT LINK markers are rendered over transition cells as presentation only; walking onto the marked cell remains the authoritative way to transfer.

## Encounter placement
Encounter cells are generated only from naturally detected walkable map cells inside the player's reachable component. Spawn points and portal approaches receive quiet buffers, preventing a transition from immediately forcing a wild encounter. Plaza Terminal remains a safe hub.

## Release validation
`tools/validate_release.py` verifies:

- exactly eleven native 1254×1254 map assets;
- exact 38×38 × 33 px geometry;
- collision dimensions and source provenance;
- spawn safety;
- full encounter-cell reachability;
- portal source and destination safety;
- reciprocal portal graph connectivity from Plaza Terminal through The Core;
- all 48 launch Digimon represented across the encounter network;
- presence of the native tracked-camera JavaScript/CSS contract;
- full MMO/database regression surface and PHP/JavaScript syntax.
