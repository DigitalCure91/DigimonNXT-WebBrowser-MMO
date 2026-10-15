/* Genuine Dawn SDAT audio. One music buffer, twelve cached effects, eight voices. */
(() => {
  'use strict';
  const config = window.DAWN_AUDIO_CONFIG;
  if (!config || !config.tracks || window.DawnAudio) return;
  const read = (key, fallback) => { try { const value = localStorage.getItem('nxt.dawn.' + key); return value === null ? fallback : value; } catch (_) { return fallback; } };
  const save = (key, value) => { try { localStorage.setItem('nxt.dawn.' + key, String(value)); } catch (_) {} };
  const clamp = value => Math.max(0, Math.min(1, Number.isFinite(+value) ? +value : .4));
  let enabled = read('enabled', 'true') === 'true';
  const rememberedActivation = read('activated', 'false') === 'true' || read('enabled', '') === 'true';
  let musicVolume = clamp(read('musicVolume', '.38')), effectsVolume = clamp(read('effectsVolume', '.55'));
  let context, musicGain, effectsGain, source, currentId = '', musicBuffer = null, startedAt = 0, startOffset = 0;
  let requestedId = '', changeToken = 0, unlocked = false, inFlight, suspendedByVisibility = false;
  let selected = 'auto', effectsEpoch = 0, battleEpoch = 0;
  const effectsCache = new Map(), pendingEffects = new Map(), lastEffectAt = new Map(), activeEffects = new Set();
  // Roles are NXT arrangements of original ROM effects, not asserted Dawn metadata.
  const battleCues = {
    physical: { effect: 'skill', gain: .42, duration: .18 },
    magic: { effect: 'skill', gain: .68, duration: .42 },
    hit: { effect: 'hit', gain: .76, duration: .55, duck: true },
    effective: { effect: 'hit', gain: 1, duration: .70, duck: true },
    resist: { effect: 'hit', gain: .44, duration: .34 },
    heal: { effect: 'scan', gain: .64, duration: .80 },
    guard: { effect: 'cancel', gain: .50, duration: .42 },
    miss: { effect: 'cancel', gain: .30, duration: .22 },
    victory: { effect: 'victory', gain: .74, duration: 1.65 },
    defeat: { effect: 'defeat', gain: .66, duration: 2.25 }
  };
  const status = document.querySelector('[data-dawn-status]'), toggle = document.querySelector('[data-dawn-toggle]');
  const setStatus = text => { if (status) status.textContent = text; };
  const update = () => {
    if (toggle) { toggle.textContent = enabled && unlocked ? 'Mute sound' : 'Enable sound'; toggle.setAttribute('aria-pressed', String(enabled && unlocked)); }
    if (!enabled) setStatus('Sound is muted.');
  };
  const setMusicGain = () => {
    if (!musicGain) return;
    musicGain.gain.cancelScheduledValues(context.currentTime);
    musicGain.gain.setTargetAtTime(musicVolume, context.currentTime, .025);
  };
  const setup = () => {
    if (context) return true;
    const Constructor = window.AudioContext || window.webkitAudioContext;
    if (!Constructor) { setStatus('Your browser does not support this audio player.'); return false; }
    try {
      context = new Constructor();
      musicGain = context.createGain(); effectsGain = context.createGain();
      musicGain.gain.value = musicVolume; effectsGain.gain.value = effectsVolume;
      musicGain.connect(context.destination); effectsGain.connect(context.destination);
      return true;
    } catch (_) { setStatus('Sound is unavailable in this browser.'); return false; }
  };
  const stopMusic = () => { if (source) { source.onended = null; try { source.stop(); } catch (_) {} source.disconnect(); source = null; } };
  const stopVoice = voice => {
    if (!activeEffects.has(voice)) return;
    activeEffects.delete(voice);
    voice.node.onended = null;
    try { voice.node.stop(); } catch (_) {}
    voice.node.disconnect(); voice.gain.disconnect(); voice.pan?.disconnect();
  };
  const stopEffects = battleOnly => {
    if (battleOnly) ++battleEpoch; else ++effectsEpoch;
    for (const voice of activeEffects) if (!battleOnly || voice.battle) stopVoice(voice);
    setMusicGain();
  };
  const stopBattleEffects = () => stopEffects(true);
  const decode = async id => {
    const track = config.tracks[id];
    if (!track) throw new Error('Track is unavailable');
    const response = await fetch(track.url, { cache: 'force-cache', credentials: 'same-origin' });
    if (!response.ok) throw new Error('Track could not be loaded');
    return context.decodeAudioData(await response.arrayBuffer());
  };
  const music = async id => {
    if (!enabled || !unlocked || document.hidden || !config.tracks[id]) return;
    if (requestedId === id && ((source && currentId === id) || inFlight)) return;
    requestedId = id;
    const token = ++changeToken;
    setStatus('Loading Dawn music…');
    try {
      inFlight = currentId === id && musicBuffer ? Promise.resolve(musicBuffer) : decode(id);
      const buffer = await inFlight;
      if (token !== changeToken || !enabled || document.hidden) return;
      stopMusic(); musicBuffer = buffer; currentId = id;
      const next = context.createBufferSource(); next.buffer = buffer; next.connect(musicGain); source = next;
      const entry = config.tracks[id];
      if (entry.loopEnd > entry.loopStart) { next.loop = true; next.loopStart = entry.loopStart; next.loopEnd = Math.min(entry.loopEnd, buffer.duration); }
      let offset = 0;
      try {
        const previous = JSON.parse(sessionStorage.getItem('nxt.dawn.position') || 'null');
        // Result jingles always begin at the start; only continuous music resumes.
        if (next.loop && previous && previous.id === id && Date.now() - previous.saved < 1800000) offset = Math.max(0, Math.min(+previous.offset || 0, buffer.duration - .1));
      } catch (_) {}
      startedAt = context.currentTime; startOffset = offset;
      next.onended = () => {
        if (source !== next) return;
        next.disconnect(); source = null; requestedId = '';
        if (enabled) setStatus('Dawn · ' + id.toUpperCase());
      };
      next.start(0, offset); setMusicGain();
      setStatus('Playing Dawn · ' + id.toUpperCase()); update();
    } catch (_) {
      if (token === changeToken) { requestedId = ''; setStatus('Music could not load. Choose a track in Sound settings to retry.'); }
    } finally { if (token === changeToken) inFlight = null; }
  };
  const effectId = name => config.effects?.[name] || name;
  const loadEffect = async id => {
    if (!config.tracks[id] || config.tracks[id].kind !== 'effect') return null;
    if (effectsCache.has(id)) {
      const cached = effectsCache.get(id); effectsCache.delete(id); effectsCache.set(id, cached); return cached;
    }
    if (!pendingEffects.has(id)) {
      pendingEffects.set(id, decode(id).then(buffer => {
        effectsCache.set(id, buffer);
        while (effectsCache.size > 12) effectsCache.delete(effectsCache.keys().next().value);
        return buffer;
      }).finally(() => pendingEffects.delete(id)));
    }
    return pendingEffects.get(id);
  };
  const prepareBattle = async () => {
    if (!enabled || !unlocked || !effectsVolume || document.hidden) return false;
    const ids = [...new Set(Object.values(battleCues).map(cue => effectId(cue.effect)))];
    const loaded = await Promise.allSettled(ids.map(loadEffect));
    return loaded.every(result => result.status === 'fulfilled' && !!result.value);
  };
  const unlock = async () => {
    if (!enabled || document.hidden || !setup()) return false;
    // A policy-blocked resume can remain pending. Always retry resume inside a
    // fresh gesture; reusing that pending promise would leave audio locked.
    try { await context.resume(); unlocked = enabled && !document.hidden && context.state === 'running'; }
    catch (_) { unlocked = false; }
    update();
    if (unlocked) {
      save('activated', true);
      music(selected === 'auto' ? config.track : selected);
      if (config.scene === 'battle' || config.scene === 'ranked' || document.querySelector('[data-dawn-managed]')) prepareBattle();
    }
    return unlocked;
  };
  const effect = async (name, options = {}) => {
    if (!enabled || !unlocked || !effectsVolume || document.hidden) return false;
    const id = effectId(name);
    if (!config.tracks[id] || config.tracks[id].kind !== 'effect') return false;
    const epoch = effectsEpoch, battle = !!options.battle, cueEpoch = battleEpoch, requestedAt = performance.now();
    const throttleKey = (battle ? 'battle:' : 'ui:') + (options.cue || id);
    if (requestedAt - (lastEffectAt.get(throttleKey) ?? -1000) < 65) return false;
    lastEffectAt.set(throttleKey, requestedAt);
    try {
      const buffer = await loadEffect(id);
      // A slow first fetch, hidden tab, mute, skip or replay never emits a late hit.
      if (!buffer || !enabled || !unlocked || !effectsVolume || document.hidden || context.state !== 'running' || epoch !== effectsEpoch || (battle && cueEpoch !== battleEpoch) || performance.now() - requestedAt > (battle ? 160 : 350)) return false;
      while (activeEffects.size >= 8) stopVoice(activeEffects.values().next().value);
      const node = context.createBufferSource(), gain = context.createGain();
      const pan = battle && context.createStereoPanner ? context.createStereoPanner() : null;
      node.buffer = buffer; node.connect(gain);
      if (pan) { pan.pan.value = Math.max(-1, Math.min(1, Number(options.pan) || 0)) * .45; gain.connect(pan); pan.connect(effectsGain); }
      else gain.connect(effectsGain);
      const now = context.currentTime, volume = options.gain == null ? 1 : clamp(options.gain);
      const duration = Math.min(buffer.duration, Math.max(.08, Number(options.duration) || buffer.duration));
      gain.gain.setValueAtTime(0, now);
      gain.gain.linearRampToValueAtTime(volume, now + .006);
      gain.gain.setValueAtTime(volume, now + Math.max(.007, duration - .065));
      gain.gain.linearRampToValueAtTime(0, now + duration);
      const voice = { node, gain, pan, battle }; activeEffects.add(voice);
      node.onended = () => { activeEffects.delete(voice); node.disconnect(); gain.disconnect(); pan?.disconnect(); };
      node.start(now); node.stop(now + duration);
      if (options.duck && musicVolume) {
        musicGain.gain.cancelScheduledValues(now);
        musicGain.gain.setTargetAtTime(musicVolume * .78, now, .025);
        musicGain.gain.setTargetAtTime(musicVolume, now + .22, .14);
      }
      return true;
    } catch (_) { return false; /* Audio must never interrupt a battle or a form submission. */ }
  };
  const battleCue = (name, options = {}) => {
    const cue = battleCues[name];
    if (!cue) return Promise.resolve(false);
    return effect(cue.effect, { ...cue, ...options, battle: true, cue: name });
  };
  const scene = name => {
    if (!config.scenes[name]) return;
    config.scene = name; config.track = config.scenes[name];
    if (selected === 'auto') music(config.track);
  };
  window.DawnAudio = Object.freeze({ effect, battleCue, prepareBattle, stopBattleEffects, scene, play: music, unlock });
  toggle?.addEventListener('click', async () => {
    enabled = !enabled || !unlocked; save('enabled', enabled);
    if (enabled) await unlock();
    else {
      ++changeToken; requestedId = ''; inFlight = null; stopMusic(); stopEffects(false);
      if (context) try { await context.suspend(); } catch (_) {}
    }
    update();
  });
  [['[data-dawn-music-volume]', 'musicVolume'], ['[data-dawn-effects-volume]', 'effectsVolume']].forEach(([selector, key]) => {
    const input = document.querySelector(selector); if (!input) return;
    input.value = Math.round((key === 'musicVolume' ? musicVolume : effectsVolume) * 100);
    input.addEventListener('input', () => {
      const volume = clamp(input.value / 100); save(key, volume);
      if (key === 'musicVolume') { musicVolume = volume; setMusicGain(); }
      else {
        effectsVolume = volume;
        if (effectsGain) effectsGain.gain.setTargetAtTime(volume, context.currentTime, .015);
        if (!volume) stopEffects(false);
      }
    });
  });
  document.querySelector('[data-dawn-track]')?.addEventListener('change', async event => { selected = event.target.value; enabled = true; save('enabled', true); await unlock(); });
  document.addEventListener('pointerdown', event => { if (!event.target.closest('[data-dawn-toggle]') && enabled && (!unlocked || context?.state !== 'running')) unlock(); }, { passive: true });
  document.addEventListener('keydown', () => { if (enabled && (!unlocked || context?.state !== 'running')) unlock(); }, { passive: true });
  document.addEventListener('click', event => {
    const control = event.target.closest('[data-dawn-sfx],button,a[href],summary');
    if (!control || control.closest('.dawn-audio') || control.hasAttribute('disabled') || control.dataset.dawnSfx === 'none') return;
    // Commands only confirm input; the battle director owns the actual contact cue.
    const managed = control.closest('[data-dawn-managed]');
    effect(managed ? 'confirm' : (control.dataset.dawnSfx || (control.matches('button') ? 'confirm' : 'select')));
  }, { passive: true });
  document.addEventListener('nxt:audio', event => { if (event.detail?.effect) effect(event.detail.effect); if (event.detail?.scene) scene(event.detail.scene); });
  const remember = () => {
    if (!source || !context || !currentId) return;
    let offset = startOffset + context.currentTime - startedAt;
    const track = config.tracks[currentId];
    if (source.loop && offset >= track.loopEnd) offset = track.loopStart + ((offset - track.loopStart) % (track.loopEnd - track.loopStart));
    try { sessionStorage.setItem('nxt.dawn.position', JSON.stringify({ id: currentId, offset, saved: Date.now() })); } catch (_) {}
  };
  window.addEventListener('pagehide', () => { remember(); stopEffects(false); });
  document.addEventListener('visibilitychange', async () => {
    if (!context || !enabled) return;
    if (document.hidden) {
      remember(); suspendedByVisibility = true; stopEffects(false);
      try { await context.suspend(); } catch (_) {}
    } else if (suspendedByVisibility) { suspendedByVisibility = false; await unlock(); }
  });
  const result = document.querySelector('[data-dawn-result]');
  if (result && !result.closest('[data-dawn-managed]')) {
    const resultScene = { win: 'victory', loss: 'defeat' }[result.dataset.dawnResult];
    if (resultScene) config.track = config.scenes[resultScene];
  }
  update();
  document.dispatchEvent(new CustomEvent('nxt:audio-ready'));
  // A remembered choice may resume across navigation only if browser policy
  // permits it. A blocked attempt still falls back to pointer/key/Enable sound.
  if (enabled && rememberedActivation) unlock();
})();
