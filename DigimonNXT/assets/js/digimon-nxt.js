/* Digimon NXT presentation. Game requests and settlement remain in their own modules. */
(() => {
  'use strict';
  const body = document.body;
  body.classList.add('nxt-js');
  const disclosures = [];
  function disclosure(selector) {
    const button = document.querySelector(selector);
    const panel = button && document.getElementById(button.getAttribute('aria-controls'));
    if (!button || !panel) return;
    const set = open => {
      button.setAttribute('aria-expanded', String(open));
      panel.classList.toggle('is-open', open);
      const sign = button.querySelector('b');
      if (sign) sign.textContent = open ? '−' : '+';
    };
    button.addEventListener('click', () => set(button.getAttribute('aria-expanded') !== 'true'));
    panel.addEventListener('click', event => { if (event.target.closest('a')) set(false); });
    disclosures.push({button, panel, set});
  }
  disclosure('[data-pv-nav-toggle]');
  disclosure('[data-nxt-side-toggle]');
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    disclosures.forEach(({button, panel, set}) => {
      if (button.getAttribute('aria-expanded') === 'true') {
        const inside = panel.contains(document.activeElement);
        set(false);
        if (inside) button.focus();
      }
    });
  });

  const motion = document.querySelector('[data-nxt-motion]');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  let sessionPaused = false;
  try { sessionPaused = sessionStorage.getItem('nxt-motion-paused') === '1'; } catch (_) {}
  const accountEnabled = body.dataset.motionPreference !== 'off';
  function syncMotion() {
    const enabled = accountEnabled && !sessionPaused && !reduced.matches;
    body.classList.toggle('nxt-motion-paused', !enabled);
    if (motion) {
      motion.setAttribute('aria-pressed', String(enabled));
      motion.querySelector('b').textContent = enabled ? 'on' : 'off';
      motion.disabled = reduced.matches || !accountEnabled;
      motion.title = reduced.matches ? 'Reduced motion is enabled on your device.' : !accountEnabled ? 'Enable interface motion in Preferences.' : 'Pause or resume visual effects for this session';
    }
  }
  if (motion) motion.addEventListener('click', () => {
    sessionPaused = !sessionPaused;
    try { sessionStorage.setItem('nxt-motion-paused', sessionPaused ? '1' : '0'); } catch (_) {}
    syncMotion();
  });
  if (reduced.addEventListener) reduced.addEventListener('change', syncMotion);
  syncMotion();
  const visibility = () => body.classList.toggle('nxt-tab-hidden', document.hidden);
  document.addEventListener('visibilitychange', visibility);
  visibility();

  // Bound failed artwork without changing its path or hiding useful alternative text.
  document.querySelectorAll('img').forEach(img => {
    img.addEventListener('error', () => img.classList.add('dn-image-missing'), {once: true});
  });
  // Associate existing field labels while retaining every controller's field names.
  document.querySelectorAll('.pv-field').forEach((field, index) => {
    const label = field.querySelector('label');
    const input = field.querySelector('input:not([type="hidden"]),select,textarea');
    if (label && input && !label.htmlFor && !label.contains(input)) {
      if (!input.id) input.id = `nxt-field-${index}`;
      label.htmlFor = input.id;
    }
  });
  document.querySelectorAll('.pv-flash').forEach(flash => {
    if (!flash.hasAttribute('role')) flash.setAttribute('role', flash.classList.contains('error') ? 'alert' : 'status');
  });
})();
