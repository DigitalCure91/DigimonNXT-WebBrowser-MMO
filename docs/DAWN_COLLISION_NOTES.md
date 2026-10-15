# Dawn collision conversion

The playable geometry comes from the supplied US Dawn ROM's original collision files, not from colour or brightness guesses on the upscaled images.

## Source format and alignment

Each `/dat/map/<source_id>.0t` file is decoded with the existing DS LZ/RLE reader. Its first eight bytes are two little-endian 32-bit integers: native width and height. The remaining bytes contain exactly one collision bit per native pixel, in row order. The least significant bit represents the leftmost pixel in each byte. A set bit blocks movement; a clear bit represents floor.

The uploaded images are displayed at their supplied resolution, exactly twice the source graphics dimensions. The movement grid uses four original pixels per cell and therefore eight uploaded image pixels per cell. A cell is open only when **all 16 source pixels are open**. This conservative conversion preserves walls and map edges, including blockers narrower than one grid cell. Both the browser and server also prevent diagonal corner cutting.

Map 088 is the only canvas exception. Its graphics are 752 × 384 original pixels, while its collision file is 768 × 384. The converter reads every collision row with its original 96-byte stride and discards the invisible rightmost 16 pixels. All 768 bytes in that discarded strip are `0xff` (blocked). No walkable floor is removed. The supplied image remains 1504 × 768 pixels.

The ten supplied maps numbered 131–140 use their `b` artwork layer as their available visual map. They use the matching original collision files and the same scale conversion.

## Traversable sections

The 264 maps contain 481 connected floor sections. Connectivity uses four cardinal neighbours, which is equivalent to the runtime's eight directions when diagonal corner cutting is forbidden. Each component receives a safe landing point in its interior and encounter candidates on verified floor. Component labels permit constant-time checks that a player and a wild encounter are on the same floor section.

The source's moving platforms, ladders, scripted event conditions and original story warp engine are not decoded by this tool. NXT's travel and section-entry controls connect the available sections without opening holes through the source collision. The map pack does not claim to reproduce Dawn's original story progression.

## Evidence and regeneration

`DAWN_COLLISION_AUDIT.json` records the ROM hash, decoded source collision hash, generated JSON hash, geometry dimensions and section counts for every map. `tools/extract_dawn_world.py` reproduces the collision files from the user's source ROM and uploaded map directory:

```text
python tools/extract_dawn_world.py /path/to/Dawn.nds --maps /path/to/uploaded/maps
```

Visual collision overlays were checked against platform maps 001 and 150, narrow bridges in 010, island/coast 053, wooded terrain 070, clipped canvas 088, cliffs 095, the alternate visual layers in 131 and 140, and interior walls in 260. The grid preserves the narrow bridges in 010 that an eight-original-pixel grid would fragment.

No complete ROM file is included in the game source release.
