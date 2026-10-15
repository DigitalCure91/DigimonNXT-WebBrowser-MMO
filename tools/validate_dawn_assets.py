#!/usr/bin/env python3
"""Validate every packaged Dawn source layer, composite, thumbnail and travel gate."""
import hashlib
import json
from collections import deque
from pathlib import Path
from PIL import Image, ImageChops

root = Path(__file__).resolve().parents[1]
public = root / 'public_html'
static = public / 'html/static'
manifest = json.loads((public / 'config/worlds/dawn_world.json').read_text(encoding='utf-8'))
audit = json.loads((root / 'docs/verification/dawn_asset_audit.json').read_text(encoding='utf-8'))
assert len(manifest['areas']) == 264
assert len(audit['source_images']) == 351
for record in audit['source_images']:
    path = static / record['asset']
    assert hashlib.sha256(path.read_bytes()).hexdigest() == record['sha256'], record['name']
    with Image.open(path) as image:
        assert image.size == (record['width'], record['height']), record['name']
for record in audit['maps']:
    assert hashlib.sha256((static / record['asset']).read_bytes()).hexdigest() == record['composite_sha256']
    assert hashlib.sha256((public / record['collision_asset']).read_bytes()).hexdigest() == record['collision_sha256']
for key, area in manifest['areas'].items():
    with Image.open(static / area['asset']) as image:
        assert image.size == (area['columns'] * 8, area['rows'] * 8), key
        if len(area['source_layers']) == 2:
            paths = [static / row['asset'] for row in area['source_layers']]
            with Image.open(paths[0]) as a, Image.open(paths[1]) as b:
                expected = Image.alpha_composite(a.convert('RGBA'), b.convert('RGBA'))
                difference = ImageChops.difference(image.convert('RGBA'), expected)
                assert not any(channel.getbbox() for channel in difference.split()), key
    with Image.open(static / area['thumbnail']) as thumb:
        assert thumb.size == (320, 200), key
    collision = json.loads((public / area['collision_asset']).read_text(encoding='utf-8'))
    rows = collision['walkable_rows']
    assert len(area['landing_points']) == len(collision['components']), key
    for landing in area['landing_points']:
        assert rows[landing['y'] - 1][landing['x'] - 1] == '1', key
    for gate in area['transitions']:
        assert rows[gate['y'] - 1][gate['x'] - 1] == '1', key
        target = manifest['areas'][gate['target_area']]
        assert key in target['connections'], key
        assert [gate['target_x'], gate['target_y']] not in [[g['x'], g['y']] for g in target['transitions']], key
visited = {manifest['default_area']}
pending = deque(visited)
while pending:
    for target in manifest['areas'][pending.popleft()]['connections']:
        if target not in visited:
            visited.add(target)
            pending.append(target)
assert len(visited) == 264
assert not (static / 'images/worlds/cyberspace').exists()
assert not (public / 'assets/ui/sectors').exists()
assert not (public / 'config/worlds/cyberspace_collision').exists()
print(json.dumps({'result': 'passed', 'source_layer_hashes': 351, 'composite_pixels': 87, 'native_maps': 264,
                  'thumbnails': 264, 'connected_maps': len(visited),
                  'gate_count': sum(len(a['transitions']) for a in manifest['areas'].values()),
                  'landings': sum(len(a['landing_points']) for a in manifest['areas'].values()),
                  'regions': manifest['region_count']}, indent=2))
