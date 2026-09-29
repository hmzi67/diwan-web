// Customer dashboard: fetch everything from dashboard-data.php, render it,
// and gate the whole page behind a valid session — no session, no dashboard.
//
// The page is a set of views (Overview, Licences, Get started, Products,
// Pricing, Help) switched by the URL hash, so every sidebar/tab link stays
// inside the dashboard and the browser back button still works.
const API = '/api';
const platforms = { windows: 'Windows', macos: 'macOS', android: 'Android' };
const requestedPlatform = new URLSearchParams(window.location.search).get('platform');
let platform = Object.hasOwn(platforms, requestedPlatform) ? requestedPlatform : 'windows';
const platformQuery = `?platform=${encodeURIComponent(platform)}`;
// macOS ships separate Apple Silicon and Intel builds. Apple Silicon is the
// default (every Mac sold since 2020); detectMacVariant() corrects it where the
// browser can tell (Chromium reports the CPU architecture; Safari does not).
const MAC_VARIANTS = { 'apple-silicon': 'Apple Silicon (M1–M4)', intel: 'Intel' };
let macVariant = 'apple-silicon';
const TRIAL_DAYS = 30;
const DAY_MS = 86400000;

const VIEWS = {
  overview: 'Overview',
  licences: 'Licences',
  setup: 'Get started',
  products: 'Products',
  pricing: 'Pricing',
  help: 'Help',
};
const DEFAULT_VIEW = 'overview';

const ICONS = {
  key: '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3M16 7l3 3M14 9l2 2"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  device: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
  download: '<path d="M12 3v12M7 10l5 5 5-5M4 19h16"/>',
  copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  swap: '<path d="M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
  star: '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
  help: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  check: '<path d="M5 12.5l4.5 4.5L19 7"/>',
  windows: '<path d="M3 5.5 10 4.5v7H3zM12 4.2 21 3v8.5h-9zM3 12.5h7v7L3 18.5zM12 12.5h9V21l-9-1.2z"/>',
  macos: '<path d="M16 3c-1 .1-2.2.8-2.9 1.6-.6.7-1.1 1.8-.9 2.8 1.1 0 2.2-.6 2.9-1.4.6-.8 1-1.9.9-3zM19.5 16.6c-.5 1.2-.8 1.7-1.5 2.7-.9 1.4-2.2 3.1-3.8 3.1-1.4 0-1.8-.9-3.7-.9s-2.3.9-3.7.9c-1.6 0-2.8-1.6-3.7-3C.6 15.5.3 11 1.9 8.6 3 6.9 4.8 5.9 6.5 5.9c1.7 0 2.8.9 4.2.9 1.4 0 2.2-.9 4.2-.9 1.5 0 3.1.8 4.2 2.2-3.7 2-3.1 7.3.4 8.5z"/>',
  android: '<path d="M5 10v6M19 10v6M7 9h10v8a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1zM9 18v3M15 18v3M8 9a4 4 0 0 1 8 0M8 4l1.5 2M16 4l-1.5 2"/>',
};

const icon = (name, size = 18) =>
  `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;

async function apiGet(path) {
  const res = await fetch(API + path, { method: 'GET', credentials: 'same-origin' });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw { status: res.status, message: data.error || 'Request failed' };
  return data;
}

async function apiPost(path, body) {
  const res = await fetch(API + path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw { status: res.status, message: data.error || 'Request failed' };
  return data;
}

const money = (paisa, currency) => {
  if (!paisa) return 'Free';
  const amount = (paisa / 100).toLocaleString('en-PK', { minimumFractionDigits: 0 });
  return currency === 'PKR' ? `Rs ${amount}` : `${currency} ${amount}`;
};

const date = (isoish) => isoish
  ? new Date(String(isoish).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
  : '—';

const unixDate = (seconds) =>
  new Date(seconds * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

const daysLeft = (order) => order.expires_at === null
  ? null
  : Math.max(0, Math.ceil((order.expires_at * 1000 - Date.now()) / DAY_MS));

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function supportMailto(subject, order) {
  const body = `Order reference: ${order.order_ref}\nLicence prefix: ${order.license_key_prefix || 'n/a'}\n\n`;
  return `mailto:hamzawaheed057@gmail.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}

// Which paid edition a licence is, from its product name (dashboard-data.php
// does not return the SKU). Trials and App Store purchases map to none.
function editionOf(order) {
  if (!order || order.gateway === 'trial') return null;
  const name = (order.product_name || '').toLowerCase();
  return ['enterprise', 'standard', 'starter'].find((e) => name.includes(e)) || null;
}

let toastTimer;
function toast(message, kind) {
  const el = document.getElementById('toast');
  el.textContent = message;
  el.className = `toast${kind ? ` toast--${kind}` : ''}`;
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.hidden = true; }, 3200);
}

// --- Router -----------------------------------------------------------------
function currentView() {
  const name = window.location.hash.replace(/^#\/?/, '');
  return Object.hasOwn(VIEWS, name) ? name : DEFAULT_VIEW;
}

function showView(name, { focus = true } = {}) {
  document.querySelectorAll('[data-view]').forEach((view) => {
    view.hidden = view.dataset.view !== name;
  });
  document.querySelectorAll('[data-nav]').forEach((link) => {
    if (link.dataset.nav === name) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  document.title = `${VIEWS[name]} — Diwan POS`;

  // Keep the active tab visible in the scrolling mobile tab bar.
  document.querySelector(`.tabbar [data-nav="${name}"]`)?.scrollIntoView({ block: 'nearest', inline: 'center' });

  window.scrollTo(0, 0);
  if (focus) {
    // Move focus to the view's heading so keyboard and screen-reader users
    // land at the start of the new content.
    document.querySelector(`[data-view="${name}"] h1`)?.focus({ preventScroll: true });
  }
}

// Only view names route; other hashes (e.g. the #main skip link) are ordinary
// in-page anchors and must not switch the view.
window.addEventListener('hashchange', () => {
  const name = window.location.hash.replace(/^#\/?/, '');
  if (Object.hasOwn(VIEWS, name)) showView(name);
});

// --- Header -----------------------------------------------------------------
function displayName(data) {
  if (data.name) return data.name.trim().split(/\s+/)[0];
  const local = (data.email || '').split('@')[0].replace(/[^a-z]+/gi, ' ').trim().split(' ')[0] || '';
  return local ? local.charAt(0).toUpperCase() + local.slice(1) : '';
}

function renderHeader(data) {
  const hour = new Date().getHours();
  const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const name = displayName(data);

  document.getElementById('greeting').textContent = name ? `${part}, ${name}` : part;
  document.getElementById('welcome-email').textContent = `Signed in as ${data.email}`;
  document.getElementById('today').textContent = new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long',
  });

  document.querySelectorAll('[data-email]').forEach((el) => { el.textContent = data.email; });
  document.querySelectorAll('[data-name]').forEach((el) => { el.textContent = data.name || name || 'Your account'; });
  document.querySelectorAll('[data-avatar]').forEach((el) => {
    el.textContent = (data.name || data.email || '?').trim().charAt(0).toUpperCase();
  });
}

// --- Stats ------------------------------------------------------------------
function ring(fraction) {
  const r = 20;
  const c = 2 * Math.PI * r;
  const f = Math.min(1, Math.max(0, fraction));
  return `
    <svg class="dash-ring" width="52" height="52" viewBox="0 0 52 52" aria-hidden="true">
      <circle cx="26" cy="26" r="${r}" class="dash-ring__track"/>
      <circle cx="26" cy="26" r="${r}" class="dash-ring__bar" stroke-dasharray="${c}" stroke-dashoffset="${c * (1 - f)}"/>
    </svg>`;
}

function stat({ iconName, label, value, sub, tone, visual, href }) {
  const tag = href ? 'a' : 'article';
  return `
    <${tag} class="stat${tone ? ` stat--${tone}` : ''}"${href ? ` href="${href}"` : ''}>
      <div class="stat__top">
        <span class="stat__icon">${icon(iconName)}</span>
        <span class="stat__label">${label}</span>
      </div>
      <div class="stat__body">
        <div>
          <div class="stat__value">${value}</div>
          <div class="stat__sub">${sub}</div>
        </div>
        ${visual || ''}
      </div>
    </${tag}>`;
}

function renderStats(licensed, primary) {
  const active = licensed.filter((o) => o.license_status === 'active').length;
  const cards = [];

  cards.push(stat({
    iconName: 'key',
    label: 'Licences',
    value: String(licensed.length),
    sub: licensed.length ? `${active} active` : 'None yet',
    tone: active ? 'ok' : '',
    href: '#licences',
  }));

  if (!primary) {
    cards.push(stat({ iconName: 'clock', label: 'Time left', value: '—', sub: 'No licence yet', href: '#pricing' }));
  } else if (primary.expires_at === null) {
    cards.push(stat({ iconName: 'clock', label: 'Time left', value: 'Lifetime', sub: 'Never expires', tone: 'ok', href: '#licences' }));
  } else {
    const left = daysLeft(primary);
    const total = primary.gateway === 'trial' ? TRIAL_DAYS : Math.max(left, 365);
    cards.push(stat({
      iconName: 'clock',
      label: primary.gateway === 'trial' ? 'Trial left' : 'Time left',
      value: left ? plural(left, 'day') : 'Expired',
      sub: `${left ? 'Ends' : 'Ended'} ${unixDate(primary.expires_at)}`,
      tone: left === 0 ? 'bad' : left <= 7 ? 'warn' : 'ok',
      visual: ring(left / total),
      href: primary.gateway === 'trial' ? '#pricing' : '#licences',
    }));
  }

  const activated = primary && primary.activation_status === 'activated';
  cards.push(stat({
    iconName: 'device',
    label: 'Device',
    value: activated ? 'Activated' : 'Not activated',
    sub: activated
      ? escapeHtml(primary.activated_machine_hint || (primary.activated_at ? `Since ${date(primary.activated_at)}` : 'On one device'))
      : 'Install Diwan and enter your key',
    tone: activated ? 'ok' : 'warn',
    href: '#setup',
  }));

  const used = primary ? Number(primary.downloads_used || 0) : 0;
  const max = primary ? Number(primary.max_downloads || 0) : 0;
  cards.push(stat({
    iconName: 'download',
    label: 'Downloads',
    value: max ? `${used}<small> / ${max}</small>` : String(used),
    sub: max ? `${Math.max(0, max - used)} remaining` : 'Installer downloads',
    visual: max ? `<div class="meter" aria-hidden="true"><span style="width:${Math.min(100, (used / max) * 100)}%"></span></div>` : '',
    href: '#setup',
  }));

  document.getElementById('stats').innerHTML = cards.join('');
}

// --- Progress (overview panel + setup guide) --------------------------------
function progressOf(primary) {
  return {
    key: Boolean(primary),
    download: Boolean(primary && (Number(primary.downloads_used) > 0 || primary.activation_status === 'activated')),
    activate: Boolean(primary && primary.activation_status === 'activated'),
  };
}

function renderProgress(primary) {
  const done = progressOf(primary);
  const mark = (items, key) => {
    let currentMarked = false;
    items.forEach((el) => {
      const isDone = done[el.dataset[key]];
      const isCurrent = !isDone && !currentMarked;
      el.classList.toggle('is-done', isDone);
      el.classList.toggle('is-current', isCurrent);
      if (isCurrent) currentMarked = true;
    });
  };
  mark(document.querySelectorAll('[data-steps] [data-step]'), 'step');
  mark(document.querySelectorAll('[data-guide]'), 'guide');
}

// --- Shared licence pieces --------------------------------------------------
function badgeFor(order) {
  if (order.gateway === 'trial') {
    if (order.license_status === 'expired') return '<span class="badge badge--failed">Trial ended</span>';
    return '<span class="badge badge--trial">Free trial</span>';
  }
  if (order.license_status === 'expired') return '<span class="badge badge--failed">Expired</span>';
  if (order.license_status === 'revoked') return '<span class="badge badge--failed">Revoked</span>';
  const s = order.order_status;
  return `<span class="badge badge--${s}">${s.charAt(0).toUpperCase()}${s.slice(1)}</span>`;
}

function noteFor(order) {
  if (order.gateway === 'trial') {
    if (order.license_status === 'expired') return 'Your free trial has ended. This account has already used its trial.';
    if (order.license_status === 'revoked') return 'This trial licence has been revoked.';
    return '30-day free trial · One device · No payment required';
  }
  if (order.gateway === 'apple') {
    return order.license_status === 'revoked'
      ? 'Refunded through the App Store, so this licence is no longer active.'
      : 'Bought in the iOS app · Use "Restore purchases" in the app to move it to a new iPhone or iPad';
  }
  return '';
}

function keybox(order) {
  const displayKey = order.license_key || `${order.license_key_prefix}-••••-••••-••••`;
  return `
    <div class="keybox">
      <span class="keybox__label">Licence key</span>
      <div class="keybox__row">
        <code class="keybox__key">${escapeHtml(displayKey)}</code>
        ${order.license_key ? `<button class="copy-btn" type="button" data-copy="${escapeHtml(order.license_key)}">${icon('copy', 15)}<span>Copy</span></button>` : ''}
      </div>
    </div>`;
}

function timeline(order) {
  if (order.expires_at === null) return '';
  const left = daysLeft(order);
  const total = order.gateway === 'trial' ? TRIAL_DAYS : Math.max(left, 365);
  const pct = Math.round((left / total) * 100);
  const tone = left === 0 ? 'bad' : left <= 7 ? 'warn' : 'ok';
  return `
    <div class="timeline timeline--${tone}">
      <div class="timeline__labels">
        <span>${left ? `${plural(left, 'day')} left` : 'Expired'}</span>
        <span>${left ? 'Ends' : 'Ended'} ${unixDate(order.expires_at)}</span>
      </div>
      <div class="timeline__bar" role="progressbar" aria-label="Time remaining" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}">
        <span style="width:${pct}%"></span>
      </div>
    </div>`;
}

function details(order) {
  const activated = order.activation_status === 'activated';
  const rows = [
    ['Order', `<code title="${escapeHtml(order.order_ref)}">${escapeHtml(order.order_ref)}</code>`],
    ['Date', date(order.created_at)],
    ['Amount', money(order.amount_paisa, order.currency)],
    ['Device', activated
      ? `<span class="dot dot--ok"></span>${escapeHtml(order.activated_machine_hint || 'Activated')}`
      : '<span class="dot"></span>Not activated'],
    ['Expires', order.expires_at === null ? 'Never' : unixDate(order.expires_at)],
    ['Downloads', order.max_downloads ? `${order.downloads_used || 0} of ${order.max_downloads}` : String(order.downloads_used || 0)],
  ];
  return `<dl class="details">${rows.map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl>`;
}

function resendButton(order) {
  return order.can_resend
    ? `<button class="btn btn--ghost btn--sm" type="button" data-resend="${order.license_id}">${icon('mail', 16)}<span>Email me my key</span></button>`
    : `<a class="btn btn--ghost btn--sm" href="${supportMailto('Recover my Diwan licence key', order)}">${icon('mail', 16)}Contact support to recover key</a>`;
}

function downloadPanel(order, { title = true } = {}) {
  if (order.license_status !== 'active') return '';
  return `
    <form class="dl" data-download>
      ${title ? `<div class="dl__head"><strong>Download Diwan</strong><span>Choose your platform</span></div>` : ''}
      ${order.license_key
        ? `<input type="hidden" name="license_key" value="${escapeHtml(order.license_key)}">`
        : '<input class="input" type="text" name="license_key" aria-label="Licence key" placeholder="Paste your licence key to download" required>'}
      <div class="seg" role="radiogroup" aria-label="Download platform">
        ${Object.entries(platforms).map(([value, label]) => `
          <label class="seg__opt">
            <input type="radio" name="platform" value="${value}" ${value === platform ? 'checked' : ''}>
            <span>${icon(value, 16)}${label}</span>
          </label>`).join('')}
      </div>
      <button class="btn btn--primary dl__btn" type="submit">${icon('download', 16)}<span data-dl-label>Download for ${platforms[platform]}</span></button>
      <div class="dl__variant" data-mac-variant ${platform === 'macos' ? '' : 'hidden'}>
        <div class="seg seg--sm" role="radiogroup" aria-label="Your Mac's chip">
          ${Object.entries(MAC_VARIANTS).map(([value, label]) => `
            <label class="seg__opt">
              <input type="radio" name="variant" value="${value}" ${value === macVariant ? 'checked' : ''}>
              <span>${label}</span>
            </label>`).join('')}
        </div>
        <small>Not sure? Apple menu → <b>About This Mac</b>: “Chip” means Apple Silicon, “Processor” means Intel.</small>
      </div>
      <p role="status" class="download-status"></p>
    </form>`;
}

// --- Licences view ----------------------------------------------------------
function renderOrder(order) {
  const hasLicense = order.license_id !== null;
  let body;

  if (hasLicense) {
    const note = noteFor(order);
    body = `
      ${keybox(order)}
      ${timeline(order)}
      ${details(order)}
      ${note ? `<p class="order-note">${escapeHtml(note)}</p>` : ''}
      <div class="license-actions">
        ${resendButton(order)}
        <a class="btn btn--ghost btn--sm" href="${supportMailto('Move my Diwan licence to a new device', order)}">${icon('swap', 16)}Request device change</a>
      </div>
      ${downloadPanel(order)}`;
  } else if (['paid', 'fulfilled'].includes(order.order_status)) {
    body = '<p class="order-note">Your licence is being issued — refresh in a moment, or contact support if this persists.</p>';
  } else {
    body = '<p class="order-note">A licence key will appear here once payment is confirmed.</p>';
  }

  return `
    <article class="order-card${order.license_status === 'active' ? ' order-card--active' : ''}">
      <header class="order-card__head">
        <div class="order-card__title">
          <span class="order-card__icon">${icon('key', 20)}</span>
          <div>
            <h3 class="order-card__product">${escapeHtml(order.product_name)}</h3>
            <div class="order-card__meta">${date(order.created_at)} · ${money(order.amount_paisa, order.currency)}</div>
          </div>
        </div>
        ${badgeFor(order)}
      </header>
      ${body}
    </article>`;
}

// --- Overview: current licence + quick actions ------------------------------
function renderCurrent(primary) {
  const el = document.getElementById('current');
  if (!primary) {
    el.innerHTML = `
      <div class="dash-empty dash-empty--compact">
        <h3>No licence yet</h3>
        <p>Start a free 30-day trial, or buy a lifetime licence.</p>
        <div class="dash-empty__cta">
          <button class="btn btn--primary btn--sm" type="button" data-start-trial>Start free trial</button>
          <a class="btn btn--ghost btn--sm" href="#pricing">See pricing</a>
        </div>
      </div>`;
    return;
  }
  el.innerHTML = `
    <article class="order-card${primary.license_status === 'active' ? ' order-card--active' : ''}">
      <header class="order-card__head">
        <div class="order-card__title">
          <span class="order-card__icon">${icon('key', 20)}</span>
          <div>
            <h3 class="order-card__product">${escapeHtml(primary.product_name)}</h3>
            <div class="order-card__meta">${date(primary.created_at)} · ${money(primary.amount_paisa, primary.currency)}</div>
          </div>
        </div>
        ${badgeFor(primary)}
      </header>
      ${keybox(primary)}
      ${timeline(primary)}
      <div class="license-actions">
        <a class="btn btn--primary btn--sm" href="#setup">${icon('download', 16)}Download &amp; set up</a>
        <a class="btn btn--ghost btn--sm" href="#licences">Manage licence ${icon('arrow', 16)}</a>
      </div>
    </article>`;
}

function renderQuick(primary) {
  const tiles = [];
  const tile = ({ iconName, title, sub, href, attrs = '' }) => href
    ? `<a class="qa" href="${href}" ${attrs}><span class="qa__icon">${icon(iconName, 20)}</span><span class="qa__text"><strong>${title}</strong><small>${sub}</small></span></a>`
    : `<button class="qa" type="button" ${attrs}><span class="qa__icon">${icon(iconName, 20)}</span><span class="qa__text"><strong>${title}</strong><small>${sub}</small></span></button>`;

  tiles.push(tile({ iconName: 'download', title: 'Download Diwan', sub: 'Windows, macOS or Android', href: '#setup' }));

  if (primary && primary.license_key) {
    tiles.push(tile({ iconName: 'copy', title: 'Copy licence key', sub: 'Paste it into Diwan to activate', attrs: `data-copy="${escapeHtml(primary.license_key)}"` }));
  }
  if (primary && primary.can_resend) {
    tiles.push(tile({ iconName: 'mail', title: 'Email me my key', sub: 'Resend it to your inbox', attrs: `data-resend="${primary.license_id}"` }));
  }
  if (primary) {
    tiles.push(tile({ iconName: 'swap', title: 'Change device', sub: 'Move your licence to a new PC', href: supportMailto('Move my Diwan licence to a new device', primary) }));
  }
  if (!primary || primary.gateway === 'trial') {
    tiles.push(tile({ iconName: 'star', title: 'Upgrade to lifetime', sub: 'One payment, no subscription', href: '#pricing' }));
  }
  tiles.push(tile({ iconName: 'help', title: 'Get help', sub: 'Reply within 1 business day', href: '#help' }));

  document.getElementById('quick').innerHTML = tiles.join('');
}

// --- Get started view -------------------------------------------------------
function renderSetup(primary) {
  const keyEl = document.getElementById('setup-key');
  const dlEl = document.getElementById('setup-download');
  const activateEl = document.getElementById('activate-status');

  if (!primary) {
    keyEl.innerHTML = `<div class="dash-empty dash-empty--compact"><p>You don&rsquo;t have a licence yet.</p><div class="dash-empty__cta"><button class="btn btn--primary btn--sm" type="button" data-start-trial>Start free trial</button><a class="btn btn--ghost btn--sm" href="#pricing">Buy a licence</a></div></div>`;
    dlEl.innerHTML = '<p class="guide__note">Downloads unlock once you have a licence key.</p>';
    activateEl.textContent = '';
    return;
  }

  keyEl.innerHTML = `${keybox(primary)}${primary.can_resend ? `<div class="license-actions">${resendButton(primary)}</div>` : ''}`;
  dlEl.innerHTML = primary.license_status === 'active'
    ? downloadPanel(primary, { title: false })
    : `<p class="guide__note">This licence is ${escapeHtml(primary.license_status)}, so downloads are unavailable. <a href="#pricing">See plans</a> or <a href="#help">contact support</a>.</p>`;

  if (primary.activation_status === 'activated') {
    activateEl.innerHTML = `${icon('check', 16)} Activated on <b>${escapeHtml(primary.activated_machine_hint || 'your device')}</b>${primary.activated_at ? ` since ${date(primary.activated_at)}` : ''}.`;
    activateEl.className = 'guide__note guide__note--ok';
  } else {
    activateEl.textContent = 'Not activated yet — this updates here once Diwan is activated on your device.';
    activateEl.className = 'guide__note';
  }
}

function selectHowto(name) {
  document.querySelectorAll('[data-howto]').forEach((tab) => {
    tab.setAttribute('aria-selected', String(tab.dataset.howto === name));
  });
  document.querySelectorAll('[data-howto-panel]').forEach((panel) => {
    panel.hidden = panel.dataset.howtoPanel !== name;
  });
}

// Choosing a platform anywhere (Products cards, activation tabs) updates
// every download form so the next click downloads the right build.
function choosePlatform(name) {
  if (!Object.hasOwn(platforms, name)) return;
  platform = name;
  document.querySelectorAll('[data-download]').forEach((form) => {
    const radio = form.querySelector(`input[name="platform"][value="${name}"]`);
    if (radio) radio.checked = true;
    const label = form.querySelector('[data-dl-label]');
    if (label) label.textContent = `Download for ${platforms[name]}`;
    const variants = form.querySelector('[data-mac-variant]');
    if (variants) variants.hidden = name !== 'macos';
  });
  selectHowto(name);
}

function chooseMacVariant(value) {
  if (!Object.hasOwn(MAC_VARIANTS, value)) return;
  macVariant = value;
  document.querySelectorAll(`[data-download] input[name="variant"][value="${value}"]`).forEach((radio) => {
    radio.checked = true;
  });
}

async function detectMacVariant() {
  try {
    const ua = navigator.userAgentData;
    if (!ua || ua.platform !== 'macOS' || !ua.getHighEntropyValues) return;
    const { architecture } = await ua.getHighEntropyValues(['architecture']);
    if (architecture === 'x86') chooseMacVariant('intel');
    else if (architecture === 'arm') chooseMacVariant('apple-silicon');
  } catch {
    // Hint unavailable — keep the Apple Silicon default.
  }
}

// --- Products / Pricing: mark the plan this account already owns ------------
function renderPlans(licensed) {
  const owned = new Set(licensed.filter((o) => o.license_status === 'active').map(editionOf).filter(Boolean));
  document.querySelectorAll('[data-edition]').forEach((card) => {
    const isOwned = owned.has(card.dataset.edition);
    card.classList.toggle('is-owned', isOwned);
    const cta = card.querySelector('[data-plan-cta]');
    if (!cta) return;
    if (isOwned) {
      cta.textContent = 'Your current plan';
      cta.setAttribute('aria-disabled', 'true');
      cta.removeAttribute('href');
    }
  });
}

// --- Help: prefill the contact form -----------------------------------------
function prefillContact(data, primary) {
  const form = document.getElementById('contact-form');
  if (!form) return;
  const set = (name, value) => {
    const input = form.elements[name];
    if (input && value) { input.value = value; input.defaultValue = value; }
  };
  set('name', data.name || displayName(data));
  set('email', data.email);
  if (primary) set('order_ref', primary.order_ref);
}

// --- Load -------------------------------------------------------------------
async function load() {
  const loading = document.getElementById('loading');
  const content = document.getElementById('content');

  try {
    const data = await apiGet('/dashboard-data.php');
    const licensed = data.orders.filter((o) => o.license_id !== null);
    // The licence the overview describes: the newest active one, else the newest.
    const primary = licensed.find((o) => o.license_status === 'active') || licensed[0] || null;

    renderHeader(data);
    renderStats(licensed, primary);
    renderProgress(primary);
    renderCurrent(primary);
    renderQuick(primary);
    renderSetup(primary);
    renderPlans(licensed);
    prefillContact(data, primary);
    selectHowto(platform);
    detectMacVariant();

    document.querySelectorAll('[data-upgrade]').forEach((el) => {
      el.hidden = !(primary && primary.gateway === 'trial')
        || licensed.some((o) => o.gateway !== 'trial' && o.license_status === 'active');
    });

    document.querySelectorAll('[data-licence-count]').forEach((el) => {
      el.textContent = String(data.orders.length);
      el.hidden = !data.orders.length;
    });

    if (!data.orders.length) {
      document.getElementById('no-orders').hidden = false;
    } else {
      document.getElementById('orders').innerHTML = data.orders.map(renderOrder).join('');
    }

    loading.hidden = true;
    content.hidden = false;
    showView(currentView(), { focus: false });
  } catch (err) {
    if (err.status === 401) {
      window.location.href = `/auth/login.html${platformQuery}`;
      return;
    }
    loading.hidden = true;
    document.getElementById('load-error-text').textContent =
      err.message || 'Something went wrong loading your account.';
    document.getElementById('load-error').hidden = false;
  }
}

// --- Actions (delegated: the same buttons appear in several views) ----------
document.addEventListener('click', async (e) => {
  const copyBtn = e.target.closest('[data-copy]');
  if (copyBtn) {
    try {
      await navigator.clipboard.writeText(copyBtn.dataset.copy);
      toast('Licence key copied to clipboard', 'ok');
      if (copyBtn.classList.contains('copy-btn')) {
        const label = copyBtn.querySelector('span');
        copyBtn.classList.add('is-copied');
        label.textContent = 'Copied';
        setTimeout(() => { label.textContent = 'Copy'; copyBtn.classList.remove('is-copied'); }, 1600);
      }
    } catch {
      toast('Copy isn’t available here — select the key and copy it manually.', 'error');
    }
    return;
  }

  const resendBtn = e.target.closest('[data-resend]');
  if (resendBtn) {
    if (resendBtn.disabled) return;
    resendBtn.disabled = true;
    try {
      await apiPost('/resend-license-email.php', { license_id: Number(resendBtn.dataset.resend) });
      toast('We’ve emailed your licence key — check your inbox', 'ok');
    } catch (err) {
      toast(err.message || 'Could not send the email. Please try again.', 'error');
    } finally {
      resendBtn.disabled = false;
    }
    return;
  }

  const trialBtn = e.target.closest('[data-start-trial]');
  if (trialBtn) {
    if (trialBtn.disabled) return;
    trialBtn.disabled = true;
    try {
      const { status } = await apiPost('/start-trial.php');
      if (status === 'issued' || status === 'existing_trial' || status === 'paid_license') {
        window.location.reload();   // the new licence renders on the next load
        return;
      }
      toast(status === 'disabled'
        ? 'Free trials are paused right now — see Pricing for a lifetime licence.'
        : 'Could not start your trial. Please contact support.', 'error');
    } catch (err) {
      toast(err.message || 'Could not start your trial. Please try again.', 'error');
    } finally {
      trialBtn.disabled = false;
    }
    return;
  }

  const pick = e.target.closest('[data-pick-platform]');
  if (pick) {
    choosePlatform(pick.dataset.pickPlatform);
    return; // the link's own href (#setup) switches the view
  }

  const howto = e.target.closest('[data-howto]');
  if (howto) {
    selectHowto(howto.dataset.howto);
    return;
  }

  // "Your current plan" buttons have no href; stop them doing anything.
  if (e.target.closest('[aria-disabled="true"]')) e.preventDefault();
});

document.addEventListener('change', (e) => {
  if (e.target.name === 'platform' && e.target.closest('[data-download]')) {
    choosePlatform(e.target.value);
  }
  if (e.target.name === 'variant' && e.target.closest('[data-download]')) {
    chooseMacVariant(e.target.value);
  }
});

document.addEventListener('submit', async (e) => {
  const form = e.target.closest('[data-download]');
  if (!form) return;
  e.preventDefault();
  const button = form.querySelector('button[type="submit"]');
  if (button.disabled) return;
  button.disabled = true;
  const status = form.querySelector('.download-status');
  status.className = 'download-status';
  status.textContent = 'Verifying your licence…';
  try {
    const payload = Object.fromEntries(new FormData(form));
    // Only macOS has more than one build; don't send a Mac chip for Windows.
    if (payload.platform !== 'macos') delete payload.variant;
    const { download_url, expires_at } = await apiPost('/issue-download.php', payload);
    status.textContent = `Licence verified. Your link expires ${expires_at}.`;
    status.className = 'download-status ok';
    window.location.href = download_url;
  } catch (err) {
    status.textContent = err.message;
    status.className = 'download-status error';
  } finally {
    button.disabled = false;
  }
});

document.querySelectorAll('[data-logout]').forEach((btn) => {
  btn.addEventListener('click', async () => {
    try { await apiPost('/logout.php'); } catch { /* clearing client-side regardless */ }
    window.location.href = '/auth/login.html';
  });
});

document.getElementById('retry-btn')?.addEventListener('click', () => window.location.reload());

load();
