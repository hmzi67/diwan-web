// All backend calls go through /api/* — the only web-accessible PHP entrypoints.
// This file loads FIRST on pages that also load their own script (checkout.js,
// auth.js). Never declare a top-level `const` here whose name a page script also
// declares (`API`, `params`, `form`, …): a duplicate `const` in global scope
// throws SyntaxError and the page script dies with it.

async function postJson(path, body) {
  const res = await fetch('/api' + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

function setStatus(el, message, kind) {
  el.textContent = message;
  el.className = kind === 'ok' ? 'min-h-6 text-[.9rem] text-accent'
    : kind === 'error' ? 'min-h-6 text-[.9rem] text-danger'
    : 'min-h-6 text-[.9rem] text-muted';
}

// Pricing cards: "Get Started" needs a session before it can reach checkout.
// The href on each card already points at /auth/login.html?plan=X, so anyone
// without JS (or if this fetch fails) still lands somewhere correct — this
// listener just skips the login hop for someone already signed in.
document.querySelectorAll('.plan__cta[data-plan]').forEach((link) => {
  link.addEventListener('click', async (e) => {
    e.preventDefault();
    const plan = link.dataset.plan;
    try {
      const res = await fetch('/api/dashboard-data.php', { credentials: 'same-origin' });
      window.location.href = res.ok
        ? `/checkout.html?plan=${encodeURIComponent(plan)}`
        : `/auth/login.html?plan=${encodeURIComponent(plan)}`;
    } catch {
      window.location.href = `/auth/login.html?plan=${encodeURIComponent(plan)}`;
    }
  });
});

const downloadForm = document.getElementById('download-form');
downloadForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const status = document.getElementById('download-status');
  setStatus(status, 'Verifying your licence…');
  try {
    const payload = Object.fromEntries(new FormData(downloadForm));
    const { download_url, expires_at } = await postJson('/issue-download.php', payload);
    setStatus(status, `Licence verified. Your link expires ${expires_at}.`, 'ok');
    window.location.href = download_url;
  } catch (err) {
    setStatus(status, err.message, 'error');
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
    const res = await fetch('/api/dashboard-data.php', { credentials: 'same-origin' });
    if (res.ok) me = await res.json().catch(() => null);
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
    try { await postJson('/logout.php', {}); } catch { /* cookie is gone server-side regardless */ }
    window.location.href = '/auth/login.html';
  });
})();
