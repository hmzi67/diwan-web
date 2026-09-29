// Contact page: validate, post to contact.php, swap to the "sent" state.
// Client checks are a convenience only; ContactService re-validates everything.
// Wrapped so nothing leaks to globals: the dashboard's Help view loads this
// beside dashboard.js.
(() => {
  const API = '/api';
  const MAX = 3000;
  // Topics where an order reference helps us find the purchase.
  const ORDER_TOPICS = ['licence', 'device', 'refund', 'support'];

  const form     = document.getElementById('contact-form');
  const status   = document.getElementById('contact-status');
  const sent     = document.getElementById('contact-sent');
  const sentText = document.getElementById('contact-sent-text');
  const again    = document.getElementById('contact-again');
  const orderRef = document.getElementById('order-ref-field');
  const count    = document.getElementById('message-count');
  const message  = form.elements.message;

  function setStatus(text, kind) {
    status.textContent = text;
    status.className = kind || '';
  }

  function syncOrderRef() {
    orderRef.hidden = !ORDER_TOPICS.includes(form.elements.topic.value);
  }

  function syncCount() {
    const n = message.value.length;
    count.textContent = `${n} / ${MAX}`;
    count.classList.toggle('is-near', n > MAX * 0.9);
  }

  function firstProblem() {
    const name = form.elements.name.value.trim();
    const email = form.elements.email.value.trim();
    const text = message.value.trim();
    if (!name) return ['Please enter your name.', form.elements.name];
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return ['Please enter a valid email address.', form.elements.email];
    }
    if (text.length < 10) return ['Please tell us a little more — at least 10 characters.', message];
    return null;
  }

  form.addEventListener('change', (e) => {
    if (e.target.name === 'topic') syncOrderRef();
  });
  message.addEventListener('input', syncCount);
  form.addEventListener('input', (e) => e.target.removeAttribute('aria-invalid'));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const problem = firstProblem();
    if (problem) {
      const [text, field] = problem;
      setStatus(text, 'error');
      field.setAttribute('aria-invalid', 'true');
      field.focus();
      return;
    }

    const button = form.querySelector('button[type="submit"]');
    const data = new FormData(form);
    if (orderRef.hidden) data.delete('order_ref');

    button.disabled = true;
    form.setAttribute('aria-busy', 'true');
    setStatus('Sending…');
    try {
      const res = await fetch(API + '/contact.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(Object.fromEntries(data)),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        setStatus(body.error || 'Something went wrong. Please try again, or email us directly.', 'error');
        return;
      }
      sentText.textContent = body.message;
      form.hidden = true;
      sent.hidden = false;
      sent.focus();
    } catch {
      setStatus("We couldn't reach the server. Check your connection and try again.", 'error');
    } finally {
      button.disabled = false;
      form.removeAttribute('aria-busy');
    }
  });

  again.addEventListener('click', () => {
    form.reset();
    setStatus('');
    syncOrderRef();
    syncCount();
    sent.hidden = true;
    form.hidden = false;
    form.elements.name.focus();
  });

  syncOrderRef();
  syncCount();
})();
