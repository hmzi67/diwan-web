// All backend calls go through DiwanApi (api.js, loaded first). This file
// loads before page scripts (checkout.js, contact.js) in the same global
// scope: never declare a top-level name here that a page script also
// declares (`params`, `form`, …) — a duplicate `const` throws SyntaxError and
// the page script dies with it.

// Pricing cards (rendered by plans.js, so delegated): "Request Access" needs
// a session. Each card's href already points at /auth/login.html?plan=X, so
// without JS (or if this fetch fails) the visitor still lands somewhere
// correct — this only skips the login hop for someone already signed in,
// taking them straight to the request form in their dashboard.
document.addEventListener('click', async (e) => {
  const link = e.target.closest('.plan__cta[data-plan]');
  if (!link || link.closest('.app')) return;   // the dashboard handles its own cards
  e.preventDefault();
  const plan = encodeURIComponent(link.dataset.plan);
  try {
    const { ok } = await DiwanApi.get('/dashboard-data.php');
    window.location.href = ok ? `/dashboard.html?request=${plan}#plan` : `/auth/login.html?plan=${plan}`;
  } catch {
    window.location.href = `/auth/login.html?plan=${plan}`;
  }
});

// Hero loop: plays itself unless the visitor asked for less motion or data. The
// button is its pause control, and stays hidden if this script never runs.
{
  const video = document.getElementById('hero-video');
  const toggle = document.getElementById('hero-video-toggle');
  if (video && toggle) {
    toggle.hidden = false;
    toggle.addEventListener('click', () => (video.paused ? video.play() : video.pause()));
    video.addEventListener('play', () => { toggle.textContent = 'Pause'; });
    video.addEventListener('pause', () => { toggle.textContent = 'Play'; });
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches || navigator.connection?.saveData;
    if (!calm) video.play().catch(() => {});   // refused (e.g. iOS Low Power): poster and Play stay
  }
}

const downloadForm = document.getElementById('download-form');
downloadForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const status = document.getElementById('download-status');
  DiwanApi.setStatus(status, 'Verifying your licence…');
  try {
    const payload = Object.fromEntries(new FormData(downloadForm));
    // Options are "platform:variant" (e.g. "macos:intel"); Android has one build.
    const [platform, variant = ''] = payload.platform.split(':');
    Object.assign(payload, { platform, variant });
    const { download_url, expires_at } = await DiwanApi.postJson('/issue-download.php', payload);
    DiwanApi.setStatus(status, `Licence verified. Your link expires ${expires_at}.`, 'ok');
    window.location.href = download_url;
  } catch (err) {
    DiwanApi.setStatus(status, err.message, 'error');
  }
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

// --- Nav: signed-in state — swap the guest CTAs for an avatar dropdown. ---
// Injected rather than templated because the header is duplicated across 12
// pages (no build-time includes). Guest markup stays the no-JS default.
(async function () {
  const nav = document.getElementById('nav');
  if (!nav) return;
  const host = document.getElementById('nav-toggle')?.parentElement;
  if (!host) return;

  let me = null;
  try {
    const { ok, data } = await DiwanApi.get('/dashboard-data.php');
    if (ok) me = data;
  } catch { /* backend unreachable — stay in guest state */ }
  if (!me || !(me.email || me.name)) return;

  // Scoped to #nav so the pricing cards' own "Get Started" links stay put.
  nav.querySelectorAll('a').forEach((a) => {
    const label = a.textContent.trim();
    if (label === 'Log in' || label === 'Get Started') a.style.display = 'none';
  });

  const initial = ((me.name || me.email).trim()[0] || '?').toUpperCase();
  host.insertAdjacentHTML('afterbegin', `
    <div class="relative">
      <button type="button" id="nav-user-btn" aria-haspopup="true" aria-expanded="false" aria-label="Account menu"
        class="grid size-9 cursor-pointer place-items-center rounded-full bg-[linear-gradient(145deg,var(--color-accent),var(--color-accent-dim))] text-[.85rem] font-extrabold text-accent-ink shadow-[0_0_18px_-4px_var(--color-accent)] transition duration-200 ease-diwan hover:-translate-y-px">${initial}</button>
      <div id="nav-user-menu" hidden
        class="absolute right-0 top-full z-50 mt-2 w-44 rounded-2xl border border-line bg-surface p-1.5 shadow-[0_24px_50px_-20px_rgba(0,0,0,.9)]">
        <a href="/dashboard.html" class="block rounded-xl px-3 py-2 text-[.9rem] text-muted transition hover:bg-surface-hi hover:text-ink">Dashboard</a>
        <button type="button" id="logout-btn"
          class="block w-full cursor-pointer rounded-xl px-3 py-2 text-left text-[.9rem] text-muted transition hover:bg-surface-hi hover:text-ink">Log out</button>
      </div>
    </div>`);

  const btn = document.getElementById('nav-user-btn');
  const menu = document.getElementById('nav-user-menu');
  const setOpen = (open) => {
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', String(open));
  };
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    setOpen(menu.hidden);
  });
  document.addEventListener('click', (e) => {
    if (!menu.hidden && !menu.contains(e.target)) setOpen(false);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });
  document.getElementById('logout-btn').addEventListener('click', async () => {
    try { await DiwanApi.post('/logout.php'); } catch { /* cookie is gone server-side regardless */ }
    window.location.href = '/auth/login.html';
  });
})();
