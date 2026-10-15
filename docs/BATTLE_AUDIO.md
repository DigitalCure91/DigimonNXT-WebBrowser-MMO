# Battle sound integration

Battle effects use the shipped, playable Dawn SDAT renders. The newly supplied
`Digimon World - Dawn (USA)(5).nds` has SHA-256
`b8fbc2272ec2a7b3c85674a43ffce0a7dde208319e331b349218e1238ce83005`, which matches
the original audio extraction manifest. No replacement soundtrack, synthesized
impact noise, or external audio service is used.

The existing NXT music assignments remain: wild battle `bgm30`, ranked battle
`bgm31`, victory `bgm54`, and defeat `bgm53`. These are NXT scene assignments;
the ROM's internal IDs do not establish an original game track title or role.
See [Dawn soundtrack](DAWN_AUDIO.md) for extraction details and the single
unrenderable zero-time-loop source effect (`se024`, never used in battle).

## Playback behavior

- First-time audio waits for a pointer/key gesture or **Enable sound**. A
  remembered sound activation attempts to resume across navigation, subject to
  browser autoplay policy. If blocked, the next gesture retries it; saved mute
  is always respected.
- The animation director cues a short physical/magic launch, then one contact
  sound (`hit`, `effective`, or `resist`) at the visual impact. Healing, guarding,
  missing, victory, and defeat have separate cue roles.
- Battle cue envelopes have short fades to avoid hard cuts. Optional panning
  places feedback subtly toward its on-screen target; significant hits briefly
  lower the music level before restoring it.
- Only six distinct source effects (118,065 compressed bytes) warm on battle
  interaction. Other pages and muted sessions do not preload them. The shared
  cache retains at most twelve decoded effects and eight simultaneous voices.
- Muting, zero effect volume, hiding the tab, skipping, and replacing playback
  stop the relevant voices. Pending hits are invalidated; uncached battle sounds
  arriving more than 160 ms late are dropped, rather than sounding out of sync.
- Music and effect sliders remain independent, and a manual music-player track
  continues to override automatic scene selection.
- Music/effect download or decode failures do not affect battles or form actions.

Cue roles reuse existing NXT effect assignments: physical/magic `se030`,
hit/effective/resist `se029`, heal `se009`, guard/miss `se002`, victory `se015`,
defeat `se016`. Differences in envelope and level provide feedback without
altering the source assets or claiming verified original-game cue semantics.

## Animation director API

All methods below are on `window.DawnAudio`. The script also dispatches
`nxt:audio-ready` after defining the API, for modules initialized earlier.

```js
// A command/replay click is an appropriate user gesture for unlock.
// These are optional audio promises; never block server actions on them.
window.DawnAudio?.unlock();
window.DawnAudio?.prepareBattle();

window.DawnAudio?.scene('battle'); // or ranked
window.DawnAudio?.battleCue('physical', { pan: -1 });
// At actual contact, use ONE of hit/effective/resist per contact beat.
window.DawnAudio?.battleCue('effective', { pan: 1 });

// Skip, replaced animation, replay reset, or navigation:
window.DawnAudio?.stopBattleEffects();

// After the visual outcome has finished:
window.DawnAudio?.scene('victory'); // defeat on loss, town on disconnect
```

`battleCue` resolves to whether a cue played, and never rejects for load/playback
failures. `prepareBattle` resolves to whether its six distinct cues loaded; it
returns false while muted, locked, or hidden. `pan` ranges from -1 to 1 and is
attenuated to avoid hard stereo separation. Optional `gain` ranges from 0 to 1.

Mark the battle container with `data-dawn-managed`. Within it, generic command
click handlers emit only a quiet confirmation; the director owns attack sounds.
The audio bootstrap also leaves nested `data-dawn-result` music switching to the
director, so victory/defeat music cannot start before the last animated hit.
For silent controls, use `data-dawn-sfx="none"`.

## Validation

Run `node tools/test_battle_audio.cjs` for the audio lifecycle regression checks:
permission gating, permitted cross-navigation resume, blocked-resume gesture
retry, remembered mute, managed result timing, bounded preloading and voices, panning
and contact timing, skip/mute/hidden cancellation, late download suppression,
volume controls, command-only confirmation, failure isolation, manual soundtrack
selection, and unsupported-browser behavior. These are contract tests with a
mocked Web Audio context, not proof of audible hardware playback.

`BATTLE_AUDIO_VERIFICATION.json` records the matching ROM/SDAT hashes, integrity
checks for all 228 shipped Ogg files, and successful complete FFmpeg decodes of
the ten battle-related effects/music tracks. The browser/director integration is
verified separately by the release checks.
