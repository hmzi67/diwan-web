// Login page: request a magic link, show the confirmation state.
const API = '/api';

// PLACEHOLDER names — must match the SKUs in database/schema.sql /
// migration 003. Purely cosmetic (which word appears in the copy below);
// the actual plan is never trusted from here, only from the signed session
// once checkout.php looks up the order's product.
const PLAN_NAMES = {
  'diwan-pos-starter':    'Starter',
  'diwan-pos-standard':   'Standard',
  'diwan-pos-enterprise': 'Enterprise',
};

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

const stepEmail = document.getElementById('step-email');
const stepSent  = document.getElementById('step-sent');
const form      = document.getElementById('login-form');
const status    = document.getElementById('login-status');

// A friendly message for a bad/expired link the customer clicked from email.
const params = new URLSearchParams(window.location.search);
const error = params.get('error');
if (error === 'expired_link') {
  setStatus(status, 'That login link has expired or was already used. Request a new one below.', 'error');
} else if (error === 'invalid_link') {
  setStatus(status, "That login link isn't valid. Request a new one below.", 'error');
}

// Arrived here from a pricing card ("Get Started") rather than the nav's
// plain "Log in" link — tailor the copy and carry the plan through to
// send-login-link.php so the emailed link returns to that plan's checkout.
const plan = params.get('plan');
const planName = plan ? (PLAN_NAMES[plan] || plan) : null;
if (planName) {
  const title = document.getElementById('login-title');
  const sub = document.getElementById('login-sub');
  if (title) title.textContent = `Continue with ${planName}`;
  if (sub) sub.textContent = `Enter your email to continue — new or returning, it doesn't matter. `
    + `We'll send you a link back to your ${planName} checkout. No password needed.`;
}

form?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const button = form.querySelector('button');
  const email = new FormData(form).get('email');
  button.disabled = true;
  setStatus(status, 'Sending…');
  try {
    await postJson('/send-login-link.php', { email, plan });
    document.getElementById('sent-email').textContent = email;
    const sentContinue = document.getElementById('sent-continue');
    if (planName && sentContinue) {
      sentContinue.textContent = `continue your ${planName} checkout`;
    }
    stepEmail.hidden = true;
    stepSent.hidden = false;
  } catch (err) {
    setStatus(status, err.message, 'error');
  } finally {
    button.disabled = false;
  }
});

document.getElementById('try-again')?.addEventListener('click', (e) => {
  e.preventDefault();
  stepSent.hidden = true;
  stepEmail.hidden = false;
  setStatus(status, '');
  form.reset();
  form.querySelector('input').focus();
});
