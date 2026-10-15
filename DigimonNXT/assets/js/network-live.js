/* Refresh actual activity and standings without interrupting match forms or reports. */
(() => {
  'use strict';
  if (!document.querySelector('.bp-feed, .bp-leaderboard')) return;
  let busy = false, lastRefresh = 0;
  const refresh = async () => {
    if (busy || document.hidden || Date.now() - lastRefresh < 8000) return;
    busy = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(location.href, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {'X-Requested-With': 'fetch'}});
      if (!response.ok || new URL(response.url).pathname !== location.pathname) return;
      const html = new DOMParser().parseFromString(await response.text(), 'text/html');
      for (const selector of ['.bp-stats', '.bp-worker', '[data-tamer-mode]', '.bp-rank-emblem', '.bp-leaderboard', '.bp-history']) {
        const next = html.querySelector(selector), current = document.querySelector(selector);
        if (next && current && !current.contains(document.activeElement)) {
          const top = current.scrollTop;
          current.replaceWith(next);
          next.scrollTop = top;
        }
      }
      lastRefresh = Date.now();
      const page = Number(new URL(location.href).searchParams.get('page') || 1);
      if (page === 1 && !document.querySelector('.bp-feed details[open]') && !document.querySelector('.bp-feed :focus')) {
        for (const selector of ['.bp-feed', '.bp-pager']) {
          const next = html.querySelector(selector), current = document.querySelector(selector);
          if (next && current && next.innerHTML !== current.innerHTML) current.replaceWith(next);
        }
        const empty = document.querySelector('.bp-feed')?.previousElementSibling;
        if (document.querySelector('.bp-feed article') && empty?.classList.contains('pv-empty')) empty.remove();
      }
    } catch (_) { /* Retain the last confirmed activity while reconnecting. */ }
    finally { clearTimeout(timeout); busy = false; }
  };
  setInterval(refresh, 15000);
  document.addEventListener('nxt:tamer-activity', refresh);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
