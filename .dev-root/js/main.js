// All backend calls go through /api/* — the only web-accessible PHP entrypoints.
const API = '/api';

function setStatus(el, message, kind) {
  el.textContent = message;
  el.className = kind || '';
}

// TEMPORARY trial onboarding. Issuance happens only on signup or this explicit
// user action, never from a dashboard GET. Platform is navigation context only.
document.querySelectorAll('[data-platform]').forEach((link) => {
  link.addEventListener('click', async (e) => {
    e.preventDefault();
    if (link.getAttribute('aria-busy') === 'true') return;
    link.setAttribute('aria-busy', 'true');
    const platform = link.dataset.platform;
    const query = `?platform=${encodeURIComponent(platform)}`;
    const status = document.getElementById('platform-status');
    setStatus(status, 'Opening your download…');
    try {
      const res = await fetch(API + '/start-trial.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      });
      if (res.status === 401) {
        window.location.href = `/auth/sign-in.html${query}`;
        return;
      }
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || 'Could not open your download. Please try again.');
      window.location.href = `/dashboard.html${query}`;
    } catch (err) {
      setStatus(status, err.message, 'error');
    } finally {
      link.removeAttribute('aria-busy');
    }
  });
});

// --- Nav: mobile menu + stuck-state border. No library, no dependencies. ---
(function () {
  const toggle = document.getElementById('nav-toggle');
  const links  = document.getElementById('nav-links');
  const nav    = document.getElementById('nav');
  const mobile = () => window.matchMedia('(max-width: 860px)').matches;

  function close() {
    if (!links || !toggle) return;
    links.hidden = mobile();
    toggle.setAttribute('aria-expanded', 'false');
  }

  toggle?.addEventListener('click', () => {
    const open = links.hidden;
    links.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
  });

  links?.addEventListener('click', (e) => { if (e.target.tagName === 'A') close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  window.addEventListener('resize', close);
  close();

  // Border appears only once the page has scrolled past the nav.
  if (nav) {
    const onScroll = () => nav.setAttribute('data-stuck', String(window.scrollY > 8));
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }
})();
