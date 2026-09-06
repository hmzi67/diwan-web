// Checkout page: requires a session (checkout.php enforces this too — this
// is just so a logged-out visitor gets a redirect instead of a 401).
const API = '/api';

// PLACEHOLDER names/prices — cosmetic only, must match database/schema.sql /
// migration 003. The real price is never trusted from here: checkout.php
// reads it from the products table when it creates the order.
const PLANS = {
  'diwan-pos-starter':    { name: 'Starter',    price: 'Rs 8,000' },
  'diwan-pos-standard':   { name: 'Standard',   price: 'Rs 12,000' },
  'diwan-pos-enterprise': { name: 'Enterprise', price: 'Rs 25,000' },
};
const DEFAULT_PLAN = 'diwan-pos-standard';

async function getJson(path) {
  const res = await fetch(API + path, { credentials: 'same-origin' });
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, data };
}

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

const params = new URLSearchParams(window.location.search);
const rawPlan = params.get('plan') || DEFAULT_PLAN;
// Only ever used for display — an unrecognised value still gets sent to
// checkout.php as-is, which will reject it server-side if it's not a real,
// active product.
const plan = Object.prototype.hasOwnProperty.call(PLANS, rawPlan) ? rawPlan : DEFAULT_PLAN;
const planInfo = PLANS[plan];

const title   = document.getElementById('checkout-title');
const summary = document.getElementById('checkout-summary');
const form    = document.getElementById('checkout-form');
const emailEl = document.getElementById('checkout-email');
const status  = document.getElementById('checkout-status');

(async () => {
  const { ok, data } = await getJson('/dashboard-data.php');
  if (!ok) {
    window.location.href = `/auth/sign-in.html?plan=${encodeURIComponent(plan)}`;
    return;
  }
  title.textContent = `Checkout — ${planInfo.name}`;
  summary.textContent = `${planInfo.name} plan, ${planInfo.price} one-time.`;
  emailEl.textContent = data.email;
  form.hidden = false;
})();

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const button = form.querySelector('button');
  button.disabled = true;
  setStatus(status, 'Creating your order…');
  try {
    const phone = new FormData(form).get('phone');
    const { redirect_url, fields } = await postJson('/checkout.php', {
      phone,
      product_sku: plan,
    });
    // The gateway requires a form POST, so build and submit one.
    const gwForm = document.createElement('form');
    gwForm.method = 'POST';
    gwForm.action = redirect_url;
    for (const [name, value] of Object.entries(fields || {})) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      gwForm.appendChild(input);
    }
    document.body.appendChild(gwForm);
    gwForm.submit();
  } catch (err) {
    setStatus(status, err.message, 'error');
    button.disabled = false;
  }
});
