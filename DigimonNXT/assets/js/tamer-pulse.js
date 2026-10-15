/* Keep real tamer actions moving from any signed-in game page. */
(() => {
  'use strict';
  const script = document.querySelector('script[data-tamer-pulse]');
  if (!script || window.nxtTamerPulseStarted) return;
  window.nxtTamerPulseStarted = true;
  let busy = false, stopped = false, timer, failures = 0;
  const status = (title, detail, active) => {
    const box = document.querySelector('.bp-worker');
    if (!box) return;
    box.classList.toggle('is-running', active);
    box.querySelector('strong').textContent = title;
    box.querySelector('small').textContent = detail;
  };
  const pulse = async () => {
    if (busy || stopped) return;
    clearTimeout(timer);
    busy = true;
    let delay = 5000;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(script.dataset.endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'X-CSRF-Token': script.dataset.csrf, 'X-Requested-With': 'fetch'}
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        if (response.status === 401 || response.status === 403) stopped = true;
        throw new Error(data.message || 'Tamer activity is reconnecting.');
      }
      failures = 0;
      delay = Math.max(5000, Number(data.retry_after || 5) * 1000);
      if (data.attention) status('ACTIVITY NEEDS ATTENTION', data.message, false);
      else if (data.last_run) status('TAMERS ACTIVE', 'Last update ' + new Date(data.last_run * 1000).toISOString().slice(11, 19) + ' UTC', true);
      else status('TAMERS STARTING', 'Connecting to the tamer network…', false);
      const mode = document.querySelector('[data-tamer-mode]');
      if (mode) mode.textContent = data.background
        ? 'Background worker connected · tamers keep playing with no players online.'
        : 'Browser activity connected · install the background worker to keep tamers active with every browser closed.';
      if (data.processed > 0) document.dispatchEvent(new CustomEvent('nxt:tamer-activity', {detail: data}));
    } catch (error) {
      failures++;
      delay = Math.min(30000, 5000 * failures);
      status(stopped ? 'RECONNECT REQUIRED' : 'RECONNECTING TAMERS', error.name === 'AbortError' ? 'Connection timed out. Retrying shortly.' : (error.message || 'Retrying shortly.'), false);
    } finally {
      clearTimeout(timeout);
      busy = false;
      if (!stopped) timer = setTimeout(pulse, delay);
    }
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !stopped) pulse(); });
  window.addEventListener('online', () => { if (!stopped) pulse(); });
  // Keep this bounded request alive on hidden tabs when the browser permits it.
  // Multiple tabs and the optional CLI worker share the server's one cadence.
  timer = setTimeout(pulse, 250);
})();
