# Dawn soundtrack

This build ships playable audio rendered from the supplied **Digimon World – Dawn (USA)** ROM, game code `A3VE`. The notes, instruments and sample waves come from its SDAT archive. No General MIDI soundfont, replacement composition or generated background music is used.

## Included

- All **46 background-music sequences** exported to stereo Ogg Vorbis at 32,768 Hz.
- **182 sound-effect sequences** exported from the ROM's 183-entry `SEQ_SE` archive.
- Original loop start/end times for the 45 looping music sequences, used by Web Audio to preserve introductions and repeat the loop body. `bgm54` is a nonlooping cue.
- Music throughout public pages, the command center, all 264 Dawn areas, wild battles, Battle Park, DigiLab, shops and community pages.
- Click/confirm/cancel, attack, skill, scan and other gameplay effect hooks.
- An in-page soundtrack desk with mute, separate music/effect volumes, and a player for all 46 tracks. Preferences persist in the browser.
- The original complete `tools/dawn_source/sound_data.sdat`, all 46 standalone SSEQ files, and the untouched SSAR archive. Every instrument/wave bank remains inside the original SDAT.

The ROM contains internal IDs such as `bgm30`, not descriptive track titles. These IDs remain visible in the player and manifest. The scene assignments are this project's arrangements: this release does **not** claim verified one-to-one matching of each Dawn location's original music cue.

## One source effect cannot be rendered as a finite cue

`se024` starts an infinite loop containing a zero-duration note with no rest. A normal offline sequence interpreter makes no timing progress on that entry. The exporter detects this case and records `source_zero_time_loop`; the original entry, instrument and sample are preserved in `SEQ_SE.ssar` and `sound_data.sdat`. It is not replaced with fabricated audio, is not used by the game, and is not counted among the 182 playable effects.

## Browser behavior

Browsers require a user gesture before they permit sound. The first click or keypress enables playback; the soundtrack desk also has an explicit **Enable sound** button. Muting is remembered. Music pauses in hidden tabs and resumes when the game returns. Navigating within the same scene restores the previous track position when browser policy permits playback.

The music player keeps one decoded music buffer. Switching tracks temporarily decodes the next track before replacing the current buffer. Effects use a cache of at most twelve buffers and at most eight simultaneous voices. Controls are in normal page flow and never cover the map or battle commands.

## Rebuild

Normal XAMPP installation needs no audio tools: the playable Ogg files are already included. To rebuild from a matching ROM, install Python 3, a C++11 compiler (`g++` or `clang++`), and ffmpeg with libvorbis, then run from the project directory:

```sh
python tools/extract_dawn_audio.py "C:/path/Digimon World - Dawn (USA).nds" --jobs 4
```

The bundled source renderer builds locally; downloads are not needed. Per-track rendering metadata enables reruns to resume completed exports. Cache entries verify the source archive, renderer source and output hashes; changing any of them triggers a fresh render. `tools/third_party/SSEQPlayer/UPSTREAM.md` identifies the upstream revision, license and local compatibility/telemetry changes.

`public_html/config/dawn_audio.json` records the ROM/archive SHA-256, source sequence IDs, bank IDs, loop timing, nonzero sample counts, clipping counts and Ogg file hashes. `docs/DAWN_AUDIO_VERIFICATION.json` records the catalog summary and decoding verification. Run `python tools/validate_dawn_audio.py --decode` to verify every shipped file hash and fully decode all 228 Ogg files.

## Gameplay integration

`includes/ui.php` loads `includes/audio.php` and calls `dn_audio_widget()` once in the shared footer. The widget uses the current route and the Dawn area's `music_track` metadata to choose a track.

Use `data-dawn-sfx="skill"` (or `confirm`, `cancel`, `hit`, `scan`, `evolve`, `victory`, `defeat`, `encounter`) on controls, and `data-dawn-result="win"` / `"loss"` on a battle result container. JavaScript can call:

```js
window.DawnAudio?.effect('hit');
window.DawnAudio?.scene('battle');
document.dispatchEvent(new CustomEvent('nxt:audio', { detail: { effect: 'scan' } }));
```

Audio failures remain isolated from gameplay requests and battle state.
