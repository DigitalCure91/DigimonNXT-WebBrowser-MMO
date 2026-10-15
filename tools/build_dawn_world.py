#!/usr/bin/env python3
"""Package the supplied Dawn map layers at their exact pixel dimensions.

Requires Pillow. First run extract_dawn_world.py against the supplied ROM.
Then: python tools/build_dawn_world.py --maps-dir /path/to/extracted/maps

No map texture is generated or resized. Single-layer images remain byte-for-byte
identical; two-layer images use ordinary alpha composition, A then B. Unchanged
source layers remain in the package and the audit records every input SHA-256.
Thumbnails are the only resized assets. Map names, grouping and the reciprocal
travel routes are NXT presentation, not claims about Dawn story warp tables.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import shutil
from collections import defaultdict
from pathlib import Path

from PIL import Image

# Bounds, key, visible region name, category, encounter profile, event group.
REGIONS = [
    (1, 9, 'cyan-terminal', 'Cyan Terminal', 'Digital plaza', 'cyber-route', ''),
    (10, 11, 'garden-isles', 'Garden Isles', 'Island terraces', 'dataflow', 'trails'),
    (12, 22, 'sunlit-sanctuaries', 'Sunlit Sanctuaries', 'Tamer habitats', 'cyber-route', ''),
    (23, 31, 'violet-terminal', 'Violet Terminal', 'Digital plaza', 'dataflow', ''),
    (32, 33, 'garden-highlands', 'Garden Highlands', 'Island terraces', 'dataflow', 'trails'),
    (34, 44, 'twilight-sanctuaries', 'Twilight Sanctuaries', 'Tamer habitats', 'cyber-route', ''),
    (45, 52, 'data-citadel', 'Data Citadel', 'Digital chambers', 'geargrid', ''),
    (53, 69, 'habitat-islands', 'Habitat Islands', 'Habitat routes', 'cyber-route', 'trails'),
    (70, 77, 'woodland-trails', 'Woodland Trails', 'Forest paths', 'cyber-route', 'trails'),
    (78, 86, 'desert-terraces', 'Desert Terraces', 'Desert paths', 'overclock', 'trails'),
    (87, 94, 'stone-ruins', 'Stone Ruins', 'Ancient ruins', 'overclock', ''),
    (95, 102, 'forest-cliffs', 'Forest Cliffs', 'Wooded highlands', 'dataflow', 'trails'),
    (103, 110, 'high-quarry', 'High Quarry', 'Stone bridges', 'overclock', ''),
    (111, 121, 'machine-works', 'Machine Works', 'Machine halls', 'geargrid', ''),
    (122, 130, 'tidal-channels', 'Tidal Channels', 'Water channels', 'node-link', ''),
    (131, 140, 'jungle-crossings', 'Jungle Crossings', 'Jungle paths', 'dataflow', 'trails'),
    (141, 151, 'rose-canyon', 'Rose Canyon', 'Canyon paths', 'encrypt-field', ''),
    (152, 162, 'data-facility', 'Data Facility', 'Machine halls', 'geargrid', ''),
    (163, 174, 'frozen-reaches', 'Frozen Reaches', 'Ice passages', 'icewall', 'frozen'),
    (175, 181, 'coral-routes', 'Coral Routes', 'Reef passages', 'node-link', ''),
    (182, 194, 'coastal-bridges', 'Coastal Bridges', 'Coastal paths', 'node-link', ''),
    (195, 204, 'meadow-channels', 'Meadow Channels', 'Meadow paths', 'dataflow', 'trails'),
    (205, 215, 'magma-reaches', 'Magma Reaches', 'Volcanic paths', 'firewall', ''),
    (216, 231, 'deep-network', 'Deep Network', 'Deep data routes', 'mainframe', 'deep'),
    (232, 235, 'moon-quarry', 'Moon Quarry', 'Twilight stone paths', 'the-core', 'deep'),
    (236, 239, 'twilight-forest', 'Twilight Forest', 'Twilight highlands', 'encrypt-field', 'trails'),
    (240, 243, 'dusk-islands', 'Dusk Islands', 'Twilight coast', 'node-link', ''),
    (244, 248, 'shadow-halls', 'Shadow Halls', 'Ancient chambers', 'the-core', 'deep'),
    (249, 256, 'crystal-wastes', 'Crystal Wastes', 'Crystal paths', 'icewall', 'frozen'),
    (257, 264, 'lost-citadel', 'Lost Citadel', 'Deep chambers', 'the-core', 'deep'),
]
PROFILE_MUSIC = dict(zip(
    ['cyber-route', 'dataflow', 'overclock', 'icewall', 'geargrid', 'encrypt-field', 'node-link', 'firewall', 'mainframe', 'the-core'],
    [f'bgm{i}' for i in range(11, 21)]))
LEGACY_ALIASES = dict(zip(
    ['terminal-plaza', 'packet-meadow', 'cache-grove', 'proxy-path', 'firewall-ridge', 'memory-sector', 'data-stream-delta', 'server-ruins', 'kernel-garden', 'core-nexus', 'the-core'],
    [f'dawn-{i:03d}' for i in [1, 70, 95, 78, 163, 111, 141, 182, 205, 217, 257]]))
LEGACY_IMAGES = ['plaza-terminal', 'cyber-route', 'dataflow', 'overclock', 'icewall', 'geargrid', 'encrypt-field', 'node-link', 'firewall', 'mainframe', 'the-core']


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_json(path: Path, value: object, pretty=False) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2 if pretty else None, separators=None if pretty else (',', ':')) + '\n', encoding='utf-8')


def gate_cells(collision: dict, count: int) -> list[list[int]]:
    """Choose clear gate cells in the primary connected component, away from entry."""
    rows = collision['component_rows']
    spawn = collision['spawn_points'][0]
    primary = str(collision['components'][0]['id'])
    width, height = collision['columns'], collision['rows']
    candidates = []
    for y in range(2, height):
        for x in range(2, width):
            if all(rows[ny - 1][nx - 1] == primary for nx, ny in [(x, y), (x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1)]):
                if math.hypot(x - spawn[0], y - spawn[1]) >= 9:
                    candidates.append([x, y])
    if len(candidates) < count:
        raise ValueError(f'Not enough clear gate cells: map {collision["source_id"]}')
    selected = []
    for _ in range(count):
        # Farthest-point sampling spreads gates; ties are stable for reproducibility.
        p = max(candidates, key=lambda q: min((q[0]-v[0])**2+(q[1]-v[1])**2 for v in [spawn] + selected))
        selected.append(p)
        candidates = [q for q in candidates if math.hypot(q[0]-p[0], q[1]-p[1]) >= 5]
        if len(selected) < count and not candidates:
            raise ValueError('Gates would overlap')
    return selected


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--maps-dir', type=Path, required=True)
    parser.add_argument('--project-root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--keep-legacy', action='store_true', help='Keep retired Cyberspace art for development only')
    args = parser.parse_args()
    project = args.project_root.resolve()
    public = project / 'public_html'
    static = public / 'html/static'
    out = static / 'images/worlds/dawn'
    (out / 'layers').mkdir(parents=True, exist_ok=True)
    (out / 'thumbs').mkdir(parents=True, exist_ok=True)
    sources = defaultdict(dict)
    for path in sorted(args.maps_dir.rglob('map_*_*.png')):
        parts = path.stem.split('_')
        if len(parts) != 3 or parts[2] not in ['a', 'b']:
            raise ValueError(f'Unexpected layer filename {path.name}')
        map_id = int(parts[1])
        if parts[2] in sources[map_id]:
            raise ValueError(f'Duplicate layer {path.name}')
        sources[map_id][parts[2]] = path
    if sorted(sources) != list(range(1, 265)) or sum(map(len, sources.values())) != 351:
        raise ValueError('Expected exactly 351 source layers covering map IDs 001–264')
    profile_php = (public / 'config/worlds/cyberspace_encounters.php').read_text(encoding='utf-8')
    profiles = json.loads(profile_php.split("JSON'\n", 1)[1].split('\nJSON,', 1)[0])

    # Region chains with their first maps connected in a ring: max 3 gates/map.
    neighbours = defaultdict(set)
    heads = [r[0] for r in REGIONS]
    for lo, hi, *_ in REGIONS:
        for mid in range(lo, hi):
            neighbours[mid].add(mid+1)
            neighbours[mid+1].add(mid)
    for a, b in zip(heads, heads[1:] + heads[:1]):
        neighbours[a].add(b)
        neighbours[b].add(a)

    collisions = {}
    for mid in sources:
        path = public / f'config/worlds/dawn_collision/dawn-{mid:03d}.json'
        value = json.loads(path.read_text(encoding='utf-8'))
        if value['source_id'] != mid or value['tile_size'] != 4 or value['display_tile_size'] != 8:
            raise ValueError(f'Unexpected collision metadata {path}')
        if not value['components']:
            raise ValueError(f'No reachable entry for map {mid}')
        collisions[mid] = value

    areas, source_audit, map_audit = {}, [], []
    for mid, layers in sorted(sources.items()):
        key, stem = f'dawn-{mid:03d}', f'map_{mid:03d}'
        definition = next(r for r in REGIONS if r[0] <= mid <= r[1])
        lo, hi, region_key, region, category, profile, event_group = definition
        collision = collisions[mid]
        image_paths = list(layers.values())
        with Image.open(image_paths[0]) as first:
            size = first.size
        if size != (collision['columns'] * 8, collision['rows'] * 8):
            raise ValueError(f'Uploaded pixels do not align to ROM collision: {mid}, {size}')
        rendered = out / f'{stem}.png'
        layer_records = []
        composition = 'single-layer-byte-copy'
        if len(layers) == 1:
            shutil.copyfile(image_paths[0], rendered)
        else:
            composition = 'alpha-over:A-then-B; no-resampling'
            composite = Image.new('RGBA', size)
            for letter, path in sorted(layers.items()):
                with Image.open(path) as layer:
                    if layer.size != size:
                        raise ValueError(f'Layer size mismatch {path.name}')
                    composite = Image.alpha_composite(composite, layer.convert('RGBA'))
            composite.save(rendered, optimize=True)
        for letter, path in sorted(layers.items()):
            packaged = rendered if len(layers) == 1 else out / 'layers' / path.name
            if len(layers) > 1:
                shutil.copyfile(path, packaged)
            record = {'name': path.name, 'map_id': mid, 'layer': letter, 'width': size[0], 'height': size[1], 'sha256': sha(path), 'asset': packaged.relative_to(static).as_posix()}
            if sha(packaged) != record['sha256']:
                raise ValueError(f'Source layer changed: {path}')
            source_audit.append(record)
            layer_records.append({k: record[k] for k in ['name', 'asset', 'sha256', 'layer']})

        with Image.open(rendered) as thumb_source:
            thumb_source = thumb_source.convert('RGBA')
            thumb_source.thumbnail((320, 200), Image.Resampling.LANCZOS)
            thumb = Image.new('RGB', (320, 200), '#071524')
            thumb.paste(thumb_source, ((320-thumb_source.width)//2, (200-thumb_source.height)//2), thumb_source)
            thumb.save(out / 'thumbs' / f'{stem}.webp', quality=84, method=6)

        landings = [{'id': c['id'], 'label': 'Main landing' if n == 0 else f'Landing {n+1}', 'x': c['spawn'][0], 'y': c['spawn'][1], 'component': c['id'], 'cells': c['size']} for n, c in enumerate(collision['components'])]
        targets = sorted(neighbours[mid])
        gates = gate_cells(collision, len(targets))
        transitions = []
        for target, (gx, gy) in zip(targets, gates):
            tx, ty = collisions[target]['spawn_points'][0]
            transitions.append({'x': gx, 'y': gy, 'target_world': 'cyberspace', 'target_area': f'dawn-{target:03d}', 'target_x': tx, 'target_y': ty, 'label': f'Dawn Area {target:03d}'})
        if mid == 1:
            profile, event_group = '', ''
        encounters = profiles[profile]['entries'] if profile else []
        level_min = min((int(e[1]) for e in encounters), default=0)
        level_max = max((int(e[2]) for e in encounters), default=0)
        entry = {'name': f'Dawn Area {mid:03d}', 'map_id': mid, 'region': region, 'region_key': region_key, 'category': category,
                 'description': ('A quiet landing in the Cyan Terminal. Choose a destination in the atlas or follow a travel gate.' if mid == 1 else f'Explore {region.lower()} with your partners. Travel gates connect nearby NXT destinations.'),
                 'asset': f'images/worlds/dawn/{stem}.png', 'thumbnail': f'images/worlds/dawn/thumbs/{stem}.webp',
                 'source_layers': layer_records, 'width': size[0], 'height': size[1], 'tile_size': 4, 'display_tile_size': 8,
                 'columns': collision['columns'], 'rows': collision['rows'], 'spawn_points': collision['spawn_points'],
                 'landing_points': landings, 'components': [{k: c[k] for k in ['id', 'size', 'spawn', 'bounds']} for c in collision['components']],
                 'collision_asset': f'config/worlds/dawn_collision/{key}.json', 'ready': True, 'connections': [f'dawn-{t:03d}' for t in targets],
                 'transitions': transitions, 'encounter_profile': profile, 'event_group': event_group,
                 'level_min': level_min, 'level_max': level_max, 'encounter_species_count': len(encounters),
                 'music_track': 'bgm10' if mid == 1 else PROFILE_MUSIC[profile], 'music': 'bgm10' if mid == 1 else PROFILE_MUSIC[profile],
                 'actor_width': 32, 'interaction_range': 8}
        areas[key] = entry
        map_audit.append({'key': key, 'map_id': mid, 'width': size[0], 'height': size[1], 'source_layers': [p.name for p in image_paths],
                          'composition': composition, 'asset': entry['asset'], 'composite_sha256': sha(rendered),
                          'collision_asset': entry['collision_asset'], 'collision_sha256': sha(public / entry['collision_asset']),
                          'walkable_cells': sum(c['size'] for c in collision['components']), 'components': len(collision['components']), 'landing_points': landings})
        print(f'{key}: {size[0]}x{size[1]}, {len(layers)} layer(s), {len(landings)} landing(s)', flush=True)

    manifest = {'key': 'cyberspace', 'label': 'Dawn World', 'subtitle': 'DIGITAL WORLD // DAWN ATLAS',
                'description': '264 Dawn maps at the supplied resolution, with visible wild Digimon, roaming tamers and connected travel gates.',
                'revision': 'dawn-2026-09-22-1', 'default_area': 'dawn-001', 'tile_size': 4, 'display_tile_size': 8,
                'source_layer_count': 351, 'map_count': 264, 'region_count': len(REGIONS),
                'travel_topology': 'NXT region chains with region entry maps connected in a ring; not original story warps.',
                'regions': [{'key': r[2], 'name': r[3], 'first_area': f'dawn-{r[0]:03d}', 'count': r[1]-r[0]+1} for r in REGIONS],
                'legacy_aliases': LEGACY_ALIASES, 'areas': areas}
    write_json(public / 'config/worlds/dawn_world.json', manifest)
    (public / 'config/worlds/cyberspace.php').write_text("<?php\ndeclare(strict_types=1);\n// Retain the world key for existing bookmarks and durable account positions.\nreturn json_decode((string)file_get_contents(__DIR__.'/dawn_world.json'), true, 512, JSON_THROW_ON_ERROR);\n", encoding='utf-8')
    audit = {'schema': 1, 'source_archive': 'DigimonDawnAllLevelMaps.7z', 'source_layer_count': len(source_audit), 'map_count': len(areas),
             'a_layer_count': sum(1 for r in source_audit if r['layer'] == 'a'), 'b_layer_count': sum(1 for r in source_audit if r['layer'] == 'b'),
             'paired_map_count': sum(1 for layers in sources.values() if len(layers) == 2), 'single_layer_map_count': sum(1 for layers in sources.values() if len(layers) == 1),
             'source_id_range': [1, 264], 'region_count': len(REGIONS), 'layer_order': ['a', 'b'],
             'pixel_policy': 'Full map images retain uploaded pixel dimensions. Single layers are byte-identical. Paired layers are standard alpha composites with no resampling. Only atlas thumbnails are resized.',
             'collision_policy': 'Authentic supplied Dawn ROM packed collision, sampled conservatively into a 4-native-pixel (8 supplied-image-pixel) grid; see docs/DAWN_COLLISION_AUDIT.json.',
             'names_and_routes': 'Numeric source map IDs retained. Region descriptions and travel links are NXT design, not a reconstructed Dawn story graph.',
             'source_images': source_audit, 'maps': map_audit}
    write_json(project / 'docs/verification/dawn_asset_audit.json', audit, pretty=True)
    if not args.keep_legacy:
        for name in LEGACY_IMAGES:
            old = static / 'images/worlds/cyberspace' / f'{name}.png'
            if old.exists():
                old.unlink()
        old_collisions = public / 'config/worlds/cyberspace_collision'
        for key in LEGACY_ALIASES:
            old = old_collisions / f'{key}.json'
            if old.exists():
                old.unlink()
        for directory in [static / 'images/worlds/cyberspace', old_collisions]:
            if directory.exists() and not any(directory.iterdir()):
                directory.rmdir()
        old_thumbnails = public / 'assets/ui/sectors'
        if old_thumbnails.exists():
            for old in old_thumbnails.iterdir():
                if old.is_file() and old.stem in LEGACY_IMAGES + list(LEGACY_ALIASES):
                    old.unlink()
            if not any(old_thumbnails.iterdir()):
                old_thumbnails.rmdir()
    print(f'Packaged {len(areas)} maps, {len(source_audit)} preserved source layers and {len(REGIONS)} regions.')


if __name__ == '__main__':
    main()
