#!/usr/bin/env node
/* Audio lifecycle regression checks. No browser, audio device or dependencies required. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public_html/assets/js/dawn-audio.js'), 'utf8');
let passed = 0;
const flush = async () => { for (let i = 0; i < 14; i++) await Promise.resolve(); };
function environment({ muted = false, remembered = false, resumeNeedsGesture = false, resultManaged = true, audioSupported = true } = {}) {
  const events = {}, controls = {}, requests = [], started = [], allSources = [], params = [], contexts = [];
  let clock = 1000, delayFetch = false, rejectId = null;
  const waiting = [];
  const storage = new Map(muted ? [['nxt.dawn.enabled', 'false']] : []);
  if (remembered) storage.set('nxt.dawn.activated', 'true');
  let permitResume = !resumeNeedsGesture, resumeCalls = 0;
  const waitingResumes = [];
  for (const name of ['toggle', 'status', 'music-volume', 'effects-volume', 'track']) controls[name] = { listeners: {}, value: '', dataset: {}, textContent: '', setAttribute() {}, addEventListener(name, fn) { this.listeners[name] = fn; } };
  const result = { dataset: { dawnResult: 'win' }, closest: () => resultManaged ? {} : null };
  const document = { hidden: false, querySelector(selector) {
    if (selector === '[data-dawn-managed]') return resultManaged ? {} : null;
    if (selector === '[data-dawn-result]') return result;
    return controls[selector.slice(11, -1)] || null;
  }, addEventListener(name, fn) { (events[name] ||= []).push(fn); }, dispatchEvent(event) { for (const fn of events[event.type] || []) fn(event); } };
  class Param { constructor() { this.value = 0; this.scheduled = []; params.push(this); } cancelScheduledValues() { this.scheduled.length = 0; } setTargetAtTime(v, t) { this.value = v; this.scheduled.push([v, t]); } setValueAtTime(v, t) { this.value = v; this.scheduled.push([v, t]); } linearRampToValueAtTime(v, t) { this.value = v; this.scheduled.push([v, t]); } }
  class Node { constructor() { this.gain = new Param(); this.pan = new Param(); } connect() {} disconnect() { this.disconnected = true; } }
  class AudioContext {
    constructor() { this.state = 'suspended'; this.currentTime = 10; this.destination = {}; contexts.push(this); }
    async resume() { resumeCalls++; if (!permitResume) await new Promise(resolve => waitingResumes.push(resolve)); else while (waitingResumes.length) waitingResumes.shift()(); this.state = 'running'; }
    async suspend() { this.state = 'suspended'; }
    createGain() { return new Node(); }
    createStereoPanner() { return new Node(); }
    createBufferSource() { const node = new Node(); node.start = time => { started.push(node); node.startTime = time; }; node.stop = time => { if (time == null) node.stopped = true; else node.stopAt = time; }; allSources.push(node); return node; }
    async decodeAudioData(id) { if (id === rejectId) throw Error('decode failure'); return { duration: id.startsWith('bgm') ? 65 : 2.125, id }; }
  }
  const tracks = {};
  for (const id of ['bgm30', 'bgm31', 'bgm54', 'bgm53', 'se000', 'se001', 'se002', 'se003', 'se009', 'se015', 'se016', 'se029', 'se030']) tracks[id] = { kind: id.startsWith('bgm') ? 'music' : 'effect', url: id, loopStart: 2, loopEnd: id === 'bgm54' ? 0 : 60 };
  const config = { track: 'bgm30', scene: 'battle', tracks, scenes: { battle: 'bgm30', ranked: 'bgm31', victory: 'bgm54', defeat: 'bgm53' }, effects: { select: 'se000', confirm: 'se001', cancel: 'se002', hit: 'se029', skill: 'se030', scan: 'se009', victory: 'se015', defeat: 'se016' } };
  const window = { DAWN_AUDIO_CONFIG: config, AudioContext: audioSupported ? AudioContext : null, addEventListener(name, fn) { (events[name] ||= []).push(fn); } };
  const sandbox = { window, document, localStorage: { getItem: k => storage.get(k) ?? null, setItem: (k, v) => storage.set(k, v) }, sessionStorage: { getItem: () => null, setItem() {} }, performance: { now: () => clock }, CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init?.detail; } }, fetch: async id => { requests.push(id); if (delayFetch) await new Promise(resolve => waiting.push(resolve)); return { ok: true, arrayBuffer: async () => id }; }, console, Map, Set };
  vm.runInNewContext(source, sandbox, { filename: 'dawn-audio.js' });
  return { api: window.DawnAudio, controls, contexts, config, requests, allowResume: () => permitResume = true, resumeCalls: () => resumeCalls, started, allSources, params, document, storage, async emit(name, event = {}) { await Promise.all((events[name] || []).map(fn => fn(event))); await flush(); }, tick: (ms = 100) => clock += ms, delay: value => delayFetch = value, release() { while (waiting.length) waiting.shift()(); }, reject: id => rejectId = id };
}
async function check(label, fn) { await fn(); passed++; process.stdout.write(`PASS ${label}\n`); }
(async () => {
  await check('Audio context and assets wait for a gesture; battle result waits for director', async () => {
    const env = environment(); assert.equal(env.contexts.length, 0); assert.equal(env.requests.length, 0); assert.equal(env.config.track, 'bgm30'); assert.equal(await env.api.battleCue('hit'), false);
  });
  await check('Remembered activation resumes navigation when browser policy permits', async () => {
    const env = environment({ remembered: true }); await flush();
    assert.equal(env.contexts.length, 1); assert.equal(env.contexts[0].state, 'running'); assert.ok(env.started.some(n => n.buffer.id === 'bgm30'));
  });
  await check('Blocked automatic resume retries in a fresh gesture instead of trapping audio', async () => {
    const env = environment({ remembered: true, resumeNeedsGesture: true }); await flush();
    assert.equal(env.contexts[0].state, 'suspended'); assert.equal(env.requests.length, 0);
    env.allowResume(); await env.emit('pointerdown', { target: { closest: () => null } });
    assert.equal(env.resumeCalls(), 2); assert.equal(env.contexts[0].state, 'running'); assert.ok(env.started.some(n => n.buffer.id === 'bgm30'));
  });
  await check('An interrupted context retries on the next gesture', async () => {
    const env = environment(); await env.api.unlock(); await flush(); env.contexts[0].state = 'suspended';
    await env.emit('keydown'); assert.equal(env.contexts[0].state, 'running'); assert.equal(env.resumeCalls(), 2);
  });
  await check('Remembered activation never overrides persisted mute', async () => {
    const env = environment({ remembered: true, muted: true }); await flush();
    assert.equal(env.contexts.length, 0); assert.equal(env.requests.length, 0);
  });
  await check('Unlock warms six distinct original cues only, and duplicate prepare shares fetches', async () => {
    const env = environment(); await env.api.unlock(); await Promise.all([env.api.prepareBattle(), env.api.prepareBattle()]); await flush();
    assert.deepEqual([...new Set(env.requests.filter(id => id.startsWith('se')))].sort(), ['se002', 'se009', 'se015', 'se016', 'se029', 'se030']);
    assert.equal(env.requests.length, 7); assert.equal(env.started.filter(n => n.buffer.id.startsWith('bgm')).length, 1);
  });
  await check('Contact is panned, short, faded, and independent from launch throttling', async () => {
    const env = environment(); await env.api.unlock(); await env.api.prepareBattle();
    assert.equal(await env.api.battleCue('physical', { pan: -1 }), true); assert.equal(await env.api.battleCue('hit', { pan: 1 }), true);
    const effects = env.started.filter(n => n.buffer.id.startsWith('se')); assert.equal(effects.length, 2); assert.equal(effects[1].stopAt, 10.55); assert.ok(env.params.some(p => p.value === .45));
    assert.equal(await env.api.battleCue('hit'), false);
  });
  await check('Battle voices bounded to eight, with oldest cleaned up', async () => {
    const env = environment(); await env.api.unlock(); await env.api.prepareBattle();
    for (let i = 0; i < 12; i++) { env.tick(); await env.api.battleCue('hit'); }
    const effects = env.started.filter(n => n.buffer.id.startsWith('se')); assert.equal(effects.length, 12); assert.equal(effects.filter(n => !n.stopped).length, 8); assert.ok(effects[0].disconnected);
  });
  await check('Skip cancels live and pending battle sounds while leaving music intact', async () => {
    const env = environment(); env.delay(true); await env.api.unlock(); const cue = env.api.battleCue('hit'); env.api.stopBattleEffects(); env.release(); await flush();
    assert.equal(await cue, false); env.delay(false); env.tick(); await env.api.battleCue('hit'); env.api.stopBattleEffects();
    assert.equal(env.started.filter(n => n.buffer.id.startsWith('se') && !n.stopped).length, 0); assert.equal(env.started.filter(n => n.buffer.id.startsWith('bgm') && !n.stopped).length, 1);
  });
  await check('Slow uncached impact drops instead of sounding after impact', async () => {
    const env = environment(); env.delay(true); await env.api.unlock(); const cue = env.api.battleCue('effective'); env.tick(200); env.release(); await flush(); assert.equal(await cue, false);
  });
  await check('Mute cancels pending sounds and stays remembered', async () => {
    const env = environment(); env.delay(true); await env.api.unlock(); const cue = env.api.battleCue('hit'); await env.controls.toggle.listeners.click(); env.release(); await flush();
    assert.equal(await cue, false); assert.equal(env.storage.get('nxt.dawn.enabled'), 'false'); assert.equal(env.started.length, 0);
  });
  await check('Zero effect volume silences current voices and future effects', async () => {
    const env = environment(); await env.api.unlock(); await env.api.prepareBattle(); await env.api.battleCue('hit'); env.controls['effects-volume'].value = '0'; env.controls['effects-volume'].listeners.input(); env.tick();
    assert.equal(await env.api.battleCue('hit'), false); assert.equal(env.started.filter(n => n.buffer.id.startsWith('se') && !n.stopped).length, 0); assert.equal(env.contexts[0].state, 'running');
  });
  await check('Tab hiding cancels pending cues and returning resumes music', async () => {
    const env = environment(); env.delay(true); await env.api.unlock(); const cue = env.api.battleCue('hit'); env.document.hidden = true; await env.emit('visibilitychange'); env.release(); await flush(); assert.equal(await cue, false);
    env.delay(false); env.document.hidden = false; await env.emit('visibilitychange'); assert.equal(env.contexts[0].state, 'running'); assert.ok(env.started.some(n => n.buffer.id === 'bgm30'));
  });
  await check('Managed command click only confirms; damage waits for contact', async () => {
    const env = environment(); await env.api.unlock(); await env.api.prepareBattle(); const control = { dataset: { dawnSfx: 'hit' }, hasAttribute: () => false, closest: selector => selector === '[data-dawn-managed]' ? {} : null, matches: () => true };
    await env.emit('click', { target: { closest: () => control } }); assert.equal(env.started.filter(n => n.buffer.id === 'se029').length, 0); assert.equal(env.started.filter(n => n.buffer.id === 'se001').length, 1);
  });
  await check('Music errors do not disable healthy battle sound playback', async () => {
    const env = environment(); env.reject('bgm30'); await env.api.unlock(); await env.api.prepareBattle(); await flush(); assert.equal(await env.api.battleCue('heal'), true);
  });
  await check('Manual soundtrack choice persists across battle scene updates', async () => {
    const env = environment(); env.controls.track.value = 'bgm31'; await env.controls.track.listeners.change({ target: env.controls.track }); await flush(); env.api.scene('victory'); await flush();
    assert.equal(env.started.filter(n => n.buffer.id.startsWith('bgm')).at(-1).buffer.id, 'bgm31');
  });
  await check('Unsupported audio and saved mute leave gameplay APIs safe', async () => {
    const unsupported = environment({ audioSupported: false }); assert.equal(await unsupported.api.unlock(), false); assert.equal(await unsupported.api.battleCue('hit'), false);
    const muted = environment({ muted: true }); assert.equal(await muted.api.unlock(), false); assert.equal(muted.requests.length, 0);
  });
  process.stdout.write(`${passed} audio lifecycle checks passed.\n`);
})().catch(error => { console.error(error); process.exitCode = 1; });
