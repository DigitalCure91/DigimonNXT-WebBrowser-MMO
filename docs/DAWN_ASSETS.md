# Digimon World Dawn assets — v0.7.0

The supplied US Nintendo DS ROM is decoded locally by `tools/extract_dawn_graphics.py`. The release includes the extracted browser images and their provenance, so the server does not need the ROM or an emulator at runtime. No ROM executable is included in the release.

## Included graphics

| Graphics | Count | Where they are used |
|---|---:|---|
| Selectable human tamer appearances | 64 | Avatar picker and overworld player/rival rendering |
| Character graphics | 890 | Original source images, including Digimon, NPCs, scenery and effects |
| Battle graphic records | 415 | Original decoded battle sprites, preserving numeric source IDs |
| Normal roster forms replaced with verified Dawn sprites | 361 | Existing Digimon portrait paths throughout the game |
| Battle backgrounds | 76 | Battle presentation and reusable original backgrounds |
| Original-size map reference layers | 530 | Historical graphics extraction; retained as source reference |
| Uploaded upscaled map layers | 351 | Preserved inputs for the new playable world |
| Playable Dawn maps / atlas thumbnails | 264 / 264 | Dawn World atlas and exploration |
| Connected floor sections | 481 | Original collision geometry, reached through safe NXT landings |
| Original interface screens | 162 | Reference graphics; browser screens use responsive HTML |

The original v0.5 graphics extraction reported **zero decoding errors**. Battle record counts include the ROM's placeholder records and unnamed boss/effect graphics; they do not mean 415 unique named, playable species.

## Playable Dawn World

The v0.7.0 update uses all 351 uploaded upscaled layers to build 264 playable maps. Single-layer maps are byte-identical copies of their inputs. Paired maps use ordinary alpha composition in A-then-B order, with no resizing. Original source layers remain available through the manifest's provenance paths. Only atlas thumbnails are resized.

Maps display at their supplied pixel dimensions, exactly twice the original DS graphic canvas. Collision is decoded separately from the ROM's original per-pixel collision files; it is not inferred from image colours. Four native pixels form one eight-display-pixel movement cell, and all 16 source collision pixels must be open. Safe landings expose all 481 connected sections. Map 088's narrower visual canvas is handled by cropping an entirely blocked source strip, preserving alignment.

The atlas groups the maps into 30 environments described by their visible artwork. Numeric source IDs are retained. Gates, landings, encounter profiles and environment labels are NXT presentation and gameplay, not a recreation of Dawn's story scripts or verified canonical area names. The eleven generated Cyberspace maps and thumbnails are removed from the new release.

[Dawn World guide](DAWN_WORLD.md) · [Every map](DAWN_WORLD_MAPS.md) · [Collision conversion](DAWN_COLLISION_NOTES.md)

## What is verified

Battle graphics come from `/dat/BTCHR.PAK`: Nintendo RLE compression is decoded, then NCGR tiles, NCLR palettes and NCER cells are assembled into transparent images. The ROM's `/dat/btchr/chrsize.bin` maps each battle graphic index to a species ID. Names are read from the validated English name table in the ROM's ARM9 data.

Only an unambiguous exact normalized match to an existing roster name, asset name, reference name or slug replaces a normal roster sprite. These explicit catalog aliases resolve abbreviations such as Imperialdramon DM; no fuzzy name matching is used. Replaced images remain 256 × 256 transparent PNG files and use nearest-neighbor scaling to preserve the original pixel artwork. `public_html/config/digimon_sprite_sources.json` records updated provenance for every replacement. Forms without a verified match retain their existing artwork. Fan-made paradox forms retain their existing artwork.

Human tamers were visually inspected. The 64 selectable appearances include the four Dawn/Dusk protagonists and distinct human NPC variants. Debug outlines, placeholder silhouettes, non-human characters and redundant protagonist animation sets are excluded. Neutral numbered labels are used when a character's identity has not been verified. Each playable tamer includes a front-facing PNG and a three-column, four-row movement sheet. Sheet rows are **down, left, right, up**. Available original movement frames repeat where an NPC has fewer distinct poses.

## Where to find assets

- `public_html/config/dawn_assets.json`: runtime catalog, stable avatar IDs, species mapping, source IDs, coverage and limitations.
- `public_html/includes/dawn_assets.php`: `dawn_tamers()`, `dawn_tamer_asset()`, `dawn_tamer_sprite()`, `dawn_digimon_asset()` and `dawn_battle_background()` helpers.
- `public_html/assets/dawn/`: original-size images grouped by tamers, characters, Digimon, backgrounds, maps and interface screens.
- `docs/DAWN_GRAPHICS_AUDIT.json`: historical v0.5 graphics extraction and mapping audit, including source SHA-256; its map limitations describe that earlier graphics-only conversion.
- `public_html/config/worlds/dawn_world.json`: the current 264-map manifest, dimensions, provenance, landings, gates and encounters.
- `public_html/html/static/images/worlds/dawn/`: current playable maps, preserved paired source layers and atlas thumbnails.
- `public_html/config/worlds/dawn_collision/`: conservative movement grids, component labels and safe floor metadata.
- `docs/DAWN_COLLISION_AUDIT.json`: current collision-source and output hashes for all 264 maps.
- `docs/verification/dawn_asset_audit.json`: current input-image hashes, composition policy and map/layer coverage.
- `docs/DAWN_TAMER_CONTACT_SHEET.png`: all selectable appearances with their stable database avatar IDs.

Graphics paths beginning `assets/dawn/` are relative to the public web root; resolve them with `pv_url()`, not `pv_static()`.

Useful background IDs, described by visible artwork rather than unverified location names:

| Source ID | Appearance |
|---:|---|
| 0 | Forest clearing |
| 30 | Blue industrial room |
| 33 | Snow and ice |
| 46 | Lava cavern |
| 49 | Cyan circuit floor |
| 64 | Red and blue tournament floor |
| 65 | White indoor arena with colored display panels |
| 74 | Cyan and gold digital arena |
| 75 | Purple digital arena |

## Reproduce the extraction

Install Python 3.10 or later and Pillow, then run from the project directory:

```text
python -m pip install Pillow
python tools/extract_dawn_graphics.py "C:\path\to\Digimon World - Dawn (USA).nds"
```

The extractor supports the supplied US `A3VE` ROM revision. It validates the species name table before it uses any names, and stops on an incompatible revision. The source ROM's SHA-256 is recorded in the manifest. Do not point this command at a different game's ROM.

This command regenerates the original graphics, historical-style graphics audit and source manifest, and replaces only verified normal roster PNGs. It does not rebuild the v0.7.0 playable map pack or change accounts, database contents, moves or story progression. `--skip-maps` omits the original-size map reference extraction.

To reproduce the current playable map pack, first extract the supplied `DigimonDawnAllLevelMaps.7z` into a folder, then run:

```text
python tools/extract_dawn_world.py "C:\path\to\Digimon World - Dawn (USA).nds" --maps "C:\path\to\extracted\maps"
python tools/build_dawn_world.py --maps-dir "C:\path\to\extracted\maps"
```

The collision builder verifies all map dimensions and source IDs before the map pack is composed. The current asset and collision audit files record the output provenance.

## Scope of the conversion

Dawn and Cyber Sleuth have different rosters and game structures. Dawn cannot supply artwork for every Cyber Sleuth species or fan-made form. The uploaded Dawn map images and their original collision geometry now power the playable world. The browser camera, NXT travel controls and safe landings adapt those maps to the MMO. Original event scripts, quest conditions, moving platforms and story sequences have not been ported. NXT's encounter profiles remain distinct from the original game's encounter tables.
