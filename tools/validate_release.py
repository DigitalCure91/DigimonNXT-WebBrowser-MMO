#!/usr/bin/env python3
"""Digimon NXT static release validator.

This intentionally avoids a live MariaDB dependency so it can be run during
packaging. XAMPP runtime/database behavior remains covered by TESTING.md.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
from collections import deque
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "public_html"
STATIC = PUBLIC / "html" / "static"


def heredoc_json(path: Path) -> dict:
    text = path.read_text(encoding="utf-8")
    match = re.search(r"<<<'JSON'\s*\n(.*?)\nJSON", text, re.S)
    if not match:
        raise AssertionError(f"Could not extract JSON from {path.relative_to(ROOT)}")
    return json.loads(match.group(1))


def assert_true(value: bool, message: str) -> None:
    if not value:
        raise AssertionError(message)


def validate_species() -> tuple[list[dict], set[str]]:
    species = json.loads((PUBLIC / "config" / "digimon_species.json").read_text(encoding="utf-8"))
    assert_true(len(species) == 1000, f"Expected 1000 supplied forms, found {len(species)}")
    slugs = [str(s["slug"]) for s in species]
    names = [str(s["name"]) for s in species]
    assert_true(len(slugs) == len(set(slugs)), "Duplicate Digimon slug")
    assert_true(len(names) == len(set(names)), "Duplicate Digimon name")
    allowed_stages = {"Baby", "In-Training", "Rookie", "Champion", "Armor", "Ultimate", "Mega", "Ultra"}
    for s in species:
        assert_true(s["stage"] in allowed_stages, f"Unsupported stage for {s['name']}: {s['stage']}")
        assert_true(1 <= int(s["base_hp"]) <= 9999, f"Invalid HP for {s['name']}")
        assert_true(int(s["skill_power"]) > 0, f"Invalid skill power for {s['name']}")
        sprite = STATIC / str(s["sprite"])
        assert_true(sprite.is_file(), f"Missing sprite: {s['sprite']}")
        with Image.open(sprite) as im:
            assert_true(im.format == "PNG", f"Sprite is not PNG: {s['sprite']}")
            assert_true(im.size == (256, 256), f"Sprite must be 256x256: {s['sprite']} = {im.size}")
            rgba = im.convert("RGBA")
            bbox = rgba.getchannel("A").getbbox()
            assert_true(bbox is not None, f"Sprite has no visible pixels: {s['sprite']}")
            assert_true(bbox[0] >= 0 and bbox[1] >= 0 and bbox[2] <= 256 and bbox[3] <= 256, f"Sprite bounds invalid: {s['sprite']}")
    manifest = json.loads((PUBLIC / "config" / "digimon_sprite_sources.json").read_text(encoding="utf-8"))
    assert_true(len(manifest) == len(species), "Sprite source manifest count does not match species catalog")
    assert_true({m["slug"] for m in manifest} == set(slugs), "Sprite source manifest slug set is incomplete")
    return species, set(slugs)


def _runtime_reachable(cols: int, rows: int, blocked: set[tuple[int, int]], start: tuple[int, int]) -> set[tuple[int, int]]:
    assert_true(start not in blocked, f"Reachability start is blocked: {start}")
    reachable = {start}
    queue = deque([start])
    deltas = ((0,-1),(0,1),(-1,0),(1,0),(-1,-1),(-1,1),(1,-1),(1,1))
    while queue:
        x, y = queue.popleft()
        for dx, dy in deltas:
            nx, ny = x + dx, y + dy
            if not (1 <= nx <= cols and 1 <= ny <= rows) or (nx, ny) in blocked:
                continue
            if abs(dx) == 1 and abs(dy) == 1 and (((x + dx, y) in blocked) or ((x, y + dy) in blocked)):
                continue
            if (nx, ny) not in reachable:
                reachable.add((nx, ny)); queue.append((nx, ny))
    return reachable


def validate_world(slugs: set[str]) -> None:
    from validate_dawn_world import validate_dawn_world, load_world
    result = validate_dawn_world()
    world = load_world()
    profiles = heredoc_json(PUBLIC / "config" / "worlds" / "cyberspace_encounters.php")
    encountered: set[str] = set()
    for key, area in world["areas"].items():
        profile = str(area.get("encounter_profile", ""))
        assert_true(not profile or profile in profiles, f"{key}: missing encounter profile {profile}")
    assert_true({a["encounter_profile"] for a in world["areas"].values() if a["encounter_profile"]} == set(profiles), "Some species encounter pools have no Dawn map")
    assert_true(len(profiles) == 10, f"Expected 10 encounter profiles outside the hub, found {len(profiles)}")
    for profile_key, profile in profiles.items():
        chance = float(profile.get("chance", 0))
        assert_true(0.0 <= chance <= 0.8, f"{profile_key}: encounter chance outside allowed range")
        total_weight = 0
        for entry in profile.get("entries", []):
            assert_true(len(entry) >= 4, f"{profile_key}: malformed encounter entry")
            slug, min_level, max_level, weight = entry[:4]
            assert_true(slug in slugs, f"{profile_key}: unknown Digimon slug {slug}")
            assert_true(1 <= int(min_level) < int(max_level) <= 24, f"{profile_key}: invalid level range for {slug}")
            assert_true(int(weight) > 0, f"{profile_key}: non-positive encounter weight for {slug}")
            encountered.add(str(slug)); total_weight += int(weight)
        assert_true(total_weight > 0, f"{profile_key}: encounter profile has no weight")
    paradox = json.loads((PUBLIC / "config" / "paradox_encounters.json").read_text())
    rare = set(paradox["entries"])
    assert_true(len(rare) == 500 and not (rare & encountered), "Paradox pool must contain 500 separate forms")
    assert_true(float(paradox["chance_per_signal"]) == .002, "Paradox signal rate must be 0.2%")
    assert_true(1 <= paradox["min_level"] < paradox["max_level"] <= 24, "Invalid Paradox level range")
    encountered |= rare
    assert_true(encountered == slugs, f"Catalog species missing from Cyberspace encounter tables: {sorted(slugs - encountered)}")

def validate_progression(species: list[dict], slugs: set[str]) -> None:
    moves = json.loads((PUBLIC / "config" / "digimon_moves.json").read_text())
    rules = json.loads((PUBLIC / "config" / "digivolution_rules.json").read_text())
    assert_true(len(moves) == 768 and len(rules) == 1902, "Unexpected move / evolution counts")
    by_slug = {s["slug"]: s for s in species}
    for s in species:
        assert_true(s.get("variant") in {"normal", "paradox"}, f"Invalid variant: {s['slug']}")
        assert_true(bool(s["learnset"]) and any(e["level"] == 1 for e in s["learnset"]), f"No initial moves: {s['slug']}")
        for e in s["learnset"]:
            assert_true(e["move"] in moves and 1 <= e["level"] <= 99, f"Invalid learnset: {s['slug']}")
        for level in ["1", "50", "99"]:
            assert_true(all(v > 0 for v in s["stats"][level].values()), f"Invalid stat anchor: {s['slug']}")
    edges = set()
    covered = set()
    for r in rules:
        a, b = r["from"], r["to"]
        assert_true(a in slugs and b in slugs and a != b, "Invalid evolution endpoint")
        assert_true((a,b) not in edges, "Duplicate evolution route")
        assert_true(by_slug[a]["variant"] == by_slug[b]["variant"], "Evolution must retain variant")
        assert_true(1 <= r["level"] <= 99 and 0 <= r["abi"] <= 200 and 0 <= r["cam"] <= 100, "Invalid evolution gate")
        if r.get("partner"):
            assert_true(r["partner"] in slugs and by_slug[a]["variant"] == by_slug[r["partner"]]["variant"], "Invalid DNA partner")
        edges.add((a,b));covered |= {a,b}
    assert_true(covered == slugs, "A form has no evolution or de-evolution route")
    for k,m in moves.items():
        assert_true(m["sp"] >= 0 and m["target"] in {"foe", "all_foes", "self", "ally", "all_allies"}, f"Invalid move {k}")
    recovery = json.loads((PUBLIC / "config" / "recovery_items.json").read_text())
    assert_true(len(recovery) == 6 and all(e["field"] in {"hp", "sp"} and e["amount"] > 0 for e in recovery.values()), "Invalid recovery catalog")


def validate_database_bundle() -> None:
    sql_root = ROOT / "database" / "digimon_nxt_full.sql"
    sql_web = PUBLIC / "database" / "digimon_nxt_full.sql"
    assert_true(sql_root.is_file(), "Root standalone SQL dump is missing")
    assert_true(sql_web.is_file(), "Web-package SQL convenience copy is missing")
    root_text = sql_root.read_text(encoding="utf-8")
    web_text = sql_web.read_text(encoding="utf-8")
    assert_true(root_text == web_text == (PUBLIC / "database/digimon_nxt.sql").read_text(encoding="utf-8"), "Standalone SQL copies differ")
    assert_true(len(root_text.encode("utf-8")) >= 20000, "Standalone SQL dump is unexpectedly small")
    tables = set(re.findall(r"CREATE TABLE IF NOT EXISTS\s+`?([a-z0-9_]+)", root_text, re.I))
    required = {
        "schema_meta","members","members_options","online","mapusers","world_map_positions","world_map_blocks",
        "digimon_species","digimon_instances","digimon_party","digimon_scan","materialization_log","digivolution_log","battle_log",
        "items","member_items","notifications","news","messages","friends","clans","clan_members","trade_listings",
        "nxt_bots","nxt_ranked_profiles","nxt_ranked_seasons","nxt_ranked_matches","nxt_ranked_tickets","nxt_bot_activity","nxt_worker_state","nxt_world_settings","nxt_world_admin_audit","nxt_world_bans","nxt_world_ip_bans","arena_progress","arena_battles","events","event_progress","audit_log","digimon_learned_moves","digimon_battle_state","digimon_action_receipts"
    }
    assert_true(required <= tables, f"Standalone SQL is missing tables: {sorted(required - tables)}")
    assert_true("INSERT INTO `digimon_species`" in root_text, "Standalone SQL has no Digimon species seed")
    assert_true("INSERT INTO `items`" in root_text, "Standalone SQL has no item seed")
    assert_true("INSERT INTO `events`" in root_text, "Standalone SQL has no event seed")
    assert_true("'schema_version','8'" in root_text, "Standalone SQL schema revision is not 8")
    assert_true("Dawn Trails" in root_text and "Frozen Reaches" in root_text and "Deep Network" in root_text, "Dawn event seed is incomplete")
    setup = (PUBLIC / "_setup.php").read_text(encoding="utf-8")
    assert_true("dn_apply_schema_migrations" in setup and "Fresh Rebuild" in setup, "Automatic database installer is incomplete")


def validate_runtime_surface() -> None:
    assert_true((ROOT / "VERSION").read_text(encoding="utf-8").strip() == "0.7.2", "VERSION file is not 0.7.2")
    required_pages = {
        "index.php","login.php","signup.php","dashboard.php","map_select.php","world_map.php","world_map_move.php","world_map_presence.php","world_map_land.php",
        "wildbattle.php","digimon.php","party.php","materialize.php","digidex.php","items.php","trade.php","battle_arena.php","battle_history.php",
        "ai_activity.php","tamer_rivals.php","world_encounter.php","community.php","profile.php","messages.php","clans.php","event_center.php","notifications.php","digivolution.php","your_account.php","options.php","news.php","admin.php","_setup.php","_health.php"
    }
    missing = sorted(x for x in required_pages if not (PUBLIC / x).is_file())
    assert_true(not missing, f"Runtime surface is missing pages: {missing}")
    for i in range(1, 7):
        assert_true((STATIC / "images" / "tamers" / f"{i}.png").is_file(), f"Missing tamer avatar {i}")
    js = (PUBLIC / "assets" / "js" / "world-map.js").read_text(encoding="utf-8")
    css = (PUBLIC / "assets" / "css" / "digimon-nxt.css").read_text(encoding="utf-8") + (PUBLIC / "assets/css/dawn-world.css").read_text(encoding="utf-8")
    world_page = (PUBLIC / "world_map.php").read_text(encoding="utf-8")
    for token in ("cameraTarget", "centerCamera", "getBoundingClientRect", "scrollTo", "requestAnimationFrame"):
        assert_true(token in js, f"Native tracked-camera contract is missing JavaScript token: {token}")
    assert_true("pv-region-native-viewport" in world_page and "pv-region-native-stage" in world_page, "World page is missing native-map viewport/stage classes")
    assert_true("dn-world-map-links" in world_page and "dn-world-map-link" in css, "World page is missing authored portal-link presentation")
    assert_true("--pv-world-width" in css and "--pv-world-height" in css, "CSS must preserve each Dawn map native canvas dimensions")
    assert_true("overflow:auto" in css.replace(" ", ""), "Native map viewport is not scrollable")
    setup = (PUBLIC / "_setup.php").read_text(encoding="utf-8")
    health = (PUBLIC / "_health.php").read_text(encoding="utf-8")
    assert_true("pv_config('asset_version')" in setup, "Setup page must display current release metadata")
    assert_true("asset_version','0.7.2" in health, "Health endpoint version fallback is stale")
    ranked = (PUBLIC / "includes" / "ranked.php").read_text(encoding="utf-8")
    assert_true("dn_team_simulate" in ranked and "FOR UPDATE" in ranked, "Ranked combat or transactional settlement is missing")


def validate_legacy_content() -> None:
    terms = [
        "poke" + "mon", "pok" + "émon", "poke" + "ball", "poké" + "ball", "poke" + "dex",
        "p" + "guide", "kan" + "to", "ho" + "enn"
    ]
    forbidden = re.compile("|".join(re.escape(t) for t in terms), re.I)
    text_ext = {".php", ".js", ".css", ".md", ".json", ".txt", ".sql"}
    hits: list[str] = []
    for path in ROOT.rglob("*"):
        if not path.is_file():
            continue
        rel = path.relative_to(ROOT).as_posix()
        if forbidden.search(rel):
            hits.append(rel)
            continue
        if path.suffix.lower() in text_ext:
            try:
                text = path.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            match = forbidden.search(text)
            if match:
                hits.append(f"{rel}: {match.group(0)}")
    assert_true(not hits, "Legacy franchise content detected: " + "; ".join(hits[:10]))

def validate_syntax() -> None:
    php = shutil.which("php")
    if php:
        for path in ROOT.rglob("*.php"):
            result = subprocess.run([php, "-l", str(path)], capture_output=True, text=True)
            assert_true(result.returncode == 0, f"PHP syntax failure in {path.relative_to(ROOT)}: {result.stderr or result.stdout}")
    else:
        print("SKIP  native PHP lint: PHP is not on PATH (run php -l separately)")
    node = shutil.which("node")
    if node:
        for path in (PUBLIC / "assets" / "js").glob("*.js"):
            result = subprocess.run([node, "--check", str(path)], capture_output=True, text=True)
            assert_true(result.returncode == 0, f"JavaScript syntax failure in {path.relative_to(ROOT)}: {result.stderr or result.stdout}")


def main() -> int:
    try:
        species, slugs = validate_species()
        print(f"PASS  species + normalized sprites ({len(species)} species)")
        validate_world(slugs)
        print("PASS  Dawn native maps / original layers / ROM collision / travel / encounters (264 areas)")
        validate_progression(species, slugs)
        print("PASS  1000 learnsets / 768 moves / 1902 evolution routes / recovery effects")
        validate_database_bundle()
        print("PASS  complete standalone database + installer")
        validate_runtime_surface()
        print("PASS  full MMO runtime surface + native tracked camera")
        validate_legacy_content()
        print("PASS  legacy franchise content exclusion")
        validate_syntax()
        print("PASS  available syntax checks (see any SKIP above)")
    except (AssertionError, OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"FAIL  {exc}", file=sys.stderr)
        return 1
    print("\nDigimon NXT static release validation: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
