#!/usr/bin/env python3
"""Extract conservative playable collision from a user-supplied US Dawn ROM.

No ROM is bundled. The original /dat/map/<id>.0t files are DS-compressed data:
uint32 little-endian pixel width and height, then row-major, little-bit-first
collision bits. One means blocked; zero means floor. A browser grid cell is
walkable only when ALL its native pixels are floor. This preserves walls and
void boundaries instead of inferring geometry from artwork brightness.

Run from anywhere:
  python tools/extract_dawn_world.py Dawn.nds --maps /path/to/uploaded/maps

The supplied upscaled artwork must match the original canvas at exactly 2x.
This extracts collision, not the original event engine/story warp scripts.
"""
from __future__ import annotations

import argparse
from collections import deque
import hashlib
import json
from pathlib import Path
import struct

from PIL import Image
from extract_dawn_graphics import Rom

NATIVE_CELL = 4
DISPLAY_SCALE = 2
MOVES = ((0, -1), (1, 0), (0, 1), (-1, 0))


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def collision_grid(data: bytes) -> tuple[int, int, list[list[bool]]]:
    if len(data) < 8:
        raise ValueError('Missing collision dimensions')
    width, height = struct.unpack_from('<II', data)
    if not (0 < width <= 4096 and 0 < height <= 4096):
        raise ValueError('Collision dimensions exceed supported bounds')
    if width % 8 or height % NATIVE_CELL:
        raise ValueError('Collision canvas is not aligned to the original pixel grid')
    if len(data) != 8 + width * height // 8:
        raise ValueError('Collision size does not match one blocked bit per pixel')
    stride = width // 8
    columns, rows = width // NATIVE_CELL, height // NATIVE_CELL
    grid: list[list[bool]] = []
    for y in range(rows):
        row: list[bool] = []
        for x in range(columns):
            byte_x = (x * NATIVE_CELL) // 8
            bit_mask = 0x0f << ((x * NATIVE_CELL) % 8)
            blocked = any(data[8 + (y * NATIVE_CELL + dy) * stride + byte_x] & bit_mask
                          for dy in range(NATIVE_CELL))
            row.append(not blocked)
        grid.append(row)
    return width, height, grid


def components_for(grid: list[list[bool]]) -> tuple[list[list[tuple[int, int]]], list[list[int]]]:
    rows, columns = len(grid), len(grid[0])
    seen: set[tuple[int, int]] = set()
    components: list[list[tuple[int, int]]] = []
    for y in range(rows):
        for x in range(columns):
            if not grid[y][x] or (x, y) in seen:
                continue
            pending = deque([(x, y)])
            seen.add((x, y))
            cells: list[tuple[int, int]] = []
            while pending:
                px, py = pending.popleft()
                cells.append((px, py))
                for dx, dy in MOVES:
                    nx, ny = px + dx, py + dy
                    if 0 <= nx < columns and 0 <= ny < rows and grid[ny][nx] and (nx, ny) not in seen:
                        seen.add((nx, ny))
                        pending.append((nx, ny))
            components.append(cells)
    components.sort(key=lambda cells: (-len(cells), min((y, x) for x, y in cells)))
    if len(components) > 9:
        raise ValueError('Component string encoding supports at most nine components')
    labels = [[0] * columns for _ in range(rows)]
    for label, cells in enumerate(components, 1):
        for x, y in cells:
            labels[y][x] = label
    return components, labels


def clearance_for(grid: list[list[bool]]) -> list[list[int]]:
    rows, columns = len(grid), len(grid[0])
    distance = [[0] * columns for _ in range(rows)]
    pending: deque[tuple[int, int]] = deque()
    for y in range(rows):
        for x in range(columns):
            if grid[y][x] and any(not (0 <= x + dx < columns and 0 <= y + dy < rows)
                                 or not grid[y + dy][x + dx] for dx, dy in MOVES):
                distance[y][x] = 1
                pending.append((x, y))
    while pending:
        x, y = pending.popleft()
        for dx, dy in MOVES:
            nx, ny = x + dx, y + dy
            if 0 <= nx < columns and 0 <= ny < rows and grid[ny][nx] and distance[ny][nx] == 0:
                distance[ny][nx] = distance[y][x] + 1
                pending.append((nx, ny))
    return distance


def derive_map(rom: Rom, map_id: int, maps_dir: Path | None = None) -> dict:
    source_path = f'/dat/map/{map_id}.0t'
    raw = rom.read(source_path)
    data = rom.read(source_path, True)
    source_width, source_height, grid = collision_grid(data)
    width, height = struct.unpack_from('<HH', rom.read(f'/dat/map/{map_id}a.s', True))
    if (width, height) != (source_width, source_height):
        # Source map 88 has a 752px visual canvas inside its 768px collision
        # canvas. Crop the invisible right strip AFTER reading with the real
        # 96-byte source row stride. Never scale or reinterpret source bits.
        if (map_id, source_width, source_height, width, height) != (88, 768, 384, 752, 384):
            raise ValueError(f'Map {map_id}: unrecognized graphic/collision canvas difference')
        grid = [row[:width // NATIVE_CELL] for row in grid[:height // NATIVE_CELL]]
    if maps_dir is not None:
        path = maps_dir / f'map_{map_id:03d}_a.png'
        if not path.is_file():
            path = maps_dir / f'map_{map_id:03d}_b.png'
        with Image.open(path) as supplied:
            if supplied.size != (width * DISPLAY_SCALE, height * DISPLAY_SCALE):
                raise ValueError(f'Map {map_id}: uploaded canvas differs from 2x original')
    cells_by_component, labels = components_for(grid)
    if not cells_by_component:
        raise ValueError(f'Map {map_id}: no safe floor remains')
    clearance = clearance_for(grid)
    components = []
    all_encounters: list[list[int]] = []
    for component_id, cells in enumerate(cells_by_component, 1):
        center_x = sum(x for x, _ in cells) / len(cells)
        center_y = sum(y for _, y in cells) / len(cells)
        # Prefer room interiors with space for a human sprite and the gateway.
        sx, sy = min(cells, key=lambda p: (-clearance[p[1]][p[0]],
                                         (p[0] - center_x) ** 2 + (p[1] - center_y) ** 2,
                                         p[1], p[0]))
        encounters = [[x + 1, y + 1] for x, y in sorted(cells, key=lambda p: (p[1], p[0]))
                      if x % 4 == 2 and y % 4 == 2 and clearance[y][x] >= 2
                      and max(abs(x - sx), abs(y - sy)) >= 6]
        # Every real component has useful floor; a fallback stays on verified
        # collision when an unusually narrow room misses the sampling lattice.
        if not encounters:
            candidates = sorted(cells, key=lambda p: (-max(abs(p[0] - sx), abs(p[1] - sy)),
                                                      -clearance[p[1]][p[0]], p[1], p[0]))
            encounters = [[x + 1, y + 1] for x, y in candidates[:8]
                          if max(abs(x - sx), abs(y - sy)) >= 3]
        component = {
            'id': component_id,
            'size': len(cells),
            'spawn': [sx + 1, sy + 1],
            'bounds': [min(x for x, _ in cells) + 1, min(y for _, y in cells) + 1,
                       max(x for x, _ in cells) + 1, max(y for _, y in cells) + 1],
            'spawn_clearance_cells': clearance[sy][sx],
            'encounter_cells': encounters,
        }
        components.append(component)
        all_encounters.extend(encounters)
    return {
        'schema': 1,
        'source_id': map_id,
        'source': {
            'file': source_path,
            'compressed_sha256': digest(raw),
            'decoded_sha256': digest(data),
            'encoding': 'uint32le dimensions; row-major blocked bits, little-bit-first',
        },
        'native_width': width,
        'native_height': height,
        'source_collision_width': source_width,
        'source_collision_height': source_height,
        'collision_crop_right': source_width - width,
        'collision_crop_bottom': source_height - height,
        'tile_size': NATIVE_CELL,
        'display_tile_size': NATIVE_CELL * DISPLAY_SCALE,
        'columns': width // NATIVE_CELL,
        'rows': height // NATIVE_CELL,
        'walkable_rows': [''.join('1' if value else '0' for value in row) for row in grid],
        'component_rows': [''.join(str(value) for value in row) for row in labels],
        'spawn_points': [component['spawn'] for component in components],
        'components': components,
        'encounter': all_encounters,
        'walkable_count': sum(map(len, cells_by_component)),
        'method': 'A cell is open only if every one of its 16 original collision pixels is open. '
                  'Components use cardinal adjacency, matching diagonal corner restrictions.',
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('rom', type=Path)
    parser.add_argument('--maps', type=Path, help='Folder containing the supplied map_NNN_a.png images')
    parser.add_argument('--project', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--output', type=Path, help='Override collision JSON folder')
    parser.add_argument('--audit', type=Path, help='Override audit JSON path')
    args = parser.parse_args()
    rom = Rom(args.rom)
    output = args.output or args.project / 'public_html/config/worlds/dawn_collision'
    audit_path = args.audit or args.project / 'docs/DAWN_COLLISION_AUDIT.json'
    output.mkdir(parents=True, exist_ok=True)
    audit_path.parent.mkdir(parents=True, exist_ok=True)
    map_ids = sorted({int(path.stem.split('_')[1]) for path in args.maps.glob('map_*_*.png')}) if args.maps else list(range(1, 265))
    if map_ids != list(range(1, 265)):
        raise ValueError('Expected the complete supplied map set, source IDs 1 through 264')
    audit = {
        'schema': 1,
        'rom_sha256': rom.sha256,
        'source_game_code': 'A3VE',
        'map_count': len(map_ids),
        'native_cell_size': NATIVE_CELL,
        'display_scale': DISPLAY_SCALE,
        'collision_basis': 'Original ROM collision pixels; no artwork colour/brightness classification',
        'blocked_polarity': 1,
        'bit_order': 'least significant bit first',
        'all_pixels_must_be_open': True,
        'story_events_and_warps_decoded': False,
        'maps': [],
    }
    for map_id in map_ids:
        record = derive_map(rom, map_id, args.maps)
        target = output / f'dawn-{map_id:03d}.json'
        encoded = (json.dumps(record, separators=(',', ':'), ensure_ascii=False) + '\n').encode()
        target.write_bytes(encoded)
        audit['maps'].append({
            'source_id': map_id,
            'native_width': record['native_width'],
            'native_height': record['native_height'],
            'source_collision_width': record['source_collision_width'],
            'collision_crop_right': record['collision_crop_right'],
            'columns': record['columns'], 'rows': record['rows'],
            'walkable_cells': record['walkable_count'],
            'components': len(record['components']),
            'encounter_candidates': len(record['encounter']),
            'source_sha256': record['source']['decoded_sha256'],
            'collision_sha256': digest(encoded),
        })
    audit['total_components'] = sum(row['components'] for row in audit['maps'])
    audit['total_walkable_cells'] = sum(row['walkable_cells'] for row in audit['maps'])
    audit_path.write_text(json.dumps(audit, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({k: audit[k] for k in ('map_count', 'total_components', 'total_walkable_cells')}))


if __name__ == '__main__':
    main()
