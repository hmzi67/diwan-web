// Behaviour for the five auth pages: login, signup, forgot-password,
// reset-password and verify-email, all wired to the real backend.
//
// The flow these pages implement, end to end:
//
//   signup  -> POST /register.php: account created (unverified), verification
//              email sent, identical response whether or not the address
//              already had an account. No session yet.
//   verify  -> the emailed link lands on verify-email.html?token=..., which
//              POSTs it to /api/verify-email.php; success starts the session.
//   login   -> POST /api/sign-in.php. Closed until the account is verified:
//              the server answers status:'email_not_verified' only after the
//              password checks out, and this page swaps in the resend panel.
//
// The `plan` query param rides through the whole flow. Someone who clicked a
// pricing card lands here as ?plan=diwan-pos-standard, and after verifying or
// signing in goes straight to that plan's checkout instead of the dashboard —
// losing it would drop them at a generic page mid-purchase.
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
  el.className = kind === 'ok' ? 'min-h-6 text-[.9rem] text-accent'
    : kind === 'error' ? 'min-h-6 text-[.9rem] text-danger'
    : 'min-h-6 text-[.9rem] text-muted';
}

function show(id) {
  ['step-form', 'step-sent', 'step-done', 'step-invalid', 'step-unverified'].forEach((name) => {
    const el = document.getElementById(name);
    if (el) el.hidden = (name !== id);
  });
}

// The email the login attempt used — carried over to the unverified panel so
// the resend button knows where to send the link, and set again when signup
// succeeds so its confirmation panel can resend too.
let authEmail = '';

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
  const email = data.get('email');
  const { ok, data: body } = await postJson('/register.php', {
    email,
    password: data.get('password'),
    plan,
  });

  if (!ok) {
    setStatus(status, body.error || 'Could not create your account.', 'error');
    return;
  }
  // Same response whether or not the address already existed — the page says
  // "check your email" either way; the mailbox decides what they actually read.
  authEmail = email;
  const sent = document.getElementById('sent-email');
  if (sent) sent.textContent = email;
  show('step-sent');
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
  // Password checked out, mailbox not verified. Swap the form for the panel
  // that offers the link again — the server only reveals this state to
  // someone who already knows the password.
  if (body.status === 'email_not_verified') {
    authEmail = data.get('email');
    const shown = document.getElementById('unverified-email');
    if (shown) shown.textContent = authEmail;
    show('step-unverified');
    return;
  }
  setStatus(status, body.error || 'Email or password is incorrect.', 'error');
});

// --- Resend verification ----------------------------------------------------
// One handler, used by the login page's unverified panel and signup's
// confirmation panel (whichever is on screen has the address stashed above).
document.getElementById('resend-btn')?.addEventListener('click', async (e) => {
  const button = e.currentTarget;
  const status = document.getElementById('resend-status');
  button.disabled = true;
  setStatus(status, 'Sending…');
  try {
    const { ok, data: body } = await postJson('/resend-verification.php', { email: authEmail });
    setStatus(status, ok ? body.message : (body.error || 'Could not send the link. Try again.'), ok ? 'ok' : 'error');
  } catch {
    setStatus(status, 'Could not reach the server. Please try again.', 'error');
  } finally {
    button.disabled = false;
  }
});

// --- Forgot password --------------------------------------------------------
wire('forgot-form', 'forgot-status', async (data, status) => {
  const email = data.get('email');
  const { ok, data: body } = await postJson('/request-password-reset.php', { email });

  if (!ok) {
    setStatus(status, body.error || 'Could not send the link. Please try again.', 'error');
    return;
  }
  authEmail = email;
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

// --- Verify email -----------------------------------------------------------
// Reached from the emailed link with the token in the query string. Redeems
// it immediately — nobody clicks a verification link to look at a button —
// and on success the backend has already started the session, so we just walk
// on to the dashboard (or the plan checkout they started from).
if (document.getElementById('verify-panel')) {
  const token = params.get('token');
  const status = document.getElementById('verify-status');

  if (!token) {
    show('step-invalid');
  } else {
    postJson('/verify-email.php', { token })
      .then(({ ok }) => {
        if (ok) {
          const cont = document.getElementById('verify-continue');
          if (cont) cont.href = destination();
          show('step-done');
          setTimeout(() => { window.location.href = destination(); }, 1400);
        } else {
          show('step-invalid');
        }
      })
      // Network trouble, not a dead link — say so rather than blaming the
      // link, which may be perfectly fine.
      .catch(() => setStatus(status, 'Could not reach the server. Refresh to try again.', 'error'));
  }
}

// Carry the plan through the links between these pages, so it survives a
// detour via "create an account" or "forgot password".
if (plan) {
  document.querySelectorAll('[data-carry-plan]').forEach((link) => {
    link.href = link.getAttribute('href') + planQuery;
  });
}
