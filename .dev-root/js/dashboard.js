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

const statusBadge = (status) =>
  `<span class="badge badge--${status}">${status.charAt(0).toUpperCase()}${status.slice(1)}</span>`;

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
      ? `<button class="btn btn--ghost btn--sm" data-resend="${order.license_id}">Email me my key</button>`
      : `<a class="btn btn--ghost btn--sm" href="${supportMailto('Recover my Diwan licence key', order)}">Contact support to recover key</a>`;

    licenseBox = `
      <div class="license-box">
        <div class="license-key">
          <span>${escapeHtml(maskedKey)}</span>
          <button class="copy-btn" data-copy="${escapeHtml(order.license_key_prefix)}"
                  title="Copies the identifying prefix only — this is not your full licence key">Copy prefix</button>
        </div>
        <p class="activation-line">${activationCopy(order)}</p>
        <div class="license-actions">
          ${resendBtn}
          <a class="btn btn--ghost btn--sm" href="${supportMailto('Move my Diwan licence to a new device', order)}">Request device change</a>
        </div>
        ${order.license_status === 'active' ? `
        <form class="download-mini" data-download>
          <input class="input" type="text" name="license_key" placeholder="Paste your licence key to download" required>
          <select name="platform">
            <option value="windows">Windows (.exe)</option>
            <option value="macos">macOS (.dmg)</option>
            <option value="android">Android (.apk)</option>
          </select>
          <button class="btn btn--primary btn--sm" type="submit">Get download link</button>
        </form>
        <p role="status" class="download-status"></p>
        ` : ''}
      </div>`;
  } else if (order.order_status === 'paid') {
    licenseBox = `<p class="activation-line">Your licence is being issued — refresh in a moment, or contact support if this persists.</p>`;
  } else {
    licenseBox = `<p class="activation-line">A licence key will appear here once payment is confirmed.</p>`;
  }

  return `
    <article class="order-card">
      <div class="order-card__head">
        <div>
          <div class="order-card__product">${escapeHtml(order.product_name)}</div>
          <div class="order-card__meta">${date(order.created_at)} · ${money(order.amount_paisa, order.currency)} · ${order.order_ref}</div>
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
      window.location.href = '/auth/sign-in.html';
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
      status.className = '';
      status.textContent = 'Verifying your licence…';
      try {
        const payload = Object.fromEntries(new FormData(form));
        const { download_url, expires_at } = await apiPost('/issue-download.php', payload);
        status.textContent = `Licence verified. Your link expires ${expires_at}.`;
        status.className = 'ok';
        window.location.href = download_url;
      } catch (err) {
        status.textContent = err.message;
        status.className = 'error';
      }
    });
  });
}

document.getElementById('logout-btn')?.addEventListener('click', async () => {
  try { await apiPost('/logout.php'); } catch { /* clearing client-side regardless */ }
  window.location.href = '/auth/sign-in.html';
});

load();
