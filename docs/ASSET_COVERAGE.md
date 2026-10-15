# Dawn Link asset coverage update — v0.5.0

361 of the 500 standard roster forms now use verified Dawn artwork extracted from the supplied ROM. The remaining 139 standard forms and all 500 Paradox forms retain their baseline sprites. All 64 selected human appearances, 46 music tracks and 182 playable effects are integrated. See [DAWN_ASSETS.md](DAWN_ASSETS.md) and [DAWN_AUDIO.md](DAWN_AUDIO.md) for exact extraction details and limitations.

The earlier roster coverage record below describes the baseline; its original artwork provenance is superseded only for the verified mapped sprites in the current source manifest.

---

# Supplied asset coverage

Imported from the successfully received archives:

- `2D Digimon Assets v7 Without Paradox Variants.zip`
- `Paradox Variantspt1.zip`
- `Paradox Variantspt2.zip`
- `Paradox Variantspt3.zip`

The larger combined Paradox ZIP attempts failed to become readable. The three successful parts supplied the artwork used in this build.

## Result

**500 standard forms + 500 Paradox forms = 1,000 playable forms.** Each has a transparent 256×256 runtime PNG. All have stats, learning schedules, evolution routes and encounter obtainability. Four of the forms are the two supplied EX-Eraser bonus entities and their Paradox versions.

Existing 48 baseline sprites are preserved. Other normal sprites were cropped/normalized from supplied idle or primary poses; flat exterior backgrounds and small sheet fragments were removed where necessary. Supplied Paradox transparency, aura and colors were preserved during normalization. No replacement Digimon roster artwork was generated. The v0.4.0 tamer/shopkeeper UI assets are documented separately in CYBER_COMMAND.md.

The manifest at `public_html/config/digimon_sprite_sources.json` maps every runtime sprite to a source. `tools/data/normal_asset_manifest.json` and `paradox_asset_manifest.json` record the import mapping. Multiple animation-frame PNGs represent poses of a species; they are not separate roster entries. The runtime currently displays a representative pose in battle and collection cards.

## Folder corrections and real gaps

| Pack detail | Treatment |
|---|---|
| Agunimon appears under Champion and Ultimate | One form per variant; use the Champion/Hybrid profile |
| A Champion Shoutmon folder lacks usable sprite art | Use the supplied Rookie Shoutmon artwork/profile |
| DevimonIceDevimon is a combined folder | Identify Devimon and IceDevimon by their actual sprites; use the separate IceDevimon entry where duplicated |
| GatomonBlackGatomon contains Gatomon artwork | Register Gatomon; do not invent an additional BlackGatomon sprite |
| Paradox metadata calls these combined folders species | Map the supplied primary poses to Paradox Devimon and Paradox Gatomon |
| SkullKnightmon is filed under Mega in the asset pack | Use its official Champion stage |
| Standard Thundermon is present; its Paradox art is absent | Include standard Thundermon; do not fabricate a Paradox counterpart |
| Gigadramon is present only in the Paradox parts | Include Paradox Gigadramon with its Cyber Sleuth base data and available Paradox routes |

“Complete” refers to the distinct usable forms actually supplied in these archives, not every Digimon ever published or every possible missing counterpart.
