// Customer dashboard: fetch everything from dashboard-data.php, render it,
// and gate the whole page behind a valid session — no session, no dashboard.
const API = '/api';

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
  const amount = (paisa / 100).toLocaleString('en-PK', { minimumFractionDigits: 0 });
  return currency === 'PKR' ? `Rs ${amount}` : `${currency} ${amount}`;
};

const date = (isoish) => isoish
  ? new Date(isoish.replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
  : '—';

const BADGE = {
  paid: 'inline-flex items-center whitespace-nowrap rounded-full px-3 py-[.3rem] text-[.74rem] font-semibold border border-accent-dim bg-glow-soft text-accent',
  pending: 'inline-flex items-center whitespace-nowrap rounded-full px-3 py-[.3rem] text-[.74rem] font-semibold border border-line-hi bg-surface-hi text-muted',
  failed: 'inline-flex items-center whitespace-nowrap rounded-full px-3 py-[.3rem] text-[.74rem] font-semibold border border-[rgba(255,122,122,.35)] bg-[rgba(255,122,122,.08)] text-danger',
  refunded: 'inline-flex items-center whitespace-nowrap rounded-full px-3 py-[.3rem] text-[.74rem] font-semibold border border-[rgba(255,122,122,.35)] bg-[rgba(255,122,122,.08)] text-danger',
};

const statusBadge = (status) =>
  `<span class="${BADGE[status] || 'inline-flex items-center whitespace-nowrap rounded-full px-3 py-[.3rem] text-[.74rem] font-semibold'}">${status.charAt(0).toUpperCase()}${status.slice(1)}</span>`;

function activationCopy(order) {
  if (order.activation_status === 'activated') {
    const hint = order.activated_machine_hint ? escapeHtml(order.activated_machine_hint) : 'a device';
    return `Active on <b>${hint}</b>`;
  }
  return 'Not yet activated';
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function supportMailto(subject, order) {
  const body = `Order reference: ${order.order_ref}\nLicence prefix: ${order.license_key_prefix || 'n/a'}\n\n`;
  return `mailto:hamzawaheed057@gmail.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}

function renderOrder(order) {
  const hasLicense = order.license_id !== null;

  let licenseBox = '';
  if (hasLicense) {
    const maskedKey = `${order.license_key_prefix}-••••-••••-••••`;
    const resendBtn = order.can_resend
      ? `<button class="inline-flex items-center justify-center gap-[.55rem] whitespace-nowrap rounded-full border border-line-hi px-[1.1rem] py-[.55rem] text-[.85rem] font-semibold transition duration-200 ease-diwan bg-transparent text-ink hover:border-accent-dim hover:bg-surface-hi" data-resend="${order.license_id}">Email me my key</button>`
      : `<a class="inline-flex items-center justify-center gap-[.55rem] whitespace-nowrap rounded-full border border-line-hi px-[1.1rem] py-[.55rem] text-[.85rem] font-semibold transition duration-200 ease-diwan bg-transparent text-ink hover:border-accent-dim hover:bg-surface-hi" href="${supportMailto('Recover my Diwan licence key', order)}">Contact support to recover key</a>`;

    licenseBox = `
      <div class="mt-[1.1rem] border-t border-dashed border-line pt-[1.1rem]">
        <div class="flex flex-wrap items-center gap-[.6rem] rounded-sm border border-line bg-canvas px-[.9rem] py-[.6rem] font-mono text-[.9rem] text-ink">
          <span>${escapeHtml(maskedKey)}</span>
          <button class="ml-auto cursor-pointer rounded-sm border border-line-hi bg-transparent px-[.65rem] py-[.35rem] text-[.78rem] text-muted transition duration-[180ms] ease-diwan hover:border-accent-dim hover:text-ink" data-copy="${escapeHtml(order.license_key_prefix)}"
                  title="Copies the identifying prefix only — this is not your full licence key">Copy prefix</button>
        </div>
        <p class="mt-[.7rem] text-[.88rem] text-muted [&_b]:font-semibold [&_b]:text-ink">${activationCopy(order)}</p>
        <div class="mt-[.9rem] flex flex-wrap gap-[.6rem]">
          ${resendBtn}
          <a class="inline-flex items-center justify-center gap-[.55rem] whitespace-nowrap rounded-full border border-line-hi px-[1.1rem] py-[.55rem] text-[.85rem] font-semibold transition duration-200 ease-diwan bg-transparent text-ink hover:border-accent-dim hover:bg-surface-hi" href="${supportMailto('Move my Diwan licence to a new device', order)}">Request device change</a>
        </div>
        ${order.license_status === 'active' ? `
        <form class="mt-4 flex flex-wrap items-start gap-2 [&_input]:min-w-40 [&_input]:flex-1 [&_select]:min-w-40 [&_select]:flex-1" data-download>
          <input class="w-full rounded-sm border border-line bg-elevated px-4 py-[.8rem] text-[.95rem] text-ink focus:border-accent-dim focus:outline-none" type="text" name="license_key" placeholder="Paste your licence key to download" required>
          <select class="rounded-sm border border-line bg-elevated px-4 py-[.8rem] text-[.95rem] text-ink focus:border-accent-dim focus:outline-none" name="platform">
            <option value="windows">Windows (.exe)</option>
            <option value="macos">macOS (.dmg)</option>
            <option value="android">Android (.apk)</option>
          </select>
          <button class="inline-flex items-center justify-center gap-[.55rem] whitespace-nowrap rounded-full border border-transparent px-[1.1rem] py-[.55rem] text-[.85rem] font-semibold transition duration-200 ease-diwan bg-accent text-accent-ink shadow-[0_0_0_0_var(--color-glow)] hover:-translate-y-px hover:bg-accent-hi hover:shadow-[0_8px_30px_-8px_var(--color-accent)]" type="submit">Get download link</button>
        </form>
        <p role="status" class="min-h-6 text-[.9rem] text-muted"></p>
        ` : ''}
      </div>`;
  } else if (order.order_status === 'paid') {
    licenseBox = `<p class="mt-[.7rem] text-[.88rem] text-muted [&_b]:font-semibold [&_b]:text-ink">Your licence is being issued — refresh in a moment, or contact support if this persists.</p>`;
  } else {
    licenseBox = `<p class="mt-[.7rem] text-[.88rem] text-muted [&_b]:font-semibold [&_b]:text-ink">A licence key will appear here once payment is confirmed.</p>`;
  }

  return `
    <article class="rounded-lg border border-line bg-surface p-6">
      <div class="mb-1 flex flex-wrap items-start justify-between gap-[.6rem]">
        <div>
          <div class="text-[1.05rem] font-bold">${escapeHtml(order.product_name)}</div>
          <div class="mt-[.2rem] text-[.85rem] text-muted">${date(order.created_at)} · ${money(order.amount_paisa, order.currency)} · ${order.order_ref}</div>
        </div>
        ${statusBadge(order.order_status)}
      </div>
      ${licenseBox}
    </article>`;
}

async function load() {
  const loading = document.getElementById('loading');
  const content = document.getElementById('content');

  try {
    const data = await apiGet('/dashboard-data.php');

    document.getElementById('welcome-email').textContent = data.email;

    const ordersEl = document.getElementById('orders');
    const noOrders = document.getElementById('no-orders');

    if (!data.orders.length) {
      noOrders.hidden = false;
    } else {
      ordersEl.innerHTML = data.orders.map(renderOrder).join('');
    }

    loading.hidden = true;
    content.hidden = false;
    wireUpActions();
  } catch (err) {
    if (err.status === 401) {
      window.location.href = '/auth/login.html';
      return;
    }
    loading.textContent = err.message || 'Something went wrong loading your account.';
  }
}

function wireUpActions() {
  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(btn.dataset.copy);
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = original; }, 1500);
      } catch {
        // Clipboard API can be unavailable (e.g. non-HTTPS); fail quietly.
      }
    });
  });

  document.querySelectorAll('[data-resend]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const original = btn.textContent;
      btn.textContent = 'Sending…';
      try {
        await apiPost('/resend-license-email.php', { license_id: Number(btn.dataset.resend) });
        btn.textContent = 'Sent — check your email';
      } catch (err) {
        btn.textContent = original;
        alert(err.message);
      } finally {
        btn.disabled = false;
      }
    });
  });

  document.querySelectorAll('[data-download]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const status = form.nextElementSibling;
      status.className = 'min-h-6 text-[.9rem] text-muted';
      status.textContent = 'Verifying your licence…';
      try {
        const payload = Object.fromEntries(new FormData(form));
        const { download_url, expires_at } = await apiPost('/issue-download.php', payload);
        status.textContent = `Licence verified. Your link expires ${expires_at}.`;
        status.className = 'min-h-6 text-[.9rem] text-accent';
        window.location.href = download_url;
      } catch (err) {
        status.textContent = err.message;
        status.className = 'min-h-6 text-[.9rem] text-danger';
      }
    });
  });
}

document.getElementById('logout-btn')?.addEventListener('click', async () => {
  try { await apiPost('/logout.php'); } catch { /* clearing client-side regardless */ }
  window.location.href = '/auth/login.html';
});

load();
