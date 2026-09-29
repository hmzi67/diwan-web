// Behaviour for the /auth/ pages: sign-up, sign-in, forgot-password and
// reset-password, all wired to the real backend.
//
// Preserve the selected platform through sign-in/sign-up. During temporary
// free-trial onboarding all successful authentication ends at the dashboard.
const API = '/api';
const params = new URLSearchParams(window.location.search);
const platform = ['windows', 'macos', 'android'].includes(params.get('platform'))
  ? params.get('platform') : null;
const platformQuery = platform ? `?platform=${encodeURIComponent(platform)}` : '';

function destination() {
  return `/dashboard.html${platformQuery}`;
}

// Only an explicit platform choice starts a trial for an existing account.
// A failed response is retryable: server-side issuance is idempotent.
async function finishSignIn(status) {
  if (platform) {
    const { ok, data } = await postJson('/start-trial.php', {});
    if (!ok) {
      setStatus(status, data.error || 'Could not start your trial. Please try again.', 'error');
      return;
    }
  }
  window.location.href = destination();
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
    await finishSignIn(status);
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

// --- Sign in with Apple -----------------------------------------------------
// Apple's popup flow: the server hands out a signed nonce (apple-nonce.php),
// Apple embeds it in the identity token, and apple-sign-in.php checks both
// before starting the same session cookie a password sign-in gets. Signing
// in with an Apple ID we've never seen creates the account and its trial.
//
// AppleID.auth.signIn() opens a popup, so it must run synchronously inside
// the click — no awaits before it, or popup blockers eat it. That's why the
// nonce is fetched ahead of time and refreshed before it can expire.
const appleBlock = document.getElementById('apple-block');
const appleButton = document.getElementById('apple-signin');
const APPLE_NONCE_REFRESH_MS = 8 * 60 * 1000;   // server nonces live 10 minutes
let appleNonce = null;

async function prepareAppleSignIn() {
  const res = await fetch(API + '/apple-nonce.php', { credentials: 'same-origin' });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.web || !data.web.enabled || !window.AppleID) return false;
  window.AppleID.auth.init({
    clientId: data.web.client_id,
    scope: 'name email',
    redirectURI: data.web.redirect_uri,
    nonce: data.nonce,
    usePopup: true,
  });
  appleNonce = data.nonce;
  return true;
}

if (appleBlock && appleButton) {
  prepareAppleSignIn()
    .then((enabled) => {
      if (!enabled) return;
      appleBlock.hidden = false;
      setInterval(() => { prepareAppleSignIn().catch(() => {}); }, APPLE_NONCE_REFRESH_MS);
    })
    .catch(() => {});   // no Apple button is a fine outcome; the form still works

  appleButton.addEventListener('click', () => {
    const status = document.getElementById(appleButton.dataset.status);
    const terms = document.querySelector('input[name="terms"]');
    if (terms && !terms.checked) {
      setStatus(status, 'Please accept the terms of use & privacy policy to continue.', 'error');
      return;
    }
    const nonce = appleNonce;
    const popup = window.AppleID.auth.signIn();   // synchronous: keeps the click's user gesture
    appleButton.disabled = true;
    setStatus(status, 'Waiting for Apple…');

    popup
      .then(async (response) => {
        const name = response.user && response.user.name
          ? [response.user.name.firstName, response.user.name.lastName].filter(Boolean).join(' ')
          : '';
        setStatus(status, 'Just a moment…');
        const { ok, data } = await postJson('/apple-sign-in.php', {
          id_token: response.authorization.id_token,
          code: response.authorization.code,
          nonce,
          name,
          client: 'web',
        });
        if (!ok) {
          setStatus(status, data.error || 'Apple sign-in failed. Please try again.', 'error');
          prepareAppleSignIn().catch(() => {});   // that nonce is spent
          return;
        }
        await finishSignIn(status);
      })
      .catch((err) => {
        // Closing the popup is a choice, not an error.
        const cancelled = err && (err.error === 'popup_closed_by_user' || err.error === 'user_cancelled_authorize');
        setStatus(status, cancelled ? '' : 'Apple sign-in failed. Please try again.', cancelled ? '' : 'error');
      })
      .finally(() => { appleButton.disabled = false; });
  });
}

// --- Forgot password --------------------------------------------------------
wire('forgot-form', 'forgot-status', async (data, status) => {
  const email = data.get('email');
  const { ok, data: body } = await postJson('/request-password-reset.php', { email, platform });

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

// Carry only allowlisted platform context, never an arbitrary redirect URL.
if (platform) {
  document.querySelectorAll('[data-carry-plan]').forEach((link) => {
    link.href = link.getAttribute('href') + platformQuery;
  });
}
