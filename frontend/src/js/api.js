// Shared JSON client for /api/*, loaded before every page script that talks
// to the backend. It exposes exactly one global, `DiwanApi`, so page scripts
// never declare helpers of their own in the shared global scope — two scripts
// declaring the same function name silently replace each other.
(() => {
  const BASE = '/api';

  class ApiError extends Error {
    constructor(message, status) {
      super(message);
      this.status = status;
    }
  }

  // Never rejects for an HTTP error status: callers branch on `ok`/`status`.
  // Rejects only when the server could not be reached at all.
  async function request(method, path, body) {
    const res = await fetch(BASE + path, {
      method,
      credentials: 'same-origin',
      headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
  }

  // For flows where every failure ends the same way: resolves to the body,
  // or throws an ApiError carrying the server's message and status.
  async function unwrap(pending) {
    const { ok, status, data } = await pending;
    if (!ok) throw new ApiError(data.error || `Request failed (${status})`, status);
    return data;
  }

  const STATUS_CLASS = { ok: 'text-accent', error: 'text-danger' };

  function setStatus(el, message, kind) {
    if (!el) return;
    el.textContent = message;
    el.className = `min-h-6 text-[.9rem] ${STATUS_CLASS[kind] || 'text-muted'}`;
  }

  window.DiwanApi = {
    get: (path) => request('GET', path),
    post: (path, body = {}) => request('POST', path, body),
    getJson: (path) => unwrap(request('GET', path)),
    postJson: (path, body = {}) => unwrap(request('POST', path, body)),
    setStatus,
    ApiError,
  };
})();
