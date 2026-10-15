# Data sources, attribution and adaptations

## Cyber Sleuth data

The factual species stats, learning schedules, move parameters and evolution prerequisites were derived from [Liane Brisebois's Digimon Cyber Sleuth dataset](https://www.kaggle.com/datasets/lianebrisebois/digimon-cyber-sleuth-dataset). Its published license is **Creative Commons Attribution–NonCommercial–ShareAlike 4.0**: [license terms](https://creativecommons.org/licenses/by-nc-sa/4.0/). The derived game-data JSON retains that license where applicable. This attribution does not assign a new license to the supplied artwork or existing project code.

Changes include name/slug aliases, extraction of numeric combat effects, a subset limited to supplied forms, stat interpolation, NXT extensions, and Paradox copies. Source CSV typo corrections include Commet/Comet Hammer II and ChronoBreaker/Chrono Breaker. The Grademon → Dorugamon source row is treated as the already-derived reverse route, not an upward evolution. Mode-change rows are kept separate from regular evolutions. Standard definitions are used when source move names are repeated for alternate editions/variants.

There are 280 covered standard forms. Paradox copies use these profiles and the Paradox-only Gigadramon uses the dataset's Gigadramon profile. The runtime does not scrape or contact Kaggle.

## ABI reference

ABI algorithms follow the author's tested tables in [Karyu Endan's Cyber Sleuth / Hacker's Memory ABI guide](https://docs.google.com/document/d/1MuXfIknTn0ukGDXIPQ8ombRNIK1ioPgRkR4abKAwqtY/edit), linked from the [original explanation](https://www.reddit.com/r/CyberSleuth/comments/b822px/how_evolution_affects_abi_in_detail/). Free mode changes preserve level and ABI, as explained in the [mode-change discussion](https://www.reddit.com/r/CyberSleuth/comments/p1agpu/does_mode_change_increase_abi_or_am_i_being_stupid/).

The code implements the corrected Rookie offset in the later guide, including the level-5 threshold. It also implements Armor, same-stage, stage-skip and DNA cases. The table arithmetic is tested at levels 1–99. NXT's ABI maximum is 200, matching the later Cyber Sleuth / Hacker's Memory ruleset.

## Digimon outside Cyber Sleuth

The [official Digimon Encyclopedia](https://digimon.net/reference_en/) supplies special-move names, stage, type and attribute for the 218 additional standard Digimon. Each relevant catalog record links directly to its official profile. No encyclopedia profile prose is bundled.

These forms have NXT-balanced stat curves, signature parameters and inherited learning schedules; they are **not described as having nonexistent Cyber Sleuth learnsets**. The 267 standard NXT evolution branches are explicitly marked in route data and in the DigiLab. Most follow recognizable supplied families; some are available-asset alternatives when an intermediate or DNA parent is missing. The source list is `tools/data/nxt_evolution_chains.txt`. These branches are game design choices, not a claimed official evolution chart.

The two EX-Eraser characters in the supplied assets are bonus digital entities, not Cyber Sleuth Digimon. Their move profiles are explicitly authored for NXT. Paradox is a supplied visual variant and an NXT game mechanic; no official Cyber Sleuth Paradox statistics are claimed.

Other NXT adaptations: single-active wild combat; interpolated level 1/50/99 stats; direct allocation of ABI training points; simplified status/combo timing; unlimited access to learned inherited moves instead of the original game's loadout UI; existing cosmetic personalities; revised EXP pacing; target discovery in place of unimplemented story/egg/spirit gates; and the original automatic arena system.

The feature is an implementation in the existing browser game, not an exact recreation of every Cyber Sleuth battle, DigiFarm, personality, memory, equipment or story system.
