# Digimon NXT — Cyber Command visual system

> Historical design notes for the earlier release. The old Cyberspace map assets and collision builder are retired in v0.7.0. Use [Dawn World](DAWN_WORLD.md) and [Dawn collision conversion](DAWN_COLLISION_NOTES.md) for the current implementation.

Version 0.4.0 rebuilds the presentation from the original supplied v0.3.0 archive. It uses the existing PHP pages and server operations; it does not require a JavaScript framework, build service, font CDN or external sprite API.

## Visual rules

The shell uses navy backgrounds, cyan navigation and diagnostic detail, yellow primary actions, mint recovery feedback and violet Paradox accents. Shared typography, spacing, card borders, meters and controls cover every game module. Metadata stays subordinate to partner names and actions. Artwork fits explicit containers with `object-fit: contain`; pixel sprites use pixelated sampling and never stretch to fill cards.

The gateway features Agumon, Gabumon, WarGreymon and Patamon on a holographic platform. Game headers and modules draw from the existing roster. The shopkeeper acts as the supply terminal's principal thumbnail. Supplied posters appear in a small framed visual archive on the About page, preserving their own composition.

The native map is still 1254×1254, with a 38×38 grid and 33 px actor cells. Only the scroll viewport follows the tamer. New directory thumbnails are separate 420×220 WebP files keyed to the original route IDs. Full map files, collision data, portal graph and camera calculations are unchanged.

Battle art is bounded, and the command form precedes the scrollable transcript in the actual HTML. Mobile navigation uses accessible disclosures, retains logout access and has a usable no-JavaScript fallback.

## Motion and preferences

CSS animates the digital rain, holographic ring, partner idle motion, portal signal and shopkeeper. Hovered catalog/arena sprites have local motion. Map movement animates only the image within its existing actor wrapper. These are presentation animations of the supplied poses, not newly authored frame-by-frame creature attack sheets.

The motion switch pauses effects for the current browser session. The account's existing `battle_animation` preference now controls interface motion; the existing `compact_ui` preference controls denser cards and spacing. Device reduced-motion settings take precedence. Hidden tabs stop decorative animation. No audio autoplays.

## Tamer and shopkeeper assets

| Runtime asset | Supplied source | Preparation |
|---|---|---|
| `html/static/images/tamers/1.png` | Tai in `6(8).jpg` | Reconstructed isolated pixel sprite; genuine alpha; normalized to 128×128 with aligned feet |
| `html/static/images/tamers/2.png` | Matt in `6(8).jpg` | Reconstructed isolated pixel sprite; genuine alpha; normalized to 128×128 with aligned feet |
| Tamer IDs 3/5 and 4/6 | The same two skins | Compatibility copies of Tai and Matt, respectively |
| `assets/ui/shopkeeper.png` | `shopkeeper(1).jpg` | White background removed; genuine alpha; bounded 256×256 PNG |
| `assets/ui/circuits.png` | `blue-lights-matrix-background-vector(1).png` | Local original, low-opacity decorative background |
| `assets/ui/partners.jpg` | `8(5).jpg` | Original supplied partner artwork |
| `assets/ui/armor.jpg` | `33.jpg` | Original supplied armor artwork |
| `assets/ui/rookies.png` | `dfcwkrf-14399b83-ea6c-4d0a-9813-d4da70b09afd.png` | Original supplied portrait artwork |

The three transparent cutouts were produced with image generation/editing using the supplied references. The human figures were small and overlapped other artwork in the collage, so their isolated pixels are reconstructed interpretations, not bit-exact crops. Final export sizing used nearest-neighbor sampling and retained true alpha. All 1,000 Digimon roster PNGs are byte-for-byte unchanged from the supplied project.

New signups write the selected skin to both existing avatar fields. Existing saves are not rewritten merely by rendering a page; legacy IDs remain supported. The two canonical skins are selectable in registration and Preferences. Avatar URLs carry the new asset version, including local and remote map actors.

## Files to maintain

- `public_html/includes/ui.php`: common shell, navigation and partner cards.
- `public_html/includes/presentation.php`: skin selection, icons, sprite placement and status screens.
- `public_html/assets/css/digimon-nxt.css`: the complete visual system and responsive rules.
- `public_html/assets/js/digimon-nxt.js`: navigation, motion and accessible field associations.
- `public_html/assets/js/world-map.js`: original map client with avatar cache versioning only.
- `tools/validate_presentation.py`: art dimensions/alpha, legacy aliases, thumbnail routes and integration checks.

Database schema revision remains 6. The gameplay, economy, evolution, catalog and map endpoint implementations were preserved. Use the installation guide and current verification notes before promoting an updated installation.
