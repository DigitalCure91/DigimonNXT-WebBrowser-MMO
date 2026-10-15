(() => {
  'use strict';
  const script = document.currentScript;
  const endpoint = script && script.dataset.endpoint;
  const banner = document.querySelector('[data-world-notice]');
  const message = document.querySelector('[data-world-notice-text]');
  if (!endpoint || !banner || !message) return;
  let busy = false;
  let timer;
  const update = async () => {
    if (busy || document.hidden) return;
    busy = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
      if (response.status === 401) {
        clearInterval(timer);
        message.textContent = 'Your session has ended. Sign in again to continue.';
        banner.hidden = false;
        return;
      }
      if (response.status === 503) {
        message.textContent = 'The server is in maintenance. Please return in a moment.';
        banner.hidden = false;
        return;
      }
      if (!response.ok) return;
      const payload = await response.json();
      if (!payload || !payload.ok) return;
      const notice = payload.notice;
      const text = notice && typeof notice.message === 'string' ? notice.message : '';
      if (message.textContent !== text) message.textContent = text;
      banner.hidden = !text;
    } catch (_) { /* A connection interruption must not disrupt gameplay. */ }
    finally { clearTimeout(timeout); busy = false; }
  };
  timer = setInterval(update, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) update(); });
  update();
})();
