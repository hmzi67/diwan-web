// Light / dark theme. Loaded synchronously in <head> so data-theme is set
// before first paint (no flash of the wrong theme).
//
// An explicit choice is remembered in localStorage; with no choice we follow
// the device setting and keep following it if it changes. Without JS the
// site simply stays dark (the :root default in styles.css).
(function () {
  const KEY = 'diwan-theme';
  const root = document.documentElement;
  const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;

  function stored() {
    try {
      const v = localStorage.getItem(KEY);
      return v === 'light' || v === 'dark' ? v : null;
    } catch {
      return null; // storage blocked (private mode, disabled cookies)
    }
  }

  function systemTheme() {
    return media && media.matches ? 'light' : 'dark';
  }

  function apply(theme) {
    root.setAttribute('data-theme', theme);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'light' ? '#F5F8F6' : '#0A0F0D');
    document.querySelectorAll('[data-theme-toggle]').forEach(syncButton);
  }

  function syncButton(btn) {
    const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    btn.setAttribute('aria-label', `Switch to ${next} theme`);
    btn.title = `Switch to ${next} theme`;
  }

  apply(stored() || systemTheme());

  if (media) {
    const follow = () => { if (!stored()) apply(systemTheme()); };
    if (media.addEventListener) media.addEventListener('change', follow);
    else if (media.addListener) media.addListener(follow);
  }

  const ICONS =
    '<svg class="theme-toggle__sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>' +
    '<svg class="theme-toggle__moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';

  function makeButton(extraClass) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `theme-toggle${extraClass ? ` ${extraClass}` : ''}`;
    btn.setAttribute('data-theme-toggle', '');
    btn.innerHTML = ICONS;
    btn.addEventListener('click', () => {
      const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      try { localStorage.setItem(KEY, next); } catch { /* still switches for this page */ }
      root.classList.add('theme-switching');
      apply(next);
      window.setTimeout(() => root.classList.remove('theme-switching'), 350);
    });
    syncButton(btn);
    return btn;
  }

  // Where the toggle goes depends on the page layout:
  //   [data-theme-slot]  explicit placement (dashboard sidebar and top bar)
  //   .nav__actions      marketing header with actions (before the menu button)
  //   .nav__inner        simple header (after the links)
  //   otherwise          a floating button (auth pages have no header)
  function mount() {
    const slots = document.querySelectorAll('[data-theme-slot]');
    if (slots.length) {
      slots.forEach((slot) => slot.appendChild(makeButton()));
      return;
    }
    const actions = document.querySelector('.nav__actions');
    if (actions) {
      actions.insertBefore(makeButton(), actions.querySelector('.nav__toggle'));
      return;
    }
    const inner = document.querySelector('.nav__inner');
    if (inner) {
      inner.appendChild(makeButton());
      return;
    }
    document.body.appendChild(makeButton('theme-toggle--floating'));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
