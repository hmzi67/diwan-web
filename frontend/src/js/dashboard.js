// Customer dashboard: fetch everything from dashboard-data.php, render it,
// and gate the whole page behind a valid session — no session, no dashboard.
//
// The page is a set of views (Overview, Plan & access, Licences, Get started,
// Products, Pricing, Help) switched by the URL hash, so every sidebar/tab link stays
// inside the dashboard and the browser back button still works.
const platforms = { windows: 'Windows', macos: 'macOS', linux: 'Linux', android: 'Android' };
const requestedPlatform = new URLSearchParams(window.location.search).get('platform');
let platform = Object.hasOwn(platforms, requestedPlatform) ? requestedPlatform : 'windows';
const platformQuery = `?platform=${encodeURIComponent(platform)}`;
// Platforms that ship more than one build, and the choice each one offers.
// Keys must match DownloadService::PLATFORMS / VARIANTS in the backend, which
// issue-download.php validates against.
// The first option is the default: 64-bit Windows, Apple Silicon (every Mac
// sold since 2020), and AppImage (runs on any distribution). detectVariants()
// corrects Windows and macOS where the browser can tell — Chromium reports the
// CPU architecture and bitness; Safari and Firefox do not.
const VARIANTS = {
  windows: {
    label: 'Windows version',
    options: { x64: '64-bit', x86: '32-bit' },
    hint: 'Not sure? Settings → System → About: <b>System type</b> says 64-bit or 32-bit.',
  },
  macos: {
    label: "Your Mac's chip",
    options: { 'apple-silicon': 'Apple Silicon (M1–M4)', intel: 'Intel' },
    hint: 'Not sure? Apple menu → <b>About This Mac</b>: “Chip” means Apple Silicon, “Processor” means Intel.',
  },
  linux: {
    label: 'Package format',
    options: { appimage: 'AppImage', deb: '.deb', rpm: '.rpm' },
    hint: '<b>AppImage</b> runs on any distribution. <b>.deb</b> for Ubuntu/Debian, <b>.rpm</b> for Fedora/RHEL.',
  },
};
const selectedVariant = Object.fromEntries(Object.entries(VARIANTS).map(([p, v]) => [p, Object.keys(v.options)[0]]));
const DAY_MS = 86400000;

const VIEWS = {
  overview: 'Overview',
  plan: 'Plan & access',
  records: 'Store records',
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
  linux: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M12 15h5"/>',
  android: '<path d="M5 10v6M19 10v6M7 9h10v8a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1zM9 18v3M15 18v3M8 9a4 4 0 0 1 8 0M8 4l1.5 2M16 4l-1.5 2"/>',
};

const icon = (name, size = 18) =>
  `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;

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

// A licence's full term in days, from its order date to its expiry — so the
// progress ring is right for a trial or any fixed-term licence without the
// page having to know how long each kind lasts.
const termDays = (order) => Math.max(1, Math.round(
  (order.expires_at * 1000 - new Date(String(order.created_at).replace(' ', 'T')).getTime()) / DAY_MS
) || 1);

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

// What a licence card is called: the plan for an approved plan licence, the
// product otherwise (licences bought or trialled before plans existed).
function productLabel(order) {
  return order.plan_name ? `Diwan POS — ${order.plan_name} plan` : order.product_name;
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
    cards.push(stat({ iconName: 'clock', label: 'Time left', value: '—', sub: 'No licence yet', href: '#plan' }));
  } else if (primary.expires_at === null) {
    cards.push(stat({ iconName: 'clock', label: 'Time left', value: 'Lifetime', sub: 'Never expires', tone: 'ok', href: '#licences' }));
  } else {
    const left = daysLeft(primary);
    const total = Math.max(left, termDays(primary));
    cards.push(stat({
      iconName: 'clock',
      label: primary.gateway === 'trial' ? 'Trial left' : 'Time left',
      value: left ? plural(left, 'day') : 'Expired',
      sub: `${left ? 'Ends' : 'Ended'} ${unixDate(primary.expires_at)}`,
      tone: left === 0 ? 'bad' : left <= 7 ? 'warn' : 'ok',
      visual: ring(left / total),
      href: primary.gateway === 'trial' || primary.plan_name ? '#plan' : '#licences',
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
  if (order.license_status === 'suspended') return '<span class="badge badge--failed">Suspended</span>';
  if (order.gateway === 'trial') {
    if (order.license_status === 'expired') return '<span class="badge badge--failed">Trial ended</span>';
    return '<span class="badge badge--trial">Free trial</span>';
  }
  if (order.license_status === 'expired') return '<span class="badge badge--failed">Expired</span>';
  if (order.license_status === 'revoked') return '<span class="badge badge--failed">Revoked</span>';
  const s = order.gateway === 'admin' ? 'paid' : order.order_status;
  const label = order.gateway === 'admin' ? 'Active' : `${s.charAt(0).toUpperCase()}${s.slice(1)}`;
  return `<span class="badge badge--${s}">${label}</span>`;
}

function noteFor(order) {
  if (order.gateway === 'trial') {
    if (order.license_status === 'expired') return 'Your free trial has ended. This account has already used its trial.';
    if (order.license_status === 'revoked') return 'This trial licence has been revoked.';
    return '30-day free trial · One device · No payment required';
  }
  if (order.gateway === 'admin') {
    return `${order.plan_name || 'Plan'} licence · approved by the Diwan team · up to ${plural(Number(order.max_devices || 1), 'device')}`;
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
  const total = Math.max(left, termDays(order));
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
      ${Object.entries(VARIANTS).map(([name, { label, options, hint }]) => `
        <div class="dl__variant" data-variant-for="${name}" ${platform === name ? '' : 'hidden'}>
          <div class="seg seg--sm" role="radiogroup" aria-label="${label}">
            ${Object.entries(options).map(([value, text]) => `
              <label class="seg__opt">
                <input type="radio" name="variant_${name}" value="${value}" ${value === selectedVariant[name] ? 'checked' : ''}>
                <span>${text}</span>
              </label>`).join('')}
          </div>
          <small>${hint}</small>
        </div>`).join('')}
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
            <h3 class="order-card__product">${escapeHtml(productLabel(order))}</h3>
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
        <p>Request a plan — once our team approves it, your licence key appears here.</p>
        <div class="dash-empty__cta">
          <a class="btn btn--primary btn--sm" href="#plan">Request a plan</a>
          <a class="btn btn--ghost btn--sm" href="#pricing">Compare plans</a>
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
            <h3 class="order-card__product">${escapeHtml(productLabel(primary))}</h3>
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

  tiles.push(tile({ iconName: 'download', title: 'Download Diwan', sub: 'Windows, macOS, Linux or Android', href: '#setup' }));

  if (primary && primary.license_key) {
    tiles.push(tile({ iconName: 'copy', title: 'Copy licence key', sub: 'Paste it into Diwan to activate', attrs: `data-copy="${escapeHtml(primary.license_key)}"` }));
  }
  if (primary && primary.can_resend) {
    tiles.push(tile({ iconName: 'mail', title: 'Email me my key', sub: 'Resend it to your inbox', attrs: `data-resend="${primary.license_id}"` }));
  }
  if (primary) {
    tiles.push(tile({ iconName: 'swap', title: 'Change device', sub: 'Move your licence to a new PC', href: supportMailto('Move my Diwan licence to a new device', primary) }));
  }
  tiles.push(tile({
    iconName: 'star',
    title: primary && primary.plan_name ? 'Change plan' : 'Request a plan',
    sub: 'No online payment — we approve it',
    href: '#plan',
  }));
  tiles.push(tile({ iconName: 'help', title: 'Get help', sub: 'Reply within 1 business day', href: '#help' }));

  document.getElementById('quick').innerHTML = tiles.join('');
}

// --- Get started view -------------------------------------------------------
function renderSetup(primary) {
  const keyEl = document.getElementById('setup-key');
  const dlEl = document.getElementById('setup-download');
  const activateEl = document.getElementById('activate-status');

  if (!primary) {
    keyEl.innerHTML = `<div class="dash-empty dash-empty--compact"><p>You don&rsquo;t have a licence yet.</p><div class="dash-empty__cta"><a class="btn btn--primary btn--sm" href="#plan">Request a plan</a></div></div>`;
    dlEl.innerHTML = '<p class="guide__note">Downloads unlock once you have a licence key.</p>';
    activateEl.textContent = '';
    return;
  }

  keyEl.innerHTML = `${keybox(primary)}${primary.can_resend ? `<div class="license-actions">${resendButton(primary)}</div>` : ''}`;
  dlEl.innerHTML = primary.license_status === 'active'
    ? downloadPanel(primary, { title: false })
    : `<p class="guide__note">This licence is ${escapeHtml(primary.license_status)}, so downloads are unavailable. <a href="#plan">See your plan</a> or <a href="#help">contact support</a>.</p>`;

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
    form.querySelectorAll('[data-variant-for]').forEach((group) => {
      group.hidden = group.dataset.variantFor !== name;
    });
  });
  selectHowto(name);
}

function chooseVariant(name, value) {
  if (!Object.hasOwn(VARIANTS, name) || !Object.hasOwn(VARIANTS[name].options, value)) return;
  selectedVariant[name] = value;
  document.querySelectorAll(`[data-download] input[name="variant_${name}"][value="${value}"]`).forEach((radio) => {
    radio.checked = true;
  });
}

async function detectVariants() {
  try {
    const ua = navigator.userAgentData;
    if (!ua || !ua.getHighEntropyValues) return;
    const { architecture, bitness } = await ua.getHighEntropyValues(['architecture', 'bitness']);
    if (ua.platform === 'macOS') {
      if (architecture === 'x86') chooseVariant('macos', 'intel');
      else if (architecture === 'arm') chooseVariant('macos', 'apple-silicon');
    } else if (ua.platform === 'Windows' && architecture === 'x86' && bitness === '32') {
      chooseVariant('windows', 'x86');
    }
  } catch {
    // Hint unavailable — keep the defaults.
  }
}

// --- Plan & access ------------------------------------------------------------
// Everything here comes from dashboard-data.php's `plan`, `notifications`
// and `downloads`. Requests go to license-requests.php; nothing on this page
// can issue or change a licence — only an administrator's approval does.
const REQUEST_BADGE = {
  pending: ['pending', 'Waiting for approval'],
  approved: ['paid', 'Approved'],
  rejected: ['failed', 'Not approved'],
  cancelled: ['refunded', 'Cancelled'],
};
const PLAN_BADGE = {
  active: ['paid', 'Active'],
  suspended: ['failed', 'Suspended'],
  expired: ['failed', 'Expired'],
};
const AVAILABILITY = { app: 'In the Diwan app', service: 'From the Diwan team', planned: 'Coming soon' };
const DOWNLOAD_STATUS = { requested: 'Link issued', served: 'Downloaded', completed: 'Downloaded', failed: 'Not completed' };
let requestedPlan = new URLSearchParams(window.location.search).get('request');

function badge([tone, label]) {
  return `<span class="badge badge--${tone}">${label}</span>`;
}

function renderPlanCurrent(current) {
  const el = document.getElementById('plan-current');
  if (!current) {
    el.innerHTML = `
      <div class="dash-empty dash-empty--compact">
        <h3>No plan yet</h3>
        <p>Choose a plan below and send a request. Once it is approved, your licence key appears under Licences.</p>
      </div>`;
    return;
  }
  const groups = Object.entries(AVAILABILITY).map(([key, title]) => {
    const items = current.features.filter((f) => f.availability === key);
    return items.length ? `
      <div class="feature-group feature-group--${key}">
        <h4>${title}</h4>
        <ul class="plan__features">${items.map((f) => `<li title="${escapeHtml(f.description || '')}">${escapeHtml(f.name)}</li>`).join('')}</ul>
      </div>` : '';
  }).join('');
  const expiry = current.expires_at === null
    ? 'No expiry date'
    : `${current.status === 'expired' ? 'Expired' : 'Renews or expires'} ${unixDate(current.expires_at)}`;
  el.innerHTML = `
    <article class="plan-summary plan--${escapeHtml(current.theme)}">
      <header class="plan-summary__head">
        <div>
          <p class="plan__tag">${escapeHtml(current.plan_name)}</p>
          <p class="plan-summary__meta">${expiry} · ${current.devices_used} of ${plural(current.max_devices, 'device')} in use${current.activated ? '' : ' · not activated yet'}</p>
        </div>
        ${badge(PLAN_BADGE[current.status] || ['failed', escapeHtml(current.status)])}
      </header>
      ${current.status === 'suspended' ? '<p class="order-note">This licence is suspended, so the app has stopped billing. Please <a href="#help">contact support</a>.</p>' : ''}
      ${current.status === 'expired' ? '<p class="order-note">This plan has expired. Request a renewal below to switch billing back on.</p>' : ''}
      <div class="feature-groups">${groups}</div>
    </article>`;
}

function renderPlanRequest(plan) {
  const el = document.getElementById('plan-request');
  const pending = plan.requests.find((r) => r.status === 'pending');
  if (pending) {
    el.innerHTML = `
      <article class="order-card">
        <header class="order-card__head">
          <div class="order-card__title">
            <span class="order-card__icon">${icon('clock', 20)}</span>
            <div>
              <h3 class="order-card__product">${escapeHtml(pending.plan_name)} plan requested</h3>
              <div class="order-card__meta">Sent ${date(pending.created_at)}</div>
            </div>
          </div>
          ${badge(REQUEST_BADGE.pending)}
        </header>
        <p class="order-note">Our team is reviewing your request — we'll notify you here and by email. Nothing is charged online.</p>
        <div class="license-actions">
          <button class="btn btn--ghost btn--sm" type="button" data-cancel-request="${pending.id}">Cancel request</button>
        </div>
      </article>`;
    return;
  }
  const currentCode = plan.current && plan.current.status !== 'expired' ? plan.current.plan_code : null;
  const choice = plan.plans.some((p) => p.code === requestedPlan) ? requestedPlan
    : (plan.current ? plan.current.plan_code : plan.plans[0]?.code);
  el.innerHTML = `
    <form class="panel request-form" data-plan-request novalidate>
      <fieldset class="plan-pick">
        <legend class="sr-only">Plan</legend>
        ${plan.plans.map((p) => `
          <label class="plan-pick__opt plan--${escapeHtml(p.theme)}">
            <input type="radio" name="plan" value="${escapeHtml(p.code)}" ${p.code === choice ? 'checked' : ''}>
            <span>
              <strong>${escapeHtml(p.name)}${p.code === currentCode ? ' <small>(current — renew)</small>' : ''}</strong>
              <small>${DiwanPlans.formatPrice(p.price_minor, p.currency)} per ${escapeHtml(p.billing_period)}</small>
            </span>
          </label>`).join('')}
      </fieldset>
      <div class="request-form__row">
        <label class="field">Business name
          <input class="input" name="business_name" maxlength="160" autocomplete="organization">
        </label>
        <label class="field">Phone (optional)
          <input class="input" name="contact_phone" maxlength="20" inputmode="tel" autocomplete="tel">
        </label>
      </div>
      <label class="field">Anything we should know? (optional)
        <textarea class="input" name="message" rows="3" maxlength="1000"></textarea>
      </label>
      <div class="request-form__foot">
        <p class="panel__note">No payment is taken online. We'll review your request and contact you.</p>
        <button class="btn btn--primary" type="submit">Send request</button>
      </div>
    </form>`;
}

function renderPlanRequests(requests) {
  const el = document.getElementById('plan-requests');
  if (!requests.length) {
    el.innerHTML = '<p class="order-note">No requests yet.</p>';
    return;
  }
  el.innerHTML = `
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Plan</th><th>Sent</th><th>Status</th><th>Note from Diwan</th></tr></thead>
        <tbody>${requests.map((r) => `
          <tr>
            <td>${escapeHtml(r.plan_name)}</td>
            <td>${date(r.created_at)}</td>
            <td>${badge(REQUEST_BADGE[r.status] || ['refunded', escapeHtml(r.status)])}</td>
            <td>${r.decision_note ? escapeHtml(r.decision_note) : '<span class="text-faint">—</span>'}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

function renderNotices(notifications) {
  const list = document.getElementById('notices');
  list.innerHTML = notifications.items.length
    ? notifications.items.map((n) => `
        <li class="notice${n.is_read ? '' : ' is-unread'}">
          <strong>${escapeHtml(n.title)}</strong>
          <p>${escapeHtml(n.body).replace(/\n/g, '<br>')}</p>
          <small>${date(n.created_at)}</small>
        </li>`).join('')
    : '<li class="notice"><p>Nothing yet. Updates about your plan appear here.</p></li>';
  document.querySelector('[data-mark-read]').hidden = !notifications.unread;
  document.querySelectorAll('[data-unread]').forEach((el) => {
    el.textContent = String(notifications.unread);
    el.hidden = !notifications.unread;
  });
}

function renderMyDownloads(downloads) {
  document.getElementById('my-downloads').innerHTML = downloads.length
    ? downloads.map((d) => `
        <li class="notice">
          <strong>${escapeHtml(platforms[d.platform] || d.platform || '')}${d.variant ? ` · ${escapeHtml(d.variant)}` : ''}${d.version ? ` · ${escapeHtml(d.version)}` : ''}</strong>
          <small>${DOWNLOAD_STATUS[d.status] || escapeHtml(d.status)} · ${date(d.created_at)}</small>
        </li>`).join('')
    : '<li class="notice"><p>No downloads yet.</p></li>';
}

// Products and Pricing show the same live cards; the button opens the request
// form with that plan picked.
function renderPlanCards(plan) {
  const current = plan.current && plan.current.status === 'active' ? plan.current.plan_code : null;
  document.querySelectorAll('[data-dash-plans]').forEach((container) => {
    DiwanPlans.render(container, plan.plans, {
      current,
      cta: (p) => ({ href: '#plan', label: plan.current ? `Switch to ${p.name}` : p.cta_label, attrs: `data-request-plan="${escapeHtml(p.code)}"` }),
    });
  });
}

function renderPlanView(data) {
  renderPlanCurrent(data.plan.current);
  renderPlanRequest(data.plan);
  renderPlanRequests(data.plan.requests);
  renderNotices(data.notifications);
  renderMyDownloads(data.downloads);
  renderPlanCards(data.plan);
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


// --- Store Records & Synced POS Data ----------------------------------------
function renderStoreRecords(pos, customerId) {
  const noDataEl = document.getElementById('pos-no-data');
  const dataEl = document.getElementById('pos-data');
  const badgeEl = document.querySelector('[data-pos-badge]');

  if (!pos || !pos.has_synced_data) {
    if (noDataEl) noDataEl.hidden = false;
    if (dataEl) dataEl.hidden = true;
    if (badgeEl) badgeEl.hidden = true;
    return;
  }

  if (noDataEl) noDataEl.hidden = true;
  if (dataEl) dataEl.hidden = false;
  if (badgeEl) {
    badgeEl.textContent = 'Live';
    badgeEl.hidden = false;
  }

  // Summary Metrics
  const today = pos.summary?.today || {};
  const month = pos.summary?.month || {};
  const allTime = pos.summary?.all_time || {};

  const setTxt = (sel, val) => {
    const el = document.querySelector(sel);
    if (el) el.textContent = val;
  };

  setTxt('[data-pos-today-sales]', money(today.total_sales_minor, 'PKR'));
  setTxt('[data-pos-today-txns]', `${today.transaction_count || 0} orders`);
  setTxt('[data-pos-month-sales]', money(month.total_sales_minor, 'PKR'));
  setTxt('[data-pos-month-txns]', `${month.transaction_count || 0} orders`);
  setTxt('[data-pos-alltime-sales]', money(allTime.total_sales_minor, 'PKR'));
  setTxt('[data-pos-alltime-txns]', `${allTime.transaction_count || 0} orders`);
  setTxt('[data-pos-cash-sales]', `Cash: ${money(today.cash_minor, 'PKR')}`);
  setTxt('[data-pos-card-sales]', `Card: ${money(today.card_minor, 'PKR')}${
    today.credit_minor > 0 ? ` · Pay later: ${money(today.credit_minor, 'PKR')}` : ''
  }`);

  // Devices List
  const devicesList = document.getElementById('pos-devices-list');
  if (devicesList) {
    devicesList.innerHTML = (pos.devices || []).map((d) => `
      <div class="order-card" style="padding: 1rem 1.25rem;">
        <div class="order-card__head" style="margin-bottom: 0;">
          <div class="order-card__title">
            <div class="order-card__icon">${icon('device', 18)}</div>
            <div>
              <h3 class="order-card__product" style="font-size: 0.95rem;">${escapeHtml(d.device_name)}</h3>
              <div class="order-card__meta" style="font-size: 0.8rem;">Fingerprint: <code>${escapeHtml(d.device_fingerprint.slice(0, 16))}…</code></div>
            </div>
          </div>
          <span class="badge badge--active" style="font-size: 0.76rem;"><span class="dot dot--ok"></span>Synced ${d.last_sync_at}</span>
        </div>
      </div>
    `).join('') || '<p class="order-note">No terminals connected.</p>';
  }

  // Recent Sales Table
  const recentTbody = document.getElementById('pos-recent-sales-tbody');
  if (recentTbody) {
    recentTbody.innerHTML = (pos.recent_sales || []).map((s) => `
      <tr>
        <td><code>#${s.local_sale_id}</code>${s.order_no ? ` <small style="color:var(--color-muted);">(#${s.order_no})</small>` : ''}</td>
        <td><small>${escapeHtml(s.sale_created_at)}</small></td>
        <td>${escapeHtml(s.cashier_name || '—')}${s.table_name ? ` <small style="color:var(--color-muted);">(${escapeHtml(s.table_name)})</small>` : ''}</td>
        <td><strong>${s.items_count} items</strong>${s.items_summary ? `<br><small style="color:var(--color-muted); font-size: 0.78rem;">${escapeHtml(s.items_summary)}</small>` : ''}</td>
        <td>
          <span class="badge--method badge--${escapeHtml(s.payment_method)}">${escapeHtml(POS_METHOD_LABELS[s.payment_method] || s.payment_method)}</span>
          ${s.credit_minor > 0 ? `<br><small style="color:var(--color-warn);">${money(s.credit_minor, s.currency)} on credit</small>` : ''}
          ${s.customer_name ? `<br><small style="color:var(--color-muted);">${escapeHtml(s.customer_name)}</small>` : ''}
        </td>
        <td style="text-align: right; font-weight: 700;">${money(s.total_minor, s.currency)}</td>
      </tr>
    `).join('') || '<tr><td colspan="6" style="text-align:center; padding: 2rem; color:var(--color-muted);">No transactions recorded yet.</td></tr>';
  }

  // Daily Trends Table
  const trendsTbody = document.getElementById('pos-daily-trends-tbody');
  if (trendsTbody) {
    trendsTbody.innerHTML = (pos.daily_trends || []).map((t) => `
      <tr>
        <td><strong>${escapeHtml(t.summary_date)}</strong></td>
        <td>${t.transaction_count}</td>
        <td>${money(t.cash_minor, t.currency)}</td>
        <td>${money(t.card_minor, t.currency)}</td>
        <td style="color: var(--color-warn);">${t.refunds_minor > 0 ? `-${money(t.refunds_minor, t.currency)}` : '—'}</td>
        <td style="text-align: right; font-weight: 700; color: var(--color-accent);">${money(t.net_sales_minor, t.currency)}</td>
      </tr>
    `).join('') || '<tr><td colspan="6" style="text-align:center; padding: 2rem; color:var(--color-muted);">No sales history recorded yet.</td></tr>';
  }

  renderAllPosRecords(pos.records || {});
}

const posRecordState = { records: {}, entity: '', search: '', from: '', to: '' };

/** Display names for a synced sale's payment_method ("split" = several
 * tenders, "credit" = pay later). Anything else is shown as sent. */
const POS_METHOD_LABELS = { split: 'Split', credit: 'Pay later', platform: 'Platform' };

function posRecordLabel(entity) {
  return entity.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function renderAllPosRecords(records) {
  posRecordState.records = records && typeof records === 'object' ? records : {};
  const select = document.getElementById('pos-record-entity');
  if (!select) return;
  const entities = Object.keys(posRecordState.records).sort();
  select.innerHTML = '<option value="">All record types</option>' +
    entities.map((entity) => `<option value="${escapeHtml(entity)}">${escapeHtml(posRecordLabel(entity))}</option>`).join('');
  renderFilteredPosRecords();
}

function renderFilteredPosRecords() {
  const thead = document.getElementById('pos-records-thead');
  const tbody = document.getElementById('pos-records-tbody');
  const empty = document.getElementById('pos-record-empty');
  const count = document.querySelector('[data-pos-record-count]');
  if (!thead || !tbody) return;

  const selected = posRecordState.entity
    ? { [posRecordState.entity]: posRecordState.records[posRecordState.entity] || [] }
    : posRecordState.records;
  const rows = Object.entries(selected).flatMap(([entity, values]) =>
    (Array.isArray(values) ? values : []).map((record) => ({ entity, record }))
  ).filter(({ entity, record }) => {
    const text = JSON.stringify(record).toLowerCase();
    if (posRecordState.search && !text.includes(posRecordState.search.toLowerCase())) return false;
    const dateValue = Object.entries(record).find(([key]) => /date|created|updated|opened|closed|paid|check_in|check_out/i.test(key))?.[1];
    const dateText = dateValue ? String(dateValue).slice(0, 10) : '';
    if (posRecordState.from && (!dateText || dateText < posRecordState.from)) return false;
    if (posRecordState.to && (!dateText || dateText > posRecordState.to)) return false;
    return true;
  });
  const columns = ['entity', ...new Set(rows.flatMap(({ record }) => Object.keys(record))).values()].slice(0, 9);
  thead.innerHTML = `<tr>${columns.map((column) => `<th>${escapeHtml(column === 'entity' ? 'Record type' : column.replaceAll('_', ' '))}</th>`).join('')}</tr>`;
  tbody.innerHTML = rows.map(({ entity, record }) => `<tr>${
    columns.map((column) => {
      const value = column === 'entity' ? posRecordLabel(entity) : record[column];
      const rendered = value === null || value === undefined || value === '' ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value);
      return `<td title="${escapeHtml(rendered)}">${escapeHtml(rendered.length > 120 ? `${rendered.slice(0, 117)}…` : rendered)}</td>`;
    }).join('')
  }</tr>`).join('');
  empty.hidden = rows.length > 0;
  count.textContent = `${rows.length} records`;
}

['entity', 'search', 'from', 'to'].forEach((name) => {
  const id = `pos-record-${name}`;
  document.getElementById(id)?.addEventListener(name === 'entity' ? 'change' : 'input', (event) => {
    posRecordState[name] = event.target.value;
    renderFilteredPosRecords();
  });
});

// --- Load -------------------------------------------------------------------
async function load() {
  const loading = document.getElementById('loading');
  const content = document.getElementById('content');

  try {
    const data = await DiwanApi.getJson('/dashboard-data.php');
    const licensed = data.orders.filter((o) => o.license_id !== null);
    // The licence the overview describes: the newest active one, else the newest.
    const primary = licensed.find((o) => o.license_status === 'active') || licensed[0] || null;

    renderHeader(data);
    renderStats(licensed, primary);
    renderProgress(primary);
    renderCurrent(primary);
    renderQuick(primary);
    renderSetup(primary);
    renderStoreRecords(data.pos, data.customer_id);
    renderPlanView(data);
    prefillContact(data, primary);
    selectHowto(platform);
    detectVariants();

    // "Choose your plan" until there is an active plan licence or a request
    // already waiting.
    const hasPlan = data.plan.current && data.plan.current.status === 'active';
    const waiting = data.plan.requests.some((r) => r.status === 'pending');
    document.querySelectorAll('[data-upgrade]').forEach((el) => { el.hidden = hasPlan || waiting; });

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
      await DiwanApi.postJson('/resend-license-email.php', { license_id: Number(resendBtn.dataset.resend) });
      toast('We’ve emailed your licence key — check your inbox', 'ok');
    } catch (err) {
      toast(err.message || 'Could not send the email. Please try again.', 'error');
    } finally {
      resendBtn.disabled = false;
    }
    return;
  }

  const requestCard = e.target.closest('[data-request-plan]');
  if (requestCard) {
    // The link's href (#plan) switches the view; pick the plan in the form.
    requestedPlan = requestCard.dataset.requestPlan;
    const radio = document.querySelector(`[data-plan-request] input[name="plan"][value="${CSS.escape(requestedPlan)}"]`);
    if (radio) radio.checked = true;
    return;
  }

  const cancelBtn = e.target.closest('[data-cancel-request]');
  if (cancelBtn) {
    if (cancelBtn.disabled) return;
    cancelBtn.disabled = true;
    try {
      await DiwanApi.postJson('/license-requests.php', { action: 'cancel', id: Number(cancelBtn.dataset.cancelRequest) });
      toast('Request cancelled', 'ok');
      await load();
    } catch (err) {
      toast(err.message || 'Could not cancel the request.', 'error');
      cancelBtn.disabled = false;
    }
    return;
  }

  const markRead = e.target.closest('[data-mark-read]');
  if (markRead) {
    try {
      const notifications = await DiwanApi.postJson('/notifications.php', {});
      renderNotices({ items: notifications.notifications, unread: notifications.unread });
    } catch (err) {
      toast(err.message || 'Could not update notifications.', 'error');
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
  if (e.target.name.startsWith('variant_') && e.target.closest('[data-download]')) {
    chooseVariant(e.target.name.slice('variant_'.length), e.target.value);
  }
});

document.addEventListener('submit', async (e) => {
  const requestForm = e.target.closest('[data-plan-request]');
  if (requestForm) {
    e.preventDefault();
    const button = requestForm.querySelector('button[type="submit"]');
    if (button.disabled) return;
    button.disabled = true;
    try {
      const body = Object.fromEntries(new FormData(requestForm));
      const { request } = await DiwanApi.postJson('/license-requests.php', body);
      toast(`Request for ${request.plan_name} sent — we'll be in touch`, 'ok');
      requestedPlan = null;
      await load();
    } catch (err) {
      toast(err.message || 'Could not send your request. Please try again.', 'error');
      button.disabled = false;
    }
    return;
  }

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
    const { license_key, platform: chosen } = Object.fromEntries(new FormData(form));
    const payload = { license_key, platform: chosen };
    // Send only the chosen platform's build, never e.g. a Mac chip for Windows.
    if (Object.hasOwn(VARIANTS, chosen)) payload.variant = selectedVariant[chosen];
    const { download_url, expires_at } = await DiwanApi.postJson('/issue-download.php', payload);
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
    try { await DiwanApi.postJson('/logout.php'); } catch { /* clearing client-side regardless */ }
    window.location.href = '/auth/login.html';
  });
});

document.getElementById('retry-btn')?.addEventListener('click', () => window.location.reload());

load();
