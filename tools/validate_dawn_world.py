#!/usr/bin/env python3
"""Static integrity checks for the Dawn map import; no database is required."""
from __future__ import annotations
import hashlib
import json
from collections import deque
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / 'public_html'
STATIC = PUBLIC / 'html/static'


def require(ok, message):
    if not ok:
        raise AssertionError(message)


def load_world():
    return json.loads((PUBLIC / 'config/worlds/dawn_world.json').read_text())


def components_for(rows):
    """Independently flood-fill cardinal neighbors; runtime diagonals cannot cut walls."""
    height = len(rows)
    width = len(rows[0]) if height else 0
    todo = {y * width + x for y, row in enumerate(rows) for x, value in enumerate(row) if value == '1'}
    out = []
    while todo:
        start = min(todo)
        todo.remove(start)
        cells = {start}
        queue = deque([start])
        while queue:
            cell = queue.popleft()
            x, y = cell % width, cell // width
            for dx, dy in ((-1, 0), (1, 0), (0, -1), (0, 1)):
                nx, ny = x + dx, y + dy
                next_cell = ny * width + nx
                if 0 <= nx < width and 0 <= ny < height and next_cell in todo:
                    todo.remove(next_cell)
                    cells.add(next_cell)
                    queue.append(next_cell)
        out.append(cells)
    return sorted(out, key=len, reverse=True)


def validate_dawn_world():
    world = load_world()
    areas = world['areas']
    require(set(areas) == {f'dawn-{i:03}' for i in range(1, 265)}, 'All 264 uploaded Dawn map IDs must be playable')
    require(world['tile_size'] == 4 and world['display_tile_size'] == 8, 'Dawn maps must retain native 2x dimensions on a 4-pixel collision grid')
    audit = json.loads((ROOT / 'docs/verification/dawn_asset_audit.json').read_text())
    images = audit['source_images']
    require(len(images) == 351 and len({i['name'] for i in images}) == 351, 'Source inventory must cover all 351 supplied PNG layers exactly once')
    by_name = {i['name']: i for i in images}
    included = []
    for record in images:
        path = STATIC / record['asset']
        require(path.is_file(), f'Missing original layer {record["name"]}')
        require(hashlib.sha256(path.read_bytes()).hexdigest() == record['sha256'], f'Source layer bytes changed: {record["name"]}')
        with Image.open(path) as image:
            require(image.size == (record['width'], record['height']), f'Source layer dimensions changed: {record["name"]}')
    walkable_by_area = {}
    components_count = 0
    encounter_count = 0
    for key, area in areas.items():
        cols, rows = int(area['columns']), int(area['rows'])
        require(area['ready'] and area['tile_size'] == 4 and area['display_tile_size'] == 8, f'{key}: invalid ready/grid contract')
        path = STATIC / area['asset']
        with Image.open(path) as image:
            require(image.size == (cols * 8, rows * 8), f'{key}: image resampled or grid misaligned')
        source_layers = area['source_layers']
        require(len(source_layers) in (1, 2), f'{key}: missing or unexpected map layers')
        for layer in source_layers:
            require(layer['name'] in by_name, f'{key}: source absent from import inventory')
            require(layer['sha256'] == by_name[layer['name']]['sha256'], f'{key}: layer hash disagrees with audit')
            require(by_name[layer['name']]['map_id'] == int(area['map_id']), f'{key}: source belongs to another map')
            included.append(layer['name'])
        with Image.open(STATIC / area['thumbnail']) as thumb:
            require(thumb.size == (320, 200), f'{key}: invalid atlas thumbnail canvas')
        collision = json.loads((PUBLIC / area['collision_asset']).read_text())
        require(collision['columns'] == cols and collision['rows'] == rows, f'{key}: collision dimensions differ from map')
        floor = collision['walkable_rows']
        labels = collision['component_rows']
        require(len(floor) == rows and all(len(row) == cols and set(row) <= {'0', '1'} for row in floor), f'{key}: malformed binary collision grid')
        require(len(labels) == rows and all(len(row) == cols and set(row) <= set('0123456789') for row in labels), f'{key}: malformed component grid')
        computed = components_for(floor)
        declared = {int(c['id']): c for c in collision['components']}
        require(len(computed) == len(declared) and bool(computed), f'{key}: component count does not match flood-fill')
        components_count += len(computed)
        walkable = {cell for component in computed for cell in component}
        walkable_by_area[key] = walkable
        for cells in computed:
            label_ids = {int(labels[c // cols][c % cols]) for c in cells}
            require(len(label_ids) == 1 and 0 not in label_ids, f'{key}: connected floor splits across component IDs')
            component_id = next(iter(label_ids))
            require(component_id in declared and declared[component_id]['size'] == len(cells), f'{key}: component size is incorrect')
            sx, sy = map(int, declared[component_id]['spawn'])
            require((sy - 1) * cols + sx - 1 in cells, f'{key}: component spawn is unreachable')
            for x, y in declared[component_id].get('encounter_cells', []):
                require(1 <= x <= cols and 1 <= y <= rows and (y - 1) * cols + x - 1 in cells, f'{key}: component encounter is blocked or outside its floor')
        for y, row in enumerate(labels):
            for x, label in enumerate(row):
                require((label != '0') == (floor[y][x] == '1'), f'{key}: component label on blocked ground')
        for x, y in area['spawn_points']:
            require(1 <= x <= cols and 1 <= y <= rows and (y - 1) * cols + x - 1 in walkable, f'{key}: blocked or out-of-bounds default spawn')
        landings = area.get('landing_points', [])
        require({int(p['component']) for p in landings} == set(declared), f'{key}: some disconnected floor has no playable landing')
        require(len({p['id'] for p in landings}) == len(landings), f'{key}: duplicate landing IDs')
        for p in landings:
            x, y = int(p['x']), int(p['y'])
            require(1 <= x <= cols and 1 <= y <= rows and int(labels[y - 1][x - 1]) == int(p['component']), f'{key}: wrong landing component')
        encounter = collision.get('encounter', [])
        encounter_count += len(encounter)
        for x, y in encounter:
            require(1 <= x <= cols and 1 <= y <= rows and (y - 1) * cols + x - 1 in walkable, f'{key}: encounter ground is blocked')
        require(not area['encounter_profile'] or bool(encounter), f'{key}: battle area has no safe signal cells')
    require(sorted(included) == sorted(by_name), 'Map catalog must use every uploaded source layer once')
    for key, area in areas.items():
        cols, rows = int(area['columns']), int(area['rows'])
        links = {str(t['target_area']) for t in area.get('transitions', [])}
        require(links <= set(area['connections']), f'{key}: gate destination missing from navigation')
        for t in area.get('transitions', []):
            x, y = int(t['x']), int(t['y'])
            require(1 <= x <= cols and 1 <= y <= rows and (y - 1) * cols + x - 1 in walkable_by_area[key], f'{key}: gate source is blocked')
            destination = str(t['target_area'])
            require(destination in areas, f'{key}: gate points to a missing map')
            target = areas[destination]
            tx, ty = int(t['target_x']), int(t['target_y'])
            require(1 <= tx <= target['columns'] and 1 <= ty <= target['rows'] and (ty - 1) * target['columns'] + tx - 1 in walkable_by_area[destination], f'{key}: gate arrival is blocked')
            require(key in {r['target_area'] for r in target.get('transitions', [])}, f'{key}: gate to {destination} has no return route')
            require(all((tx, ty) != (r['x'], r['y']) for r in target.get('transitions', [])), f'{key}: arrival on another gate causes a teleport loop')
    start = world.get('default_area', 'dawn-001')
    reached = {start}
    queue = deque([start])
    while queue:
        key = queue.popleft()
        for target in areas[key]['connections']:
            require(target in areas, f'{key}: nonexistent navigation destination')
            if target not in reached:
                reached.add(target)
                queue.append(target)
    require(reached == set(areas), f'{len(areas) - len(reached)} maps cannot be reached from the default hub')
    require(not (STATIC / 'images/worlds/cyberspace').exists(), 'Old generated playable map directory remains in release')
    return {'maps': len(areas), 'original_layers': len(images), 'walkable_components': components_count, 'encounter_cells': encounter_count}


if __name__ == '__main__':
    print(json.dumps(validate_dawn_world(), indent=2))
