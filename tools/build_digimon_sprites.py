#!/usr/bin/env python3
"""Legacy v0.2.0 sprite builder for the original 48-form asset layout.

For the v7 1000-form bundle use normalize_supplied_sprites.py.

Rules:
- Prefer isolated *_Idle.png artwork where the pack provides it.
- For legacy multi-pose sheets, isolate the upper-left full-size pose using
  background-aware connected-component analysis.
- Preserve hard pixel edges with nearest-neighbour scaling.
- Emit transparent 256x256 PNGs and a reproducible JSON source manifest.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

import numpy as np
from PIL import Image
from scipy import ndimage as ndi

CANVAS = 256
MAX_SPRITE = 220

NO_IDLE_SOURCE = {
    "stingmon": "4 Champion/109_Stingmon.png",
    "wizardmon": "4 Champion/105_Wizardmon.png",
    "weregarurumon": "5 Ultimate/214_WereGarurumon.png",
    "megakabuterimon": "5 Ultimate/220_MegaKabuterimonRed.png",
    "magnaangemon": "5 Ultimate/223_MagnaAngemon.png",
    "zudomon": "5 Ultimate/249_Zudomon.png",
    "wargrowlmon": "5 Ultimate/229_WarGrowlmon.png",
    "paildramon": "5 Ultimate/224_Paildramon.png",
    "cyberdramon": "5 Ultimate/222_Cyberdramon.png",
    "lillymon": "5 Ultimate/219_Lillymon.png",
    "wargreymon": "7 Mega/309_WarGreymon.png",
    "herculeskabuterimon": "7 Mega/299_HerculesKabuterimon.png",
    "seraphimon": "7 Mega/318_Seraphimon.png",
    "vikemon": "7 Mega/362_Vikemon.png",
    "gallantmon": "7 Mega/323_Gallantmon.png",
    "imperialdramon-fighter": "7 Mega/315_ImperialdramonFighterMode.png",
    "sakuyamon": "7 Mega/326_Sakuyamon.png",
}


def norm(value: str) -> str:
    return re.sub(r"[^a-z0-9]", "", value.lower())


def find_idle(asset_root: Path, species_name: str, slug: str) -> Path | None:
    targets = {norm(species_name), norm(slug)}
    targets |= {t.replace("mode", "") for t in list(targets)}
    candidates: list[Path] = []
    for path in asset_root.rglob("*.png"):
        stem = norm(path.stem)
        parent = norm(path.parent.name)
        if not stem.endswith("idle"):
            continue
        for target in targets:
            if target and (stem == target + "idle" or parent == target):
                candidates.append(path)
                break
    if not candidates:
        return None
    # Prefer the most exact parent/stem name and the shorter path.
    candidates.sort(key=lambda p: (
        0 if norm(p.stem) in {t + "idle" for t in targets if t} else 1,
        0 if norm(p.parent.name) in targets else 1,
        len(str(p)),
        str(p).lower(),
    ))
    return candidates[0]


def dominant_border_rgb(rgba: np.ndarray) -> np.ndarray:
    border = np.concatenate((rgba[0, :, :3], rgba[-1, :, :3], rgba[:, 0, :3], rgba[:, -1, :3]), axis=0)
    return np.median(border.astype(np.float32), axis=0)


def isolate_sheet_pose(image: Image.Image) -> Image.Image:
    rgba = np.asarray(image.convert("RGBA"))
    bg = dominant_border_rgb(rgba)
    rgb = rgba[:, :, :3].astype(np.float32)
    alpha = rgba[:, :, 3]
    color_distance = np.sqrt(np.sum((rgb - bg) ** 2, axis=2))

    # Legacy sheets use flat light-blue or white backgrounds. Keep strongly
    # non-background pixels and ignore tiny copyright/text fragments.
    mask = (alpha > 12) & (color_distance > 24)
    labels, _ = ndi.label(mask, structure=np.ones((3, 3), dtype=np.uint8))
    objects = ndi.find_objects(labels)
    components: list[tuple[int, int, int, int, int, int]] = []
    for idx, sl in enumerate(objects, start=1):
        if sl is None:
            continue
        ys, xs = sl
        area = int(np.count_nonzero(labels[sl] == idx))
        w = xs.stop - xs.start
        h = ys.stop - ys.start
        if area >= 500 and w >= 35 and h >= 45:
            components.append((area, idx, xs.start, ys.start, xs.stop, ys.stop))
    if not components:
        raise RuntimeError("Could not identify a principal pose in sprite sheet")

    max_area = max(c[0] for c in components)
    major = [c for c in components if c[0] >= max_area * 0.72]
    # The sheets consistently place the canonical large pose at the upper-left.
    major.sort(key=lambda c: (c[3], c[2]))
    _, selected_label, x0, y0, x1, y1 = major[0]

    # Keep the selected connected pose only. Some source sheets overlap the
    # rectangular bounds of adjacent poses, so a plain crop is not sufficient.
    selected = labels == selected_label
    crop = rgba[y0:y1, x0:x1].copy()
    selected_crop = selected[y0:y1, x0:x1]
    crop[:, :, 3] = np.where(selected_crop, crop[:, :, 3], 0).astype(np.uint8)
    return Image.fromarray(crop, "RGBA")


def trim_transparency(image: Image.Image) -> Image.Image:
    rgba = image.convert("RGBA")
    bbox = rgba.getchannel("A").getbbox()
    if not bbox:
        raise RuntimeError("Sprite has no visible pixels")
    return rgba.crop(bbox)


def normalize_sprite(source: Path, mode: str) -> Image.Image:
    original = Image.open(source).convert("RGBA")
    sprite = trim_transparency(original) if mode == "idle" else trim_transparency(isolate_sheet_pose(original))
    # Scale every species to a consistent battle-card footprint. Legacy sheet
    # poses are often small; nearest-neighbour upscaling keeps their pixel art crisp.
    scale = min(MAX_SPRITE / sprite.width, MAX_SPRITE / sprite.height)
    if scale != 1.0:
        new_size = (max(1, round(sprite.width * scale)), max(1, round(sprite.height * scale)))
        sprite = sprite.resize(new_size, Image.Resampling.NEAREST)
    canvas = Image.new("RGBA", (CANVAS, CANVAS), (0, 0, 0, 0))
    x = (CANVAS - sprite.width) // 2
    y = (CANVAS - sprite.height) // 2
    canvas.alpha_composite(sprite, (x, y))
    return canvas


def main() -> int:
    project_root = Path(__file__).resolve().parents[1]
    work_root = project_root.parent
    asset_root = work_root / "assets" / "2D Digimon Assets v5"
    species_path = project_root / "public_html" / "config" / "digimon_species.json"
    output_dir = project_root / "public_html" / "html" / "static" / "images" / "digimon"
    manifest_path = project_root / "public_html" / "config" / "digimon_sprite_sources.json"

    if not asset_root.exists():
        print(f"Asset pack not found: {asset_root}", file=sys.stderr)
        return 2

    species = json.loads(species_path.read_text(encoding="utf-8"))
    output_dir.mkdir(parents=True, exist_ok=True)
    manifest = []

    for entry in species:
        slug = entry["slug"]
        source = find_idle(asset_root, entry["name"], slug)
        mode = "idle"
        if source is None:
            relative = NO_IDLE_SOURCE.get(slug)
            if not relative:
                raise RuntimeError(f"No isolated idle or explicit sheet source configured for {entry['name']}")
            source = asset_root / relative
            mode = "sheet-extract"
        if not source.exists():
            raise RuntimeError(f"Configured source does not exist: {source}")

        normalized = normalize_sprite(source, mode)
        output = output_dir / f"{slug}.png"
        normalized.save(output, optimize=True)
        manifest.append({
            "slug": slug,
            "name": entry["name"],
            "mode": mode,
            "source": source.relative_to(asset_root).as_posix(),
            "output": output.relative_to(project_root / "public_html").as_posix(),
            "size": [CANVAS, CANVAS],
        })
        print(f"{entry['name']:<30} {mode:<13} <- {source.relative_to(asset_root)}")

    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"\nBuilt {len(manifest)} normalized sprites and {manifest_path.name}.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
