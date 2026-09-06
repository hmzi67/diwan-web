// Signup page: same passwordless flow as login.html (POST /send-login-link.php
// with just the email) — the backend already treats an unknown email as a
// signup (LoginService::requestLink() creates the customers row). The only
// things this page adds over the login form are copy, layout, and requiring
// the terms checkbox before it will submit.
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

const stepEmail = document.getElementById('step-email');
const stepSent  = document.getElementById('step-sent');
const form      = document.getElementById('signup-form');
const status    = document.getElementById('signup-status');

// A signup link from a pricing card ("Get Started") carries ?plan= through,
// same as login.html, so the emailed link can land back on that checkout.
const params = new URLSearchParams(window.location.search);
const plan = params.get('plan');

form?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const button = form.querySelector('button');
  const data = new FormData(form);
  const email = data.get('email');

  if (!data.get('terms')) {
    setStatus(status, 'Please accept the terms of use & privacy policy to continue.', 'error');
    return;
  }

  button.disabled = true;
  setStatus(status, 'Sending…');
  try {
    await postJson('/send-login-link.php', { email, plan });
    document.getElementById('sent-email').textContent = email;
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
