# Explore Dawn World

Digimon NXT v0.7.0 replaces the eleven generated Cyberspace maps with all **264 supplied Dawn areas**. The atlas groups them into 30 visual environments. Area 001 is a quiet rest area; the other 263 areas host wild Digimon. Your existing party, collection, scans, money and ranked progress continue into the new world.

## Find your next destination

Open **Dawn World** from the game navigation. Search by area name or number, choose an **Environment**, and filter **Adventure** to wild or rest areas. Select **Find areas** to apply the filters. Each page shows up to 24 destinations; Previous and Next retain the filters. **Reset** returns to the full atlas.

Each destination shows its preview, environment and wild level range. Maps with separate paths show their number of landings. Select a card to travel, or use **Return to…** to revisit the current area. [The complete map reference](DAWN_WORLD_MAPS.md) lists all 264 maps, their dimensions, landings and standard wild levels.

## Move and explore

Use WASD, arrow keys, the numpad or the movement pad. Tap for a precise step, or hold to keep walking. On a small screen, the movement pad stays over the map. Names appear when you hover, focus or select a Digimon; the **Names** toggle can show all labels and remembers your choice. **Find my tamer** recentres the view. The camera follows your tamer by scrolling the viewport. Maps remain at their supplied image dimensions in the page; they are not stretched to fit the window. These supplied images are exactly twice the original DS graphic dimensions. Windows client zoom and your browser's own zoom may further scale the page.

Your tamer's feet determine the movement position. Original ROM collision blocks walls, obstacles and edges. The movement grid uses four source pixels, or eight pixels in the supplied images, per cell; a cell is open only when every original collision pixel inside it is open. Diagonal movement cannot cut through blocked corners.

Other players and active AI tamers appear on the same map. Their positions use the same legal floor geometry. With 264 maps, the rivals are spread across the world rather than gathered in every area simultaneously.

## Reach paths, platforms and neighbouring maps

Use **Travel connections** in the sidebar to visit connected maps, or walk onto a marked gate. Selecting a gate displays its travel instruction. NXT connects the maps through region chains and a ring of region entry areas.

Some Dawn scenes contain separate upper paths, rooms or platforms. The **Area landings** menu lists every connected floor section in that map. Select a path and press **Travel to landing** to arrive safely on that section. The current path is identified in the menu. Across the world, these controls provide access to all 481 floor sections without opening routes through walls or cliff faces.

Travel and landing changes are unavailable during an active wild battle. Finish or disconnect from the battle first; your party is restored when the encounter settles.

These gates and landings are NXT travel features. They do not recreate Dawn's original quest conditions, scripted ladders, moving platforms or story warp engine. Environment names describe the supplied artwork; the numbered area IDs preserve the source map identity rather than claiming unverified canonical location names.

## Battle visible wild Digimon

Select a Digimon on the map or in the nearby signal list. Press **E** to select the nearest signal with the keyboard. The panel shows its level, formation and whether it is in battle range.

Move close along clear ground and choose **Battle** when the signal is in range. A nearby-looking Digimon across a wall, cliff or disconnected platform cannot be challenged through the obstacle; reach its floor section first. Merely selecting or viewing a signal grants no scan data or rewards.

A valid battle uses the existing three-versus-three combat system. Up to three additional party members remain in reserve. Scan data is awarded when the encounter starts and is kept after a loss or disconnect. Victory, defeat and disconnect restore the entire party to maximum HP and SP; no healing trip is required. [Gameplay rules](GAMEPLAY.md) cover combat, scans, materialization and progression.

Each destination's standard encounters use the existing NXT species and level profiles. The rare Paradox roll remains separate. Regional artwork does not mean the game is reproducing Dawn's original encounter tables or story difficulty curve.

## Existing saves, bots and native clients

Previous Cyberspace bookmarks resolve to compatible Dawn destinations. Saved positions are checked when entering a map and repaired to safe floor where necessary. Use **Create / Repair Schema** after installing the update; never use Fresh Rebuild to preserve saved accounts. Restart the persistent tamer task so it loads the new world code.

The 1,000 AI rivals retain their accounts and progression. Their exploration, visible map positions and wild battles use the new world, while ranked play and the local 52-command administrator console remain available. The persistent task continues operating with no players online.

The separate Windows x64 client v1.1.0 bundles the new map assets. The updated server includes its read-only asset manifest, so no separate manifest add-on is required. Matching files load locally; requested changed files are fetched from the chosen server. [Installation instructions](INSTALLATION.md) cover server and client updates.

## Source and verification

All 351 supplied map layers are retained with source provenance. Composite playable maps, atlas thumbnails and collision data are bundled. The old generated maps and thumbnails are removed from the source package.

- [Asset coverage](DAWN_ASSETS.md)
- [Collision format, conservative conversion and the map 088 canvas exception](DAWN_COLLISION_NOTES.md)
- [Actual release validation](../TESTING.md)
