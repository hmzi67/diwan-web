// All backend calls go through /api/* — the only web-accessible PHP entrypoints.
const API = '/api';

async function postJson(path, body) {
  const res = await fetch(API + path, {
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
  el.className = kind || '';
}

const checkoutForm = document.getElementById('checkout-form');
checkoutForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const status = document.getElementById('checkout-status');
  const button = checkoutForm.querySelector('button');
  button.disabled = true;
  setStatus(status, 'Creating your order…');
  try {
    const payload = Object.fromEntries(new FormData(checkoutForm));
    const { redirect_url, fields } = await postJson('/checkout.php', payload);
    // The gateway requires a form POST, so build and submit one.
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = redirect_url;
    for (const [name, value] of Object.entries(fields || {})) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
  } catch (err) {
    setStatus(status, err.message, 'error');
    button.disabled = false;
  }
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
