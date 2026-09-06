// Behaviour for the /auth/ pages: sign-up, sign-in, forgot-password and
// reset-password, all wired to the real backend.
//
// The `plan` query param rides through the whole flow. Someone who clicked a
// pricing card lands here as ?plan=diwan-pos-standard, and after a successful
// sign-in or registration goes straight to that plan's checkout instead of the
// dashboard — losing it would drop them at a generic page mid-purchase.
const API = '/api';

const params = new URLSearchParams(window.location.search);
const plan = params.get('plan');
const planQuery = plan ? `?plan=${encodeURIComponent(plan)}` : '';

/** Where to go once authenticated: back to the chosen plan, else the dashboard. */
function destination() {
  return plan ? `/checkout.html${planQuery}` : '/dashboard.html';
}

async function postJson(path, body) {
  const res = await fetch(API + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, data };
}

function setStatus(el, message, kind) {
  if (!el) return;
  el.textContent = message;
  el.className = kind || '';
}

function show(id) {
  ['step-form', 'step-sent', 'step-done', 'step-invalid'].forEach((name) => {
    const el = document.getElementById(name);
    if (el) el.hidden = (name !== id);
  });
}

// --- Password show/hide -----------------------------------------------------
document.querySelectorAll('[data-pw-toggle]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('[data-pw]');
    if (!input) return;
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    button.textContent = isHidden ? 'Hide' : 'Show';
    button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    input.focus();
  });
});

// --- Client-side checks -----------------------------------------------------
// Convenience only: catches the obvious mistakes without a round-trip. The
// server re-validates everything (PasswordService::validate) and is the only
// thing that actually decides.
function firstProblem(form) {
  const email = form.querySelector('input[type="email"]');
  if (email && !email.value.trim()) return 'Please enter your email address.';
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
    return 'Please enter a valid email address.';
  }

  const password = form.querySelector('[data-pw]:not([data-pw-match])');
  if (password) {
    if (!password.value) return 'Please enter a password.';
    if (password.hasAttribute('minlength') && password.value.length < Number(password.minLength)) {
      return `Password must be at least ${password.minLength} characters.`;
    }
  }

  const confirm = form.querySelector('[data-pw-match]');
  if (confirm) {
    const target = document.getElementById(confirm.dataset.pwMatch);
    if (target && confirm.value !== target.value) return "Those passwords don't match.";
  }

  const terms = form.querySelector('input[name="terms"]');
  if (terms && !terms.checked) {
    return 'Please accept the terms of use & privacy policy to continue.';
  }

  return null;
}

function wire(formId, statusId, submit) {
  const form = document.getElementById(formId);
  if (!form) return;
  const status = document.getElementById(statusId);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const problem = firstProblem(form);
    if (problem) {
      setStatus(status, problem, 'error');
      return;
    }

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    setStatus(status, 'Just a moment…');
    try {
      await submit(new FormData(form), status);
    } catch (err) {
      setStatus(status, err.message || 'Something went wrong. Please try again.', 'error');
    } finally {
      button.disabled = false;
    }
  });
}

// --- Sign up ----------------------------------------------------------------
wire('signup-form', 'signup-status', async (data, status) => {
  const { ok, data: body } = await postJson('/register.php', {
    email: data.get('email'),
    password: data.get('password'),
  });

  if (!ok) {
    setStatus(status, body.error || 'Could not create your account.', 'error');
    return;
  }
  // The email already had an account — register.php deliberately does not say
  // so via an error, and never touches the existing password.
  if (body.status === 'exists') {
    setStatus(status, body.message, 'error');
    return;
  }
  window.location.href = destination();   // registration signs you straight in
});

// --- Sign in ----------------------------------------------------------------
wire('signin-form', 'signin-status', async (data, status) => {
  const { ok, data: body } = await postJson('/sign-in.php', {
    email: data.get('email'),
    password: data.get('password'),
  });

  if (ok) {
    window.location.href = destination();
    return;
  }
  // Account predates password auth: send them to set one rather than leaving
  // them retrying a password that was never created.
  if (body.status === 'password_not_set') {
    setStatus(status, body.message, 'error');
    const link = document.getElementById('forgot-link');
    if (link) link.textContent = 'Set your password →';
    return;
  }
  setStatus(status, body.error || 'Email or password is incorrect.', 'error');
});

// --- Forgot password --------------------------------------------------------
wire('forgot-form', 'forgot-status', async (data, status) => {
  const email = data.get('email');
  const { ok, data: body } = await postJson('/request-password-reset.php', { email });

  if (!ok) {
    setStatus(status, body.error || 'Could not send the link. Please try again.', 'error');
    return;
  }
  const sent = document.getElementById('sent-email');
  if (sent) sent.textContent = email;
  show('step-sent');
});

// --- Reset password ---------------------------------------------------------
const resetToken = params.get('token');

wire('reset-form', 'reset-status', async (data, status) => {
  const { ok, status: code, data: body } = await postJson('/reset-password.php', {
    token: resetToken || '',
    password: data.get('password'),
  });

  if (ok) {
    show('step-done');
    return;
  }
  // 400 = malformed token, 410 = expired or already used. Both mean the link
  // itself is dead, so show that rather than an error above a form they cannot
  // successfully submit.
  if (code === 400 || code === 410) {
    show('step-invalid');
    return;
  }
  setStatus(status, body.error || 'Could not update your password.', 'error');
});

// A reset page opened without a token in the URL can never work — say so up
// front instead of after they have typed a password twice.
if (document.getElementById('reset-form') && !resetToken) {
  show('step-invalid');
}

// Carry the plan through the links between these pages, so it survives a
// detour via "create an account" or "forgot password".
if (plan) {
  document.querySelectorAll('[data-carry-plan]').forEach((link) => {
    link.href = link.getAttribute('href') + planQuery;
  });
}
