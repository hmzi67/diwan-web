// Diwan admin panel. Every figure comes from /api/admin-*.php and every
// change goes back through it with the session's CSRF token; this file only
// renders. Hiding something here protects nothing — the server checks the
// administrator session on every request.
//
// Views are switched by the hash (#users, #licenses/42, …) so the back button
// and bookmarks work. Wrapped in one function scope: no globals but DiwanApi.
(() => {
  const main = document.getElementById('admin-main');
  const PAGE = 25;

  const SECTIONS = {
    overview: 'Overview',
    users: 'Users',
    requests: 'License Requests',
    licenses: 'Licenses',
    businesses: 'Businesses & Counters',
    plans: 'Plans & Features',
    downloads: 'Downloads & Analytics',
    versions: 'Application Versions',
    notifications: 'Notifications',
    audit: 'Audit Logs',
    settings: 'Admin Settings',
  };

  // Each list keeps its own filters and page while you move around.
  const filters = {
    users: { q: '', status: '', plan: '', request: '', page: 1 },
    requests: { q: '', status: 'pending', plan: '', page: 1 },
    licenses: { q: '', status: '', plan: '', page: 1 },
    businesses: { q: '', attention: '', page: 1 },
    downloads: { from: '', to: '', plan: '', platform: '', version: '', status: '', channel: '', q: '', page: 1 },
    audit: { q: '', action: '', actor_type: '', page: 1 },
  };
  let plansCache = null;   // [{code, name, id}] for filter selects

  // --- Small helpers --------------------------------------------------------
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
  const num = (n) => Number(n || 0).toLocaleString('en-PK');
  const pkr = (minor, currency = 'PKR') => `${currency} ${(Number(minor) / 100).toLocaleString('en-PK', { maximumFractionDigits: 2 })}`;
  // MySQL DATETIME strings are the server's local time; show them as written.
  const when = (v) => (v ? esc(String(v).slice(0, 16).replace('T', ' ')) : '<span class="muted">—</span>');
  const day = (v) => (v ? esc(String(v).slice(0, 10)) : '<span class="muted">—</span>');
  const dash = '<span class="muted">—</span>';
  const qs = (obj) => {
    const p = new URLSearchParams();
    Object.entries(obj).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) p.set(k, v); });
    const s = p.toString();
    return s ? `?${s}` : '';
  };

  const TONE = {
    active: 'paid', approved: 'paid', completed: 'paid', served: 'paid', sent: 'paid', issued: 'trial', ok: 'paid',
    pending: 'pending', requested: 'pending', none: 'pending', not_configured: 'pending',
    suspended: 'failed', revoked: 'failed', rejected: 'failed', expired: 'failed', failed: 'failed', error: 'failed',
    online: 'paid', idle: 'pending',
    cancelled: 'refunded',
  };
  const LABEL = {
    issued: 'Issued', active: 'Active', suspended: 'Suspended', revoked: 'Revoked', expired: 'Expired',
    pending: 'Pending', approved: 'Approved', rejected: 'Rejected', cancelled: 'Cancelled',
    requested: 'Link issued', served: 'Served', completed: 'Completed', failed: 'Failed',
    sent: 'Email sent', not_configured: 'Email not configured', none: 'In-app only',
    online: 'Online', idle: 'Not seen recently', error: 'Sync error',
  };
  const badge = (status) => `<span class="badge badge--${TONE[status] || 'pending'}">${esc(LABEL[status] || status)}</span>`;

  function icon(path, size = 18) {
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
  }

  let toastTimer;
  function toast(message, kind = 'ok') {
    const el = document.getElementById('toast');
    el.textContent = message;
    el.className = `toast toast--${kind}`;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, 4200);
  }

  async function api(path) {
    try {
      return await DiwanApi.getJson(path);
    } catch (err) {
      if (err.status === 401) { showLogin(); throw err; }
      throw err;
    }
  }
  async function post(path, body) {
    try {
      return await DiwanApi.postJson(path, body);
    } catch (err) {
      if (err.status === 401) showLogin();
      throw err;
    }
  }

  // --- Dialog: confirmations and small forms --------------------------------
  // ask({title, body, ok, danger, onSubmit(formData)}) resolves to onSubmit's
  // result, or null if cancelled. Errors from onSubmit stay in the dialog.
  const dialog = document.getElementById('admin-dialog');
  function ask({ title, body = '', ok = 'Confirm', danger = false, cancellable = true, onSubmit }) {
    return new Promise((resolve) => {
      document.getElementById('dialog-title').textContent = title;
      document.getElementById('dialog-body').innerHTML = body;
      document.getElementById('dialog-error').textContent = '';
      const okBtn = document.getElementById('dialog-ok');
      okBtn.textContent = ok;
      okBtn.className = `btn btn--sm ${danger ? 'btn--danger' : 'btn--primary'}`;
      okBtn.disabled = false;
      const form = document.getElementById('dialog-form');
      const cancel = dialog.querySelector('[data-dialog-cancel]');
      cancel.hidden = !cancellable;
      const cleanup = () => { form.onsubmit = null; cancel.onclick = null; dialog.oncancel = null; };
      cancel.onclick = () => { cleanup(); dialog.close(); resolve(null); };
      dialog.oncancel = () => { cleanup(); resolve(null); };
      form.onsubmit = async (e) => {
        e.preventDefault();
        okBtn.disabled = true;
        try {
          const result = await onSubmit(new FormData(form));
          cleanup();
          dialog.close();
          resolve(result ?? true);
        } catch (err) {
          document.getElementById('dialog-error').textContent = err.message || 'Something went wrong.';
          okBtn.disabled = false;
        }
      };
      dialog.showModal();
      dialog.querySelector('input, select, textarea, button[type="submit"]')?.focus();
    });
  }

  function showKey(key, title) {
    return ask({
      title,
      ok: 'Done',
      cancellable: false,
      body: `<p>This key is shown here once. The customer can also see it in their dashboard; it has been emailed if mail is configured.</p>
             <div class="keybox"><div class="keybox__row"><code class="keybox__key">${esc(key)}</code>
             <button class="copy-btn" type="button" data-copy="${esc(key)}"><span>Copy</span></button></div></div>`,
      onSubmit: () => true,
    });
  }

  // --- Charts ---------------------------------------------------------------
  // Single-series only (one validated accent per theme, --chart-mark), so no
  // legend: the panel title names the series. Every chart has a table view.
  function columnChart(series, { label }) {
    if (!series.length) return '<p class="muted">No data in this range.</p>';
    const max = Math.max(1, ...series.map((d) => Number(d.value)));
    // Whole-number gridlines: a top that halves evenly, and no midline below 2.
    const top = max === 1 ? 1 : Math.ceil(max / (max <= 4 ? 2 : 4)) * (max <= 4 ? 2 : 4);
    const ticks = top >= 2 ? [top, top / 2, 0] : [top, 0];
    const first = series[0].day || series[0].label;
    const last = series[series.length - 1].day || series[series.length - 1].label;
    const cols = series.map((d) => {
      const v = Number(d.value);
      return `<div class="chart__col" data-tip="${esc(d.day || d.label)}: ${num(v)} ${esc(label)}">
        ${v ? `<span class="chart__bar" style="height:${(v / top) * 100}%"></span>` : ''}</div>`;
    }).join('');
    return `
      <div class="chart" data-chart role="img" aria-label="${esc(label)} per day, ${esc(first)} to ${esc(last)}; peak ${num(max)}">
        <div class="chart__plot">
          ${ticks.map((t) => `<div class="chart__grid" style="bottom:${(t / top) * 100}%"><span>${num(Math.round(t))}</span></div>`).join('')}
          <div class="chart__cols">${cols}</div>
        </div>
        <div class="chart__axis"><span>${esc(first)}</span><span>${esc(last)}</span></div>
        <div class="chart__tip" hidden></div>
      </div>
      <details class="chart__table"><summary>Show as table</summary>
        <table class="data-table"><thead><tr><th>Day</th><th>${esc(label)}</th></tr></thead>
        <tbody>${series.map((d) => `<tr><td>${esc(d.day || d.label)}</td><td>${num(d.value)}</td></tr>`).join('')}</tbody></table>
      </details>`;
  }

  // Horizontal bars with the value written on each row (direct labels).
  function barList(rows) {
    if (!rows.length) return '<p class="muted">No data yet.</p>';
    const max = Math.max(1, ...rows.map((r) => Number(r.value)));
    return `<ul class="barlist">${rows.map((r) => `
      <li><span class="barlist__label">${esc(r.label)}</span>
        <span class="barlist__track"><span class="barlist__bar" style="width:${(Number(r.value) / max) * 100}%"></span></span>
        <span class="barlist__value">${num(r.value)}</span></li>`).join('')}</ul>`;
  }

  // Hover tooltip for column charts (delegated, works for every chart).
  main.addEventListener('mousemove', (e) => {
    const chart = e.target.closest('[data-chart]');
    if (!chart) return;
    const tip = chart.querySelector('.chart__tip');
    const col = e.target.closest('.chart__col');
    chart.querySelectorAll('.chart__col.is-hot').forEach((c) => c.classList.remove('is-hot'));
    if (!col) { tip.hidden = true; return; }
    col.classList.add('is-hot');
    const box = chart.getBoundingClientRect();
    tip.textContent = col.dataset.tip;
    tip.hidden = false;
    const x = Math.min(box.width - tip.offsetWidth - 4, Math.max(4, e.clientX - box.left + 12));
    tip.style.transform = `translate(${x}px, ${Math.max(0, e.clientY - box.top - 40)}px)`;
  });
  main.addEventListener('mouseleave', () => {
    main.querySelectorAll('.chart__tip').forEach((t) => { t.hidden = true; });
  }, true);

  // --- Shared pieces --------------------------------------------------------
  function head(title, sub, actions = '') {
    return `<header class="view-head"><div><h1 tabindex="-1">${esc(title)}</h1>${sub ? `<p>${sub}</p>` : ''}</div>${actions}</header>`;
  }

  function kpi(label, value, sub = '', tone = '', href = '') {
    const tag = href ? 'a' : 'article';
    return `<${tag} class="stat kpi${tone ? ` stat--${tone}` : ''}"${href ? ` href="${href}"` : ''}>
      <div class="stat__top"><span class="stat__label">${esc(label)}</span></div>
      <div class="stat__value">${value}</div>
      ${sub ? `<div class="stat__sub">${sub}</div>` : ''}
    </${tag}>`;
  }

  function table(columns, rows, empty = 'Nothing here yet.') {
    if (!rows.length) return `<div class="dash-empty dash-empty--compact"><p>${empty}</p></div>`;
    return `<div class="table-wrap"><table class="data-table admin-table">
      <thead><tr>${columns.map((c) => `<th>${c}</th>`).join('')}</tr></thead>
      <tbody>${rows.join('')}</tbody></table></div>`;
  }

  function pager(page, key) {
    if (page.pages <= 1) return `<p class="pager muted">${num(page.total)} result${page.total === 1 ? '' : 's'}</p>`;
    return `<nav class="pager" aria-label="Pages">
      <span class="muted">${num(page.total)} results · page ${page.page} of ${page.pages}</span>
      <button class="btn btn--ghost btn--sm" type="button" data-page="${key}:${page.page - 1}" ${page.page <= 1 ? 'disabled' : ''}>Previous</button>
      <button class="btn btn--ghost btn--sm" type="button" data-page="${key}:${page.page + 1}" ${page.page >= page.pages ? 'disabled' : ''}>Next</button>
    </nav>`;
  }

  async function planOptions() {
    if (!plansCache) {
      const data = await api('/admin-plans.php');
      plansCache = data.plans.map((p) => ({ id: p.id, code: p.code, name: p.name, max_devices: p.max_devices, is_active: p.is_active }));
    }
    return plansCache;
  }
  function select(name, label, options, value) {
    return `<label class="filter"><span>${esc(label)}</span><select class="input" name="${name}">
      ${options.map(([v, t]) => `<option value="${esc(v)}"${String(v) === String(value) ? ' selected' : ''}>${esc(t)}</option>`).join('')}
    </select></label>`;
  }
  function search(value, placeholder) {
    return `<label class="filter filter--grow"><span>Search</span><input class="input" type="search" name="q" value="${esc(value)}" placeholder="${esc(placeholder)}"></label>`;
  }
  function filterBar(key, inner) {
    return `<form class="filters" data-filters="${key}" role="search">${inner}
      <button class="btn btn--ghost btn--sm" type="submit">Apply</button></form>`;
  }

  function loading() {
    main.innerHTML = '<div class="dash-skeleton" aria-busy="true"><div class="sk sk--title"></div><div class="sk sk--card"></div><div class="sk sk--panel"></div></div>';
  }
  function failed(err) {
    main.innerHTML = `<div class="dash-empty"><h3>Couldn't load this page</h3><p>${esc(err.message || 'Please try again.')}</p>
      <div class="dash-empty__cta"><button class="btn btn--ghost btn--sm" type="button" data-reload>Try again</button></div></div>`;
  }

  // --- Views ----------------------------------------------------------------
  async function viewOverview() {
    const d = await api('/admin-dashboard.php');
    const k = d.kpis;
    main.innerHTML = `
      ${head('Overview', 'Live figures from the database. “Downloads” count attempts that reached the installer; links that were issued but never used are not counted.')}
      <div class="kpis">
        ${kpi('Registered users', num(k.registered_users), `${num(k.suspended_accounts)} suspended`, '', '#users')}
        ${kpi('Active licensed customers', num(k.active_customers), 'with a usable licence', 'ok', '#licenses')}
        ${kpi('Awaiting approval', num(k.pending_requests), 'licence requests', k.pending_requests ? 'warn' : '', '#requests')}
        ${kpi('Rejected requests', num(k.rejected_requests), 'all time', '', '#requests')}
        ${kpi('Licence keys issued', num(k.licenses_issued), `${num(k.licenses_revoked)} revoked`, '', '#licenses')}
        ${kpi('Active licences', num(k.licenses_active), `${num(k.licenses_suspended)} suspended`, 'ok', '#licenses')}
        ${kpi('Expired licences', num(k.licenses_expired), 'past their end date', k.licenses_expired ? 'bad' : '', '#licenses')}
        ${kpi('Downloads', num(k.downloads_total), `${num(k.downloads_unique)} unique users`, '', '#downloads')}
        ${kpi('Business workspaces', num(d.workspaces.businesses), `${num(d.workspaces.active_counters)} counters`, '', '#businesses')}
        ${kpi('Counters online', num(d.workspaces.counters_online), 'synced in the last 10 minutes', 'ok', '#businesses')}
        ${kpi('Sync conflicts', num(d.workspaces.open_conflicts), 'waiting for review', d.workspaces.open_conflicts ? 'warn' : '', '#businesses')}
      </div>
      <div class="admin-grid">
        <section class="panel admin-grid__wide"><h2>Downloads, last 30 days</h2>${columnChart(d.downloads_over_time, { label: 'downloads' })}</section>
        <section class="panel"><h2>Downloads by plan</h2>${barList(d.downloads_by_plan)}</section>
        <section class="panel"><h2>Pending requests by plan</h2>${barList(d.requests_by_plan)}</section>
        <section class="panel"><h2>Latest license requests</h2>
          ${table(['Customer', 'Plan', 'Status', 'When'], d.recent_requests.map((r) => `
            <tr><td>${esc(r.email)}${r.business_name ? `<small class="cell-sub">${esc(r.business_name)}</small>` : ''}</td>
            <td>${esc(r.plan_name)}</td><td>${badge(r.status)}</td><td>${day(r.created_at)}</td></tr>`), 'No requests yet.')}
          <a class="dash-link" href="#requests">All requests →</a></section>
        <section class="panel"><h2>Newest registrations</h2>
          ${table(['User', 'Status', 'Joined'], d.recent_registrations.map((u) => `
            <tr data-href="#users/${u.id}"><td>${esc(u.email)}${u.business_name ? `<small class="cell-sub">${esc(u.business_name)}</small>` : ''}</td>
            <td>${badge(u.status)}</td><td>${day(u.created_at)}</td></tr>`), 'No users yet.')}
          <a class="dash-link" href="#users">All users →</a></section>
        <section class="panel admin-grid__wide"><h2>Latest download activity</h2>
          ${table(['When', 'Customer', 'Plan', 'Build', 'Status'], d.recent_downloads.map((e) => `
            <tr><td>${when(e.created_at)}</td><td>${e.email ? esc(e.email) : dash}</td><td>${esc(e.plan_name)}</td>
            <td>${esc(e.platform)}${e.variant ? ` · ${esc(e.variant)}` : ''} ${e.version ? `<small class="cell-sub">${esc(e.version)}</small>` : ''}</td><td>${badge(e.status)}</td></tr>`), 'No downloads yet.')}
        </section>
      </div>`;
  }

  async function viewUsers(id) {
    if (id) return viewUser(id);
    const f = filters.users;
    const [page, plans] = await Promise.all([api(`/admin-users.php${qs({ ...f, page_size: PAGE })}`), planOptions()]);
    main.innerHTML = `
      ${head('Users', 'Every registered customer account. Open one for licences, requests, downloads and history.')}
      ${filterBar('users', `${search(f.q, 'Name, email or business')}
        ${select('status', 'Account', [['', 'Any'], ['active', 'Active'], ['suspended', 'Suspended']], f.status)}
        ${select('plan', 'Plan', [['', 'Any'], ['none', 'No plan'], ...plans.map((p) => [p.code, p.name])], f.plan)}
        ${select('request', 'Request', [['', 'Any'], ['pending', 'Awaiting approval']], f.request)}`)}
      ${table(['User', 'Plan', 'Last request', 'Licences', 'Downloads', 'Joined', 'Last active', 'Account'], page.items.map((u) => `
        <tr data-href="#users/${u.id}">
          <td><a href="#users/${u.id}">${esc(u.email)}</a>${u.business_name || u.name ? `<small class="cell-sub">${esc(u.business_name || u.name)}</small>` : ''}</td>
          <td>${u.current_plan ? esc(u.current_plan) : dash}</td>
          <td>${u.last_request_status ? `${badge(u.last_request_status)} <small class="cell-sub">${esc(u.last_requested_plan)}</small>` : dash}</td>
          <td>${num(u.license_count)}</td><td>${num(u.downloads)}</td>
          <td>${day(u.created_at)}</td><td>${when(u.last_active_at)}</td>
          <td>${badge(u.status)}${u.verified ? '' : ' <small class="cell-sub">unverified</small>'}</td>
        </tr>`), 'No users match these filters.')}
      ${pager(page, 'users')}`;
  }

  async function viewUser(id) {
    const { user: u } = await api(`/admin-users.php?id=${encodeURIComponent(id)}`);
    const action = u.status === 'active'
      ? `<button class="btn btn--danger btn--sm" type="button" data-user-action="suspend" data-id="${u.id}">Suspend account</button>`
      : `<button class="btn btn--primary btn--sm" type="button" data-user-action="reactivate" data-id="${u.id}">Reactivate account</button>`;
    main.innerHTML = `
      <a class="dash-link back" href="#users">← All users</a>
      ${head(u.business_name || u.name || u.email, `${esc(u.email)} · ${badge(u.status)}`, `<div class="head-actions">${action}</div>`)}
      <div class="details admin-details">
        <div><span>Name</span><strong>${u.name ? esc(u.name) : '—'}</strong></div>
        <div><span>Phone</span><strong>${u.phone ? esc(u.phone) : '—'}</strong></div>
        <div><span>Joined</span><strong>${when(u.created_at)}</strong></div>
        <div><span>Last active</span><strong>${when(u.last_active_at)}</strong></div>
        <div><span>Email verified</span><strong>${u.email_verified_at ? when(u.email_verified_at) : 'Not yet'}</strong></div>
        <div><span>Sign-in</span><strong>${u.uses_apple ? 'Apple + password' : 'Password'}</strong></div>
      </div>
      <section class="panel"><h2>Licences</h2>
        ${table(['Key', 'Plan', 'Status', 'Devices', 'Expires', 'Issued'], u.licenses.map((l) => `
          <tr data-href="#licenses/${l.id}"><td><a href="#licenses/${l.id}"><code>${esc(l.license_key_prefix)}…</code></a></td>
          <td>${esc(l.plan_name)}</td><td>${badge(l.display_status)}</td><td>${num(l.devices)} / ${num(l.max_devices)}</td>
          <td>${l.expires_at ? day(l.expires_at) : 'Never'}</td><td>${day(l.created_at)}</td></tr>`), 'No licences.')}
      </section>
      <section class="panel"><h2>Requests</h2>
        ${table(['Plan', 'Status', 'Sent', 'Decided', 'Note'], u.requests.map((r) => `
          <tr><td>${esc(r.plan_name)}</td><td>${badge(r.status)}</td><td>${when(r.created_at)}</td>
          <td>${r.decided_at ? `${when(r.decided_at)}<small class="cell-sub">${esc(r.decided_by_email || '')}</small>` : dash}</td>
          <td class="wrap">${r.decision_note ? esc(r.decision_note) : (r.message ? `<em>${esc(r.message)}</em>` : dash)}</td></tr>`), 'No requests.')}
      </section>
      <section class="panel"><h2>Downloads</h2>
        ${table(['When', 'Build', 'Version', 'Plan', 'Channel', 'Status'], u.downloads.map((e) => `
          <tr><td>${when(e.created_at)}</td><td>${esc(e.platform)}${e.variant ? ` · ${esc(e.variant)}` : ''}</td><td>${e.version ? esc(e.version) : dash}</td>
          <td>${esc(e.plan_name)}</td><td>${esc(e.channel)}</td><td>${badge(e.status)}${e.failure_reason ? `<small class="cell-sub">${esc(e.failure_reason)}</small>` : ''}</td></tr>`), 'No downloads.')}
      </section>
      <section class="panel"><h2>History</h2>${historyList(u.history)}</section>`;
  }

  function historyList(rows) {
    if (!rows.length) return '<p class="muted">No recorded actions.</p>';
    return `<ol class="history">${rows.map((h) => `
      <li><strong>${esc(h.action)}</strong> <span class="muted">by ${esc(h.actor_label || h.actor_type || 'system')} · ${when(h.created_at)}</span>
      ${h.details ? `<code class="history__details">${esc(h.details)}</code>` : ''}</li>`).join('')}</ol>`;
  }

  async function viewRequests() {
    const f = filters.requests;
    const [page, plans] = await Promise.all([api(`/admin-license-requests.php${qs({ ...f, page_size: PAGE })}`), planOptions()]);
    main.innerHTML = `
      ${head('License requests', 'Nothing is issued until you approve. Approving a customer who already has a plan licence moves that licence to the new plan and extends it — same key, same devices.')}
      ${filterBar('requests', `${search(f.q, 'Email, name or business')}
        ${select('status', 'Status', [['', 'Any'], ['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['cancelled', 'Cancelled']], f.status)}
        ${select('plan', 'Plan', [['', 'Any'], ...plans.map((p) => [p.code, p.name])], f.plan)}`)}
      ${table(['Customer', 'Requested', 'Current plan', 'Message', 'Sent', 'Status', ''], page.items.map((r) => `
        <tr>
          <td><a href="#users/${r.customer_id}">${esc(r.email)}</a><small class="cell-sub">${esc(r.business_name || r.customer_name || '')}${r.contact_phone ? ` · ${esc(r.contact_phone)}` : ''}</small></td>
          <td><strong>${esc(r.plan_name)}</strong><small class="cell-sub">${pkr(r.price_minor, r.currency)} / month</small></td>
          <td>${r.current_plan ? esc(r.current_plan) : dash}</td>
          <td class="wrap">${r.message ? esc(r.message) : dash}</td>
          <td>${when(r.created_at)}</td>
          <td>${badge(r.status)}${r.decided_by_email ? `<small class="cell-sub">${esc(r.decided_by_email)}</small>` : ''}${r.customer_status === 'suspended' ? '<small class="cell-sub">account suspended</small>' : ''}</td>
          <td class="actions">${r.status === 'pending' ? `
            <button class="btn btn--primary btn--sm" type="button" data-approve="${r.id}" data-plan="${esc(r.plan_code)}" data-email="${esc(r.email)}">Approve</button>
            <button class="btn btn--ghost btn--sm" type="button" data-reject="${r.id}" data-email="${esc(r.email)}">Reject</button>` : (r.license_id ? `<a class="dash-link" href="#licenses/${r.license_id}">Licence →</a>` : '')}</td>
        </tr>`), 'No requests match these filters.')}
      ${pager(page, 'requests')}`;
  }

  async function approve(btn) {
    const plans = (await planOptions()).filter((p) => p.is_active);
    const settings = await api('/admin-settings.php');
    const requested = plans.find((p) => p.code === btn.dataset.plan);
    const result = await ask({
      title: `Approve request from ${btn.dataset.email}`,
      ok: 'Approve and issue',
      body: `
        <p>This issues a licence (or upgrades the customer's current plan licence) and notifies the customer.</p>
        ${select('plan_code', 'Plan', plans.map((p) => [p.code, p.name]), btn.dataset.plan)}
        <div class="form-row">
          <label class="field">Licence period (days)<input class="input" type="number" name="period_days" min="1" max="3660" value="${settings.settings.license_period_days}" required></label>
          <label class="field">Devices<input class="input" type="number" name="max_devices" min="1" max="1000" value="${requested ? requested.max_devices : 1}" required></label>
        </div>
        <label class="field">Note to the customer (optional)<textarea class="input" name="note" rows="2" maxlength="1000"></textarea></label>`,
      onSubmit: (fd) => post('/admin-license-requests.php', { id: Number(btn.dataset.approve), action: 'approve', ...Object.fromEntries(fd) }),
    });
    if (!result) return;
    if (result.license_key) await showKey(result.license_key, 'Licence issued');
    else toast(result.already ? 'Already approved — nothing changed.' : 'Approved: the existing licence was moved to the new plan and extended.');
    refreshCounts();
    route();
  }

  async function reject(btn) {
    const result = await ask({
      title: `Reject request from ${btn.dataset.email}?`,
      ok: 'Reject request',
      danger: true,
      body: '<label class="field">Reason shown to the customer (optional)<textarea class="input" name="note" rows="3" maxlength="1000"></textarea></label>',
      onSubmit: (fd) => post('/admin-license-requests.php', { id: Number(btn.dataset.reject), action: 'reject', note: fd.get('note') }),
    });
    if (!result) return;
    toast('Request rejected — the customer has been notified.');
    refreshCounts();
    route();
  }

  async function viewLicenses(id) {
    if (id) return viewLicense(id);
    const f = filters.licenses;
    const [page, plans] = await Promise.all([api(`/admin-licenses.php${qs({ ...f, page_size: PAGE })}`), planOptions()]);
    main.innerHTML = `
      ${head('Licenses', 'Keys are stored as a keyed hash plus an encrypted copy for the owner’s dashboard; only the first group is shown here. Search accepts an email or the start of a key.')}
      ${filterBar('licenses', `${search(f.q, 'Email, business or DIWAN-XXXX')}
        ${select('status', 'Status', [['', 'Any'], ['issued', 'Issued (not activated)'], ['active', 'Active'], ['expiring', 'Expiring in 14 days'], ['expired', 'Expired'], ['suspended', 'Suspended'], ['revoked', 'Revoked']], f.status)}
        ${select('plan', 'Plan', [['', 'Any'], ['none', 'No plan (legacy)'], ...plans.map((p) => [p.code, p.name])], f.plan)}`)}
      ${table(['Key', 'Customer', 'Plan', 'Status', 'Devices', 'Issued', 'Activated', 'Expires'], page.items.map((l) => `
        <tr data-href="#licenses/${l.id}">
          <td><a href="#licenses/${l.id}"><code>${esc(l.license_key_prefix)}…</code></a></td>
          <td>${esc(l.customer_email)}${l.business_name ? `<small class="cell-sub">${esc(l.business_name)}</small>` : ''}</td>
          <td>${esc(l.plan_name)}</td><td>${badge(l.display_status)}</td>
          <td>${num(l.devices)} / ${num(l.max_devices)}</td><td>${day(l.created_at)}</td>
          <td>${when(l.activated_at)}</td><td>${l.expires_at ? day(l.expires_at) : 'Never'}</td>
        </tr>`), 'No licences match these filters.')}
      ${pager(page, 'licenses')}`;
  }

  async function viewLicense(id) {
    const [{ license: l }, plans] = await Promise.all([api(`/admin-licenses.php?id=${encodeURIComponent(id)}`), planOptions()]);
    const st = l.display_status;
    const btn = (action, label, kind = 'ghost') => `<button class="btn btn--${kind} btn--sm" type="button" data-license-action="${action}" data-id="${l.id}">${label}</button>`;
    const actions = st === 'revoked' ? '' : [
      st === 'suspended' ? btn('reactivate', 'Reactivate', 'primary') : btn('suspend', 'Suspend'),
      btn('extend', 'Extend'),
      btn('change_plan', 'Change plan'),
      btn('set_devices', 'Device limit'),
      btn('reset_devices', 'Free all devices'),
      btn('rotate_key', 'Rotate key'),
      btn('revoke', 'Revoke', 'danger'),
    ].join('');
    main.innerHTML = `
      <a class="dash-link back" href="#licenses">← All licences</a>
      ${head(`${l.license_key_prefix}…`, `${esc(l.customer_email)} · ${badge(st)}`, `<div class="head-actions">${actions}</div>`)}
      ${st === 'revoked' ? '<p class="order-note">Revoked licences are final. Approve a new request to issue a fresh licence.</p>' : ''}
      <div class="details admin-details">
        <div><span>Plan</span><strong>${l.plan_name ? esc(l.plan_name) : 'None (legacy — all modules)'}</strong></div>
        <div><span>Source</span><strong>${esc({ admin: 'Approved request', trial: 'Free trial', apple: 'App Store' }[l.gateway] || `Order ${l.order_ref}`)}</strong></div>
        <div><span>Issued</span><strong>${when(l.created_at)}${l.issued_by_email ? `<small class="cell-sub">by ${esc(l.issued_by_email)}</small>` : ''}</strong></div>
        <div><span>First activated</span><strong>${when(l.activated_at)}</strong></div>
        <div><span>Expires</span><strong>${l.expires_at ? when(l.expires_at) : 'Never'}</strong></div>
        <div><span>Status changed</span><strong>${when(l.status_changed_at)}${l.status_reason ? `<small class="cell-sub">${esc(l.status_reason)}</small>` : ''}</strong></div>
        <div><span>Devices allowed</span><strong>${num(l.max_devices)}</strong></div>
        <div><span>Downloads</span><strong>${num(l.downloads_used)} / ${num(l.max_downloads)}</strong></div>
        <div><span>Customer</span><strong>${l.customer_id ? `<a href="#users/${l.customer_id}">Open profile →</a>` : '—'}</strong></div>
      </div>
      <section class="panel"><h2>Devices</h2>
        ${table(['Device', 'Activated', 'Last check-in', 'State', ''], l.devices.map((dv) => `
          <tr><td>${esc(dv.machine_hint || 'Unnamed device')}</td><td>${when(dv.activated_at)}</td><td>${when(dv.last_seen_at)}</td>
          <td>${dv.deactivated_at ? `${badge('cancelled')}<small class="cell-sub">freed ${day(dv.deactivated_at)}</small>` : badge('active')}</td>
          <td class="actions">${dv.deactivated_at ? '' : `<button class="btn btn--ghost btn--sm" type="button" data-deactivate="${dv.id}" data-id="${l.id}">Free this slot</button>`}</td></tr>`),
          'Not activated on any device yet.')}
        <p class="panel__note">A freed device stops working at its next check-in. The customer can activate a new device in the free slot.</p>
      </section>
      <section class="panel"><h2>History</h2>${historyList(l.history)}</section>`;
    main.dataset.plans = JSON.stringify(plans.map((p) => [p.id, p.name]));
  }

  const LICENSE_DIALOGS = {
    suspend: { title: 'Suspend this licence?', ok: 'Suspend', danger: true, reason: true,
      body: 'The app stops billing at its next check-in. You can reactivate it later.' },
    reactivate: { title: 'Reactivate this licence?', ok: 'Reactivate', reason: true, body: 'The app unlocks at its next check-in.' },
    revoke: { title: 'Revoke this licence permanently?', ok: 'Revoke', danger: true, reason: true,
      body: 'Revocation cannot be undone. The key stops working everywhere at the next check-in.' },
    extend: { title: 'Extend licence', ok: 'Extend', reason: true,
      body: 'Adds days from the current expiry (or from today if it has lapsed).',
      fields: '<label class="field">Days<input class="input" type="number" name="days" min="1" max="3660" value="30" required></label>' },
    set_devices: { title: 'Device limit', ok: 'Save',
      body: 'How many devices this key may be active on at once. Lowering it does not remove devices already active.',
      fields: '<label class="field">Devices<input class="input" type="number" name="max_devices" min="1" max="1000" value="1" required></label>' },
    reset_devices: { title: 'Free every device?', ok: 'Free all devices', danger: true,
      body: 'Every device on this licence stops working at its next check-in until the key is entered again.' },
    rotate_key: { title: 'Replace the licence key?', ok: 'Rotate key', danger: true,
      body: 'The old key stops working immediately. Devices must enter the new key; they keep their slots.' },
  };

  async function licenseAction(btn) {
    const action = btn.dataset.licenseAction;
    const id = Number(btn.dataset.id);
    let spec = LICENSE_DIALOGS[action];
    if (action === 'change_plan') {
      const plans = JSON.parse(main.dataset.plans || '[]');
      spec = { title: 'Change plan', ok: 'Change plan',
        body: 'Applies to every device at its next check-in. The key, expiry and devices stay the same.',
        fields: select('plan_id', 'Plan', plans, plans[0]?.[0]) };
    }
    const result = await ask({
      title: spec.title,
      ok: spec.ok,
      danger: spec.danger,
      body: `<p>${spec.body}</p>${spec.fields || ''}${spec.reason ? '<label class="field">Reason (recorded, and shown to the customer)<input class="input" name="reason" maxlength="255"></label>' : ''}`,
      onSubmit: (fd) => post('/admin-licenses.php', { id, action, ...Object.fromEntries(fd) }),
    });
    if (!result) return;
    if (result.license_key) await showKey(result.license_key, 'New licence key');
    else toast('Saved.');
    route();
  }

  // --- Businesses & counters ------------------------------------------------
  // One workspace per licence: every counter (POS terminal) of the business
  // shares its products, stock and records. Only figures are shown here.
  const KIND = {
    negative_stock: 'Stock below zero', rejected_change: 'Change refused', counter_reported: 'Reported by counter',
    concurrent_edit: 'Edited on two counters at once',
  };
  const LOAD = {
    ok: ['paid', 'Comfortable for shared hosting'],
    busy: ['pending', 'Busy: watch this, and plan a move to Business or Cloud hosting'],
    high: ['failed', 'High: move to Business or Cloud hosting'],
  };
  const megabytes = (n) => `${(Number(n) / 1048576).toLocaleString(undefined, { maximumFractionDigits: 1 })} MB`;
  function loadPanel(l) {
    const [tone, label] = LOAD[l.level] || LOAD.ok;
    const endpoints = Object.entries(l.by_endpoint).map(([k, n]) => `${esc(k)} ${num(n)}`).join(' · ') || 'none yet';
    return `<section class="panel"><h2>Server load</h2>
      <div class="kpis">
        ${kpi('This hour', num(l.this_hour), 'sync requests')}
        ${kpi('Last 24 hours', num(l.last_24h), endpoints)}
        ${kpi('Busiest hour', `${l.peak_per_second}/s`, `${num(l.peak_hour)} requests`, l.level === 'ok' ? 'ok' : 'warn')}
        ${kpi('Images stored', num(l.media.files), megabytes(l.media.bytes))}
      </div>
      <p class="panel__note"><span class="badge badge--${tone}">${esc(label)}</span>
        Counters sync every 2 seconds while busy and slow down when idle. The figure is a guide: see MULTI-COUNTER.md §9.</p>
    </section>`;
  }
  function counterState(t) {
    if (t.status === 'revoked') return badge('revoked');
    if (t.last_error) return badge('error');
    const seen = t.last_seen_at ? (Date.now() - Date.parse(String(t.last_seen_at).replace(' ', 'T'))) / 60000 : Infinity;
    return badge(seen <= 10 ? 'online' : 'idle');
  }

  async function viewBusinesses(id) {
    if (id) return viewBusiness(id);
    const f = filters.businesses;
    const [page, { load }] = await Promise.all([api(`/admin-businesses.php${qs(f)}`), api('/admin-businesses.php?load=1')]);
    main.innerHTML = `
      ${head('Businesses & Counters', 'One workspace per licence: every counter of a business shares its products, stock, sales and settings. Counters are limited by the licence’s device limit.')}
      ${loadPanel(load)}
      ${filterBar('businesses', `${search(f.q, 'Business, owner email or DIWAN-XXXX')}
        ${select('attention', 'Show', [['', 'All businesses'], ['1', 'Needing attention']], f.attention)}`)}
      ${table(['Business', 'Plan', 'Licence', 'Counters', 'Last seen', 'Sales (server)', 'Products', 'Attention'], page.items.map((b) => `
        <tr data-href="#businesses/${b.id}">
          <td><a href="#businesses/${b.id}">${esc(b.name)}</a><small class="cell-sub">${b.owner_email ? esc(b.owner_email) : 'No account'}</small></td>
          <td>${b.plan_name ? esc(b.plan_name) : '<span class="muted">Legacy</span>'}</td>
          <td>${badge(b.license_status)}<small class="cell-sub"><code>${esc(b.license_key_prefix)}…</code></small></td>
          <td>${num(b.counters)} / ${num(b.max_devices)}</td>
          <td>${when(b.last_seen_at)}</td>
          <td>${pkr(b.sales_total_minor)}<small class="cell-sub">${num(b.sales_count)} sales</small></td>
          <td>${num(b.products)}</td>
          <td>${Number(b.open_conflicts) ? `<span class="badge badge--failed">${num(b.open_conflicts)} conflict${Number(b.open_conflicts) === 1 ? '' : 's'}</span>` : ''}
              ${Number(b.counters_with_errors) ? `<span class="badge badge--pending">${num(b.counters_with_errors)} with errors</span>` : ''}
              ${!Number(b.open_conflicts) && !Number(b.counters_with_errors) ? dash : ''}
              ${b.seed_completed_at ? '' : '<small class="cell-sub">First data still uploading</small>'}</td>
        </tr>`), 'No business has connected a counter yet.')}
      ${pager(page, 'businesses')}`;
  }

  async function viewBusiness(id) {
    const { business: b } = await api(`/admin-businesses.php?id=${encodeURIComponent(id)}`);
    const t = b.totals;
    const records = Object.entries(b.records).map(([entity, n]) => ({ label: entity.replace(/_/g, ' '), value: n }));
    main.innerHTML = `
      <a class="dash-link back" href="#businesses">← All businesses</a>
      ${head(b.name, `${b.owner_email ? esc(b.owner_email) : 'No account'} · ${badge(b.license_status)}`,
        b.conflicts.length ? `<div class="head-actions"><button class="btn btn--ghost btn--sm" type="button" data-business-action="resolve_all_conflicts" data-id="${b.id}">Mark all conflicts reviewed</button></div>` : '')}
      <div class="kpis">
        ${kpi('Sales today', pkr(t.today_minor), 'all counters')}
        ${kpi('Last 30 days', pkr(t.last_30d_minor), 'all counters')}
        ${kpi('All time', pkr(t.sales_total_minor), `${num(t.sales_count)} sales`)}
        ${kpi('Open conflicts', num(b.conflicts.length), 'need a person', b.conflicts.length ? 'warn' : '')}
      </div>
      <div class="details admin-details">
        <div><span>Plan</span><strong>${b.plan_name ? esc(b.plan_name) : 'None (legacy)'}</strong></div>
        <div><span>Licence</span><strong><a href="#licenses/${b.license_id}"><code>${esc(b.license_key_prefix)}…</code></a></strong></div>
        <div><span>Counters allowed</span><strong>${num(b.max_devices)} <small class="cell-sub">the licence's device limit</small></strong></div>
        <div><span>Workspace created</span><strong>${when(b.created_at)}</strong></div>
        <div><span>First data</span><strong>${b.seed_completed_at ? `${when(b.seed_completed_at)}${b.seeded_by ? `<small class="cell-sub">from ${esc(b.seeded_by)}</small>` : ''}` : 'Still uploading'}</strong></div>
        <div><span>Changes recorded</span><strong>${num(b.sync_version)}</strong></div>
        <div><span>Images</span><strong>${num(b.media.files)} <small class="cell-sub">${megabytes(b.media.bytes)}, product photos and logo</small></strong></div>
        <div><span>Staff PINs</span><strong>${Number(b.pins_protected) ? 'Sealed by the counters (the server cannot read them)' : 'No sealed PIN received yet (older POS build)'}</strong></div>
        <div><span>Owner</span><strong>${b.customer_id ? `<a href="#users/${b.customer_id}">Open profile →</a>` : '—'}</strong></div>
      </div>
      <section class="panel"><h2>Counters</h2>
        ${table(['Counter', 'State', 'Device', 'Last seen', 'Waiting to sync', 'Sales (30 days)', ''], b.terminals.map((c) => `
          <tr><td><strong>${esc(c.name)}</strong><small class="cell-sub"><code>${esc(c.code)}</code>${c.app_version ? ` · v${esc(c.app_version)}` : ''}</small></td>
          <td>${counterState(c)}${c.last_error ? `<small class="cell-sub">${esc(c.last_error)}</small>` : ''}${!c.joined_at && c.status === 'active' ? '<small class="cell-sub">Has not loaded the business data yet</small>' : ''}</td>
          <td>${esc(c.machine_hint || c.platform || '—')}</td>
          <td>${when(c.last_seen_at)}</td>
          <td>${c.pending_count === null ? dash : num(c.pending_count)}</td>
          <td>${pkr(c.sales_30d_minor)}<small class="cell-sub">${num(c.sales_count)} sales in total</small></td>
          <td class="actions">${c.status === 'active' ? `
            <button class="btn btn--ghost btn--sm" type="button" data-business-action="rename_terminal" data-id="${b.id}" data-terminal="${c.id}" data-name="${esc(c.name)}">Rename</button>
            <button class="btn btn--danger btn--sm" type="button" data-business-action="revoke_terminal" data-id="${b.id}" data-terminal="${c.id}" data-name="${esc(c.name)}">Disconnect</button>` : `<small class="cell-sub">Disconnected ${day(c.revoked_at)}</small>`}</td></tr>`),
          'No counters yet.')}
        <p class="panel__note">Disconnecting a counter stops its sync at once and frees its device slot. Its records stay in the business.</p>
      </section>
      <div class="admin-grid">
        <section class="panel admin-grid__wide"><h2>Sync conflicts</h2>
          ${table(['When', 'What', 'Counter', ''], b.conflicts.map((x) => `
            <tr><td>${when(x.created_at)}</td><td><strong>${esc(KIND[x.kind] || x.kind)}</strong><small class="cell-sub">${esc(x.detail)}</small></td>
            <td>${esc(x.counter || '—')}</td>
            <td class="actions"><button class="btn btn--ghost btn--sm" type="button" data-business-action="resolve_conflict" data-id="${b.id}" data-conflict="${x.id}">Reviewed</button></td></tr>`),
            'Nothing to review.')}
        </section>
        <section class="panel"><h2>Shared records</h2>${barList(records)}</section>
      </div>
      <section class="panel"><h2>History</h2>${historyList(b.history)}</section>`;
  }

  async function businessAction(btn) {
    const action = btn.dataset.businessAction;
    const id = Number(btn.dataset.id);
    const name = btn.dataset.name || '';
    const specs = {
      rename_terminal: { title: 'Rename counter', ok: 'Save',
        body: `<label class="field">Name<input class="input" name="name" maxlength="60" value="${esc(name)}" required></label>` },
      revoke_terminal: { title: `Disconnect ${name}?`, ok: 'Disconnect', danger: true,
        body: `<p>${esc(name)} stops syncing at once and its device slot is freed. Everything it recorded stays in the business. To use the device again, enter the licence key on it and connect it from Settings.</p>
          <label class="field">Reason (recorded)<input class="input" name="reason" maxlength="255"></label>` },
      resolve_conflict: { title: 'Mark this conflict reviewed?', ok: 'Mark reviewed', body: '<p>It leaves the list; the record of it stays in the audit log.</p>' },
      resolve_all_conflicts: { title: 'Mark every conflict reviewed?', ok: 'Mark all reviewed', body: '<p>Clears this business’s conflict list.</p>' },
    };
    const spec = specs[action];
    const ok = await ask({
      title: spec.title, ok: spec.ok, danger: spec.danger, body: spec.body,
      onSubmit: (fd) => post('/admin-businesses.php', {
        id, action, terminal_id: Number(btn.dataset.terminal) || undefined, conflict_id: Number(btn.dataset.conflict) || undefined,
        ...Object.fromEntries(fd),
      }),
    });
    if (ok) { toast('Saved.'); refreshCounts(); route(); }
  }

  // --- Plans & features -----------------------------------------------------
  async function viewPlans() {
    const data = await api('/admin-plans.php');
    plansCache = null;
    const featureByCode = Object.fromEntries(data.features.map((f) => [f.code, f]));
    main.innerHTML = `
      ${head('Plans & Features', 'The single source of truth for the pricing page and for what each licence unlocks in the POS. Changes to a plan’s features reach every licence on it at the next check-in.',
        '<button class="btn btn--primary btn--sm" type="button" data-new-plan>New plan</button>')}
      <section class="panel"><h2>Public pricing preview</h2>
        <p class="panel__note">Exactly what the website shows (active plans only).</p>
        <div class="pricing__grid" id="plans-preview"></div>
      </section>
      ${data.plans.map((p) => planEditor(p, data.features)).join('')}
      <section class="panel"><div class="panel__head"><h2>Features</h2>
        <button class="btn btn--ghost btn--sm" type="button" data-edit-feature="">Add feature</button></div>
        <p class="panel__note">“App keys” are the POS modules and capabilities a feature unlocks. Availability says whether it is enforced in the app, delivered as a service, or not built yet.</p>
        ${table(['Feature', 'App keys', 'Availability', 'Plans', 'State', ''], data.features.map((f) => `
          <tr><td><strong>${esc(f.name)}</strong><small class="cell-sub"><code>${esc(f.code)}</code></small></td>
          <td class="wrap">${f.pos_entitlements.length ? f.pos_entitlements.map((k) => `<span class="key-tag">${esc(k)}</span>`).join(' ') : dash}</td>
          <td>${esc(f.availability)}</td><td>${num(f.plan_count)}</td><td>${badge(f.is_active ? 'active' : 'cancelled')}</td>
          <td class="actions"><button class="btn btn--ghost btn--sm" type="button" data-edit-feature="${esc(f.code)}">Edit</button></td></tr>`))}
      </section>`;
    main.dataset.features = JSON.stringify(data.features);
    main.dataset.posKeys = JSON.stringify(data.pos_keys);
    const publicShape = data.plans.filter((p) => p.is_active).map((p) => ({
      ...p,
      features: p.features.filter((f) => f.show_on_card && featureByCode[f.code]?.is_active)
        .map((f) => ({ code: f.code, label: f.display_label || f.name, description: featureByCode[f.code]?.description })),
    }));
    DiwanPlans.render(document.getElementById('plans-preview'), publicShape, { cta: (p) => ({ href: '#plans', label: p.cta_label }) });
  }

  function planEditor(p, features) {
    const included = Object.fromEntries(p.features.map((f) => [f.code, f]));
    return `
      <section class="panel plan-editor plan--${esc(p.theme)}">
        <div class="panel__head">
          <h2><span class="plan__tag">${esc(p.name)}</span> <small class="muted">${num(p.active_license_count)} active licences · ${num(p.active_customer_count)} customers</small></h2>
          ${badge(p.is_active ? 'active' : 'cancelled')}
        </div>
        <form class="plan-form" data-plan-form="${p.id}" data-active-licences="${p.active_license_count}">
          <div class="form-grid">
            <label class="field">Name<input class="input" name="name" value="${esc(p.name)}" maxlength="80" required></label>
            <label class="field">Price (${esc(p.currency)})<input class="input" type="number" name="price" min="0" step="0.01" value="${Number(p.price_minor) / 100}" required></label>
            ${select('billing_period', 'Billed per', [['month', 'Month'], ['year', 'Year']], p.billing_period)}
            ${select('theme', 'Colour', [['blue', 'Blue'], ['green', 'Green'], ['purple', 'Purple'], ['slate', 'Slate']], p.theme)}
            <label class="field">Button label<input class="input" name="cta_label" value="${esc(p.cta_label)}" maxlength="40" required></label>
            <label class="field">Devices per new licence<input class="input" type="number" name="max_devices" min="1" max="1000" value="${p.max_devices}" required></label>
            <label class="field">Order<input class="input" type="number" name="sort_order" value="${p.sort_order}"></label>
            <label class="field check"><input type="checkbox" name="is_active" ${p.is_active ? 'checked' : ''}> Offered (on the pricing page, open for requests)</label>
            <label class="field form-grid__wide">Tagline<input class="input" name="tagline" value="${esc(p.tagline || '')}" maxlength="190"></label>
          </div>
          <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Save plan details</button></div>
        </form>
        <p class="unlocks"><span class="muted">Unlocks in the app:</span> ${p.entitlements.length ? p.entitlements.map((k) => `<span class="key-tag">${esc(k)}</span>`).join(' ') : 'nothing'}</p>
        <details class="feature-editor">
        <summary>Edit features — ${num(p.features.length)} included, ${num(p.features.filter((f) => f.show_on_card).length)} on the card</summary>
        <form class="feature-form" data-features-form="${p.id}" data-active-licences="${p.active_license_count}">
          <div class="table-wrap"><table class="data-table admin-table">
            <thead><tr><th>Included</th><th>Feature</th><th>On card</th><th>Card label</th><th>Order</th></tr></thead>
            <tbody>${features.map((f) => {
              const inc = included[f.code];
              return `<tr${f.is_active ? '' : ' class="is-dim"'}>
                <td><input type="checkbox" name="inc" value="${esc(f.code)}" ${inc ? 'checked' : ''} aria-label="Include ${esc(f.name)}"></td>
                <td>${esc(f.name)}${f.is_active ? '' : ' <small class="cell-sub">inactive</small>'}</td>
                <td><input type="checkbox" name="card:${esc(f.code)}" ${inc?.show_on_card ? 'checked' : ''} aria-label="Show ${esc(f.name)} on the card"></td>
                <td><input class="input input--sm" name="label:${esc(f.code)}" value="${esc(inc?.display_label || '')}" placeholder="${esc(f.name)}" maxlength="120"></td>
                <td><input class="input input--sm input--num" type="number" name="order:${esc(f.code)}" value="${inc ? inc.sort_order : ''}"></td>
              </tr>`;
            }).join('')}</tbody></table></div>
          <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Save features</button></div>
        </form>
        </details>
      </section>`;
  }

  function planPayload(fd) {
    return {
      name: fd.get('name'),
      tagline: fd.get('tagline'),
      price_minor: Math.round(Number(fd.get('price')) * 100),
      billing_period: fd.get('billing_period'),
      theme: fd.get('theme'),
      cta_label: fd.get('cta_label'),
      max_devices: fd.get('max_devices'),
      sort_order: fd.get('sort_order'),
      is_active: fd.get('is_active') === 'on',
      code: fd.get('code') || undefined,
    };
  }

  async function savePlan(form) {
    const fd = new FormData(form);
    const result = await ask({
      title: 'Save plan details?',
      ok: 'Save',
      body: `<p>The pricing page updates at once. Price changes do not alter existing licences.${fd.get('is_active') === 'on' ? '' : ' <strong>Unticking “Offered” hides the plan and stops new requests;</strong> licences already on it keep working.'}</p>`,
      onSubmit: () => post('/admin-plans.php', { action: 'update_plan', id: Number(form.dataset.planForm), plan: planPayload(fd) }),
    });
    if (result) { toast('Plan saved.'); route(); }
  }

  async function saveFeatures(form) {
    const fd = new FormData(form);
    const items = fd.getAll('inc').map((code, i) => ({
      code,
      show_on_card: fd.get(`card:${code}`) === 'on',
      display_label: fd.get(`label:${code}`) || '',
      sort_order: Number(fd.get(`order:${code}`)) || i + 1,
    }));
    const affected = Number(form.dataset.activeLicences);
    const result = await ask({
      title: 'Change what this plan unlocks?',
      ok: 'Save features',
      danger: affected > 0,
      body: `<p>This changes access for <strong>${num(affected)} active licence${affected === 1 ? '' : 's'}</strong> on this plan at their next check-in — adding a feature grants it, removing one takes it away. The change is recorded in the audit log.</p>`,
      onSubmit: () => post('/admin-plans.php', { action: 'set_features', id: Number(form.dataset.featuresForm), features: items }),
    });
    if (result) { toast('Features saved.'); route(); }
  }

  async function newPlan() {
    const result = await ask({
      title: 'New plan',
      ok: 'Create plan',
      body: `<p>Created hidden; add features and tick “Offered” when it is ready.</p>
        <div class="form-row"><label class="field">Code<input class="input" name="code" pattern="[a-z][a-z0-9_]{1,39}" placeholder="e.g. enterprise" required></label>
        <label class="field">Name<input class="input" name="name" maxlength="80" required></label></div>
        <div class="form-row"><label class="field">Price (PKR)<input class="input" type="number" name="price" min="0" step="0.01" required></label>
        ${select('theme', 'Colour', [['slate', 'Slate'], ['blue', 'Blue'], ['green', 'Green'], ['purple', 'Purple']], 'slate')}</div>
        <input type="hidden" name="billing_period" value="month"><input type="hidden" name="cta_label" value="Request Access">
        <input type="hidden" name="max_devices" value="1"><input type="hidden" name="sort_order" value="10">`,
      onSubmit: (fd) => post('/admin-plans.php', { action: 'create_plan', plan: { ...planPayload(fd), is_active: false } }),
    });
    if (result) { toast('Plan created.'); route(); }
  }

  async function editFeature(code) {
    const features = JSON.parse(main.dataset.features || '[]');
    const keys = JSON.parse(main.dataset.posKeys || '[]');
    const f = features.find((x) => x.code === code) || { code: '', name: '', description: '', pos_entitlements: [], availability: 'app', is_active: true };
    const result = await ask({
      title: code ? `Edit ${f.name}` : 'Add feature',
      ok: 'Save feature',
      body: `
        <div class="form-row"><label class="field">Code<input class="input" name="code" value="${esc(f.code)}" ${code ? 'readonly' : ''} pattern="[a-z][a-z0-9_]{1,59}" required></label>
        <label class="field">Name<input class="input" name="name" value="${esc(f.name)}" maxlength="120" required></label></div>
        <label class="field">Description<input class="input" name="description" value="${esc(f.description || '')}" maxlength="500"></label>
        ${select('availability', 'Availability', [['app', 'Enforced in the app'], ['service', 'Service by the Diwan team'], ['planned', 'Not built yet']], f.availability)}
        <fieldset class="key-picker"><legend>Unlocks in the app</legend>${keys.map((k) => `
          <label class="key-pick"><input type="checkbox" name="key" value="${esc(k)}" ${f.pos_entitlements.includes(k) ? 'checked' : ''}> ${esc(k)}</label>`).join('')}</fieldset>
        <label class="field check"><input type="checkbox" name="is_active" ${f.is_active ? 'checked' : ''}> Active (an inactive feature unlocks nothing on any plan)</label>`,
      onSubmit: (fd) => post('/admin-plans.php', { action: 'save_feature', feature: {
        code: fd.get('code'), name: fd.get('name'), description: fd.get('description'), availability: fd.get('availability'),
        pos_entitlements: fd.getAll('key'), is_active: fd.get('is_active') === 'on',
      } }),
    });
    if (result) { toast('Feature saved.'); route(); }
  }

  // --- Downloads ------------------------------------------------------------
  async function viewDownloads() {
    const f = filters.downloads;
    const [d, plans] = await Promise.all([api(`/admin-downloads.php${qs({ ...f, page_size: PAGE })}`), planOptions()]);
    const s = d.summary;
    const csv = `/api/admin-downloads.php${qs({ ...f, page: '', format: 'csv' })}`;
    main.innerHTML = `
      ${head('Downloads & analytics', 'Recorded by the real download path only — a page view never counts. <b>Delivered</b> = the link was redeemed and the installer started (or GitHub’s signed link was handed over). <b>Completed</b> is only measurable for installers we stream ourselves.',
        `<a class="btn btn--ghost btn--sm" href="${esc(csv)}">Export CSV</a>`)}
      ${filterBar('downloads', `
        <label class="filter"><span>From</span><input class="input" type="date" name="from" value="${esc(f.from)}"></label>
        <label class="filter"><span>To</span><input class="input" type="date" name="to" value="${esc(f.to)}"></label>
        ${select('plan', 'Plan', [['', 'Any'], ['none', 'No plan'], ...plans.map((p) => [p.code, p.name])], f.plan)}
        ${select('platform', 'Platform', [['', 'Any'], ...d.facets.platforms.map((p) => [p, p])], f.platform)}
        ${select('version', 'Version', [['', 'Any'], ...d.facets.versions.map((v) => [v, v])], f.version)}
        ${select('status', 'Status', [['', 'Any'], ['requested', 'Link issued'], ['served', 'Served'], ['completed', 'Completed'], ['failed', 'Failed']], f.status)}
        ${select('channel', 'Channel', [['', 'Any'], ['dashboard', 'Dashboard'], ['licence_key', 'Licence key'], ['other', 'Other']], f.channel)}
        ${search(f.q, 'Customer email')}`)}
      <div class="kpis kpis--5">
        ${kpi('Delivered downloads', num(s.delivered), 'served or completed', 'ok')}
        ${kpi('Unique users', num(s.unique_users), 'who received an installer')}
        ${kpi('Completed', num(s.completed), 'streamed to the end')}
        ${kpi('Links issued', num(s.requested), 'every attempt, incl. unused')}
        ${kpi('Failed', num(s.failed), 'refused or interrupted', s.failed ? 'warn' : '')}
      </div>
      <div class="admin-grid">
        <section class="panel admin-grid__wide"><h2>Delivered downloads per day</h2>${columnChart(d.by_day, { label: 'downloads' })}</section>
        <section class="panel"><h2>By plan</h2>${barList(d.by_plan)}</section>
        <section class="panel"><h2>By platform</h2>${barList(d.by_platform)}</section>
        <section class="panel"><h2>By version</h2>${barList(d.by_version)}</section>
      </div>
      <section class="panel"><h2>Events</h2>
        ${table(['When', 'Customer', 'Plan', 'Build', 'Version', 'Channel', 'Licence', 'Status'], d.items.map((e) => `
          <tr><td>${when(e.created_at)}</td><td>${e.customer_id ? `<a href="#users/${e.customer_id}">${esc(e.email)}</a>` : dash}</td>
          <td>${esc(e.plan_name)}</td><td>${esc(e.platform || '')}${e.variant ? ` · ${esc(e.variant)}` : ''}</td>
          <td>${e.version ? esc(e.version) : dash}</td><td>${esc(e.channel)}</td><td>${e.license_status ? esc(e.license_status) : dash}</td>
          <td>${badge(e.status)}${e.failure_reason ? `<small class="cell-sub">${esc(e.failure_reason)}</small>` : ''}</td></tr>`), 'No download events match these filters.')}
        ${pager(d, 'downloads')}
      </section>`;
  }

  // --- Versions -------------------------------------------------------------
  async function viewVersions(notified) {
    const d = await api('/admin-releases.php');
    const versions = [...new Set(d.releases.filter((r) => r.is_active).map((r) => r.version))];
    main.innerHTML = `
      ${head('Application versions', 'The installers customers can download. Builds are registered by <code>scripts/sync-github-release.php</code>; here you can take one offline, edit its notes and tell licensed customers about a release.')}
      <section class="panel"><h2>Announce a version</h2>
        <form class="filters" data-announce>
          ${select('version', 'Version', versions.length ? versions.map((v) => [v, v]) : [['', 'No active builds']], versions[0] || '')}
          <button class="btn btn--primary btn--sm" type="submit" ${versions.length ? '' : 'disabled'}>Notify licensed customers</button>
        </form>
        <p class="panel__note">Sends an in-app notification (and an email when mail is configured) to every customer with a usable licence — once per customer per version.${notified !== undefined ? ` <strong>${num(notified)} customers notified just now.</strong>` : ''}</p>
      </section>
      <section class="panel"><h2>Builds</h2>
        ${table(['Version', 'Platform', 'File', 'Source', 'Released', 'Downloads', 'State', ''], d.releases.map((r) => `
          <tr><td><strong>${esc(r.version)}</strong></td><td>${esc(r.platform)}${r.variant ? ` · ${esc(r.variant)}` : ''}</td>
          <td>${esc(r.filename)}</td><td>${esc(r.source)}${r.github_tag ? `<small class="cell-sub">${esc(r.github_tag)}</small>` : ''}</td>
          <td>${day(r.released_at)}</td><td>${num(r.downloads)}</td><td>${badge(r.is_active ? 'active' : 'cancelled')}</td>
          <td class="actions">
            <button class="btn btn--ghost btn--sm" type="button" data-release-toggle="${r.id}" data-active="${r.is_active ? 1 : 0}">${r.is_active ? 'Take offline' : 'Put online'}</button>
            <button class="btn btn--ghost btn--sm" type="button" data-release-notes="${r.id}" data-notes="${esc(r.release_notes || '')}">Notes</button>
          </td></tr>`), 'No builds registered yet.')}
      </section>`;
  }

  // --- Notifications --------------------------------------------------------
  async function viewNotifications() {
    const d = await api('/admin-notifications.php');
    main.innerHTML = `
      ${head('Notifications', 'Your inbox, and what the platform has sent to customers. Emails show what actually happened — “not configured” means no mail server is set up, so only the in-app copy exists.',
        d.unread ? '<button class="btn btn--ghost btn--sm" type="button" data-admin-read>Mark all read</button>' : '')}
      ${d.mail_configured ? '' : '<p class="notice-bar">Outbound email is not configured (MAIL_FROM / MAIL_HOST in the server .env). Customers still see every notification in their dashboard.</p>'}
      <div class="admin-grid">
        <section class="panel"><h2>Inbox</h2>
          <ul class="notices">${d.inbox.length ? d.inbox.map((n) => `
            <li class="notice${n.is_read ? '' : ' is-unread'}"><strong>${esc(n.title)}</strong><p>${esc(n.body)}</p>
            <small>${when(n.created_at)}${n.link ? ` · <a href="${esc(n.link)}">Open</a>` : ''}</small></li>`).join('') : '<li class="notice"><p>Nothing yet.</p></li>'}</ul>
        </section>
        <section class="panel"><h2>Sent to customers</h2>
          ${table(['When', 'Customer', 'Notice', 'Email'], d.customer_notices.map((n) => `
            <tr><td>${when(n.created_at)}</td><td>${esc(n.email)}</td><td class="wrap">${esc(n.title)}</td><td>${badge(n.email_status)}</td></tr>`), 'Nothing sent yet.')}
        </section>
      </div>`;
  }

  // --- Audit ----------------------------------------------------------------
  async function viewAudit() {
    const f = filters.audit;
    const page = await api(`/admin-audit-logs.php${qs({ ...f, page_size: PAGE })}`);
    main.innerHTML = `
      ${head('Audit logs', 'Every sensitive action, newest first. Entries are written in the same transaction as the change and are never edited.')}
      ${filterBar('audit', `${search(f.q, 'Actor or details')}
        ${select('action', 'Area', [['', 'Any'], ['license_request.', 'Requests'], ['license.', 'Licences'], ['plan.', 'Plans'], ['feature.', 'Features'], ['customer.', 'Accounts'], ['admin.', 'Admin sign-in'], ['settings.', 'Settings'], ['release.', 'Versions'], ['downloads.', 'Exports']], f.action)}
        ${select('actor_type', 'Actor', [['', 'Any'], ['admin', 'Administrator'], ['customer', 'Customer'], ['terminal', 'POS counter'], ['system', 'System']], f.actor_type)}`)}
      ${table(['When', 'Actor', 'Action', 'Target', 'Details', 'IP'], page.items.map((a) => `
        <tr><td>${when(a.created_at)}</td><td>${esc(a.actor_label || a.actor_type)}<small class="cell-sub">${esc(a.actor_type)}</small></td>
        <td><code>${esc(a.action)}</code></td>
        <td>${a.target_type ? `${esc(a.target_type)} #${esc(a.target_id ?? '')}` : dash}</td>
        <td class="wrap"><code class="history__details">${a.details ? esc(a.details) : ''}</code></td><td>${a.ip ? esc(a.ip) : dash}</td></tr>`), 'No entries match these filters.')}
      ${pager(page, 'audit')}`;
  }

  // --- Settings -------------------------------------------------------------
  async function viewSettings() {
    const d = await api('/admin-settings.php');
    const env = d.environment;
    const field = (key, def) => {
      const v = d.settings[key];
      if (def.type === 'bool') return `<label class="field check"><input type="checkbox" name="${key}" ${v ? 'checked' : ''}> ${esc(def.label)}</label>`;
      return `<label class="field">${esc(def.label)}<input class="input" name="${key}" ${def.type === 'int' ? `type="number" min="${def.min}" max="${def.max}"` : 'type="email"'} value="${esc(v)}"></label>`;
    };
    // Both of these should be off; "On" is shown as a warning.
    const onOff = (on) => `<span class="badge badge--${on ? 'failed' : 'paid'}">${on ? 'On' : 'Off'}</span>`;
    main.innerHTML = `
      ${head('Admin settings', 'Platform rules you can change here. Deployment settings come from the server’s .env and are shown read-only.')}
      <div class="admin-grid">
        <section class="panel"><h2>Licensing &amp; requests</h2>
          <form class="settings-form" data-settings data-types='${esc(JSON.stringify(Object.fromEntries(Object.entries(d.definitions).map(([k, v]) => [k, v.type]))))}'>
            ${Object.entries(d.definitions).map(([k, def]) => field(k, def)).join('')}
            <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Save settings</button></div>
          </form>
        </section>
        <section class="panel"><h2>Deployment (read-only)</h2>
          <div class="details admin-details admin-details--2">
            <div><span>Environment</span><strong>${esc(env.app_env)}</strong></div>
            <div><span>Outbound email</span><strong>${env.mail_configured ? `Configured (${esc(env.mail_driver)})` : 'Not configured'}</strong></div>
            <div><span>Online payments</span><strong>${onOff(env.payments_enabled)}</strong></div>
            <div><span>Free trials (no approval)</span><strong>${onOff(env.trial_signup_enabled)}</strong></div>
            <div><span>Offline grace period</span><strong>${num(env.max_offline_days)} days</strong></div>
          </div>
          <p class="panel__note">Online payments and free trials issue licences without your approval, so both should stay off. The offline grace period is how long a device keeps working without checking in (so a revocation reaches an offline device within this many days).</p>
        </section>
        <section class="panel"><h2>Your session</h2>
          <p>Sign out of the admin panel on every browser — for example after using a shared computer.</p>
          <button class="btn btn--danger btn--sm" type="button" data-logout-everywhere>Sign out everywhere</button>
        </section>
      </div>`;
  }

  // --- Router ---------------------------------------------------------------
  const VIEWS = {
    overview: viewOverview, users: viewUsers, requests: viewRequests, licenses: viewLicenses, businesses: viewBusinesses, plans: viewPlans,
    downloads: viewDownloads, versions: viewVersions, notifications: viewNotifications, audit: viewAudit, settings: viewSettings,
  };

  async function route(extra) {
    const [name, id] = window.location.hash.replace(/^#\/?/, '').split('/');
    const section = Object.hasOwn(VIEWS, name) ? name : 'overview';
    document.querySelectorAll('[data-nav]').forEach((a) => {
      if (a.dataset.nav === section) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });
    document.querySelector(`.tabbar [data-nav="${section}"]`)?.scrollIntoView({ block: 'nearest', inline: 'center' });
    document.title = `${SECTIONS[section]} — Diwan Admin`;
    loading();
    try {
      await VIEWS[section](id ?? extra);
      main.querySelector('h1')?.focus({ preventScroll: true });
    } catch (err) {
      if (err.status !== 401) failed(err);
    }
  }

  async function refreshCounts() {
    try {
      const s = await DiwanApi.getJson('/admin-session.php');
      document.querySelectorAll('[data-count]').forEach((el) => {
        const n = s.counts[el.dataset.count];
        el.textContent = String(n);
        el.hidden = !n;
      });
    } catch { /* counts are decoration */ }
  }

  // --- Events (delegated) ---------------------------------------------------
  window.addEventListener('hashchange', () => { window.scrollTo(0, 0); route(); });

  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (form.dataset.filters) {
      e.preventDefault();
      const key = form.dataset.filters;
      Object.assign(filters[key], Object.fromEntries(new FormData(form)), { page: 1 });
      route();
    } else if (form.dataset.planForm) {
      e.preventDefault(); savePlan(form);
    } else if (form.dataset.featuresForm) {
      e.preventDefault(); saveFeatures(form);
    } else if (form.hasAttribute('data-announce')) {
      e.preventDefault();
      const version = new FormData(form).get('version');
      const ok = await ask({ title: `Announce ${version}?`, ok: 'Notify customers',
        body: '<p>Every customer with a usable licence gets a notification. Customers already told about this version are skipped.</p>',
        onSubmit: () => post('/admin-releases.php', { action: 'announce', version }) });
      if (ok) viewVersions(ok.notified);
    } else if (form.hasAttribute('data-settings')) {
      e.preventDefault();
      const types = JSON.parse(form.dataset.types);
      const fd = new FormData(form);
      const settings = Object.fromEntries(Object.entries(types).map(([k, t]) => [k, t === 'bool' ? fd.get(k) === 'on' : fd.get(k)]));
      try {
        await post('/admin-settings.php', { settings });
        toast('Settings saved.');
      } catch (err) { toast(err.message, 'error'); }
    }
  });

  document.addEventListener('click', async (e) => {
    const t = e.target;
    const go = (sel) => t.closest(sel);
    let el;
    if ((el = go('[data-page]'))) {
      const [key, page] = el.dataset.page.split(':');
      filters[key].page = Number(page);
      route();
    } else if ((el = go('[data-approve]'))) {
      approve(el);
    } else if ((el = go('[data-reject]'))) {
      reject(el);
    } else if ((el = go('[data-license-action]'))) {
      licenseAction(el);
    } else if ((el = go('[data-business-action]'))) {
      businessAction(el);
    } else if ((el = go('[data-deactivate]'))) {
      const ok = await ask({ title: 'Free this device slot?', ok: 'Free slot', danger: true,
        body: '<p>The device stops working at its next check-in, and the slot can be used by another device.</p>',
        onSubmit: () => post('/admin-licenses.php', { id: Number(el.dataset.id), action: 'deactivate_device', activation_id: Number(el.dataset.deactivate) }) });
      if (ok) { toast('Device freed.'); route(); }
    } else if ((el = go('[data-user-action]'))) {
      const suspend = el.dataset.userAction === 'suspend';
      const ok = await ask({
        title: suspend ? 'Suspend this account?' : 'Reactivate this account?',
        ok: suspend ? 'Suspend' : 'Reactivate',
        danger: suspend,
        body: `<p>${suspend ? 'Signs the customer out everywhere and suspends every licence they own (their devices lock at the next check-in). Nothing is deleted.' : 'Restores sign-in and every licence the account owns.'}</p>
          <label class="field">Reason (recorded, and shown to the customer)<input class="input" name="reason" maxlength="255"></label>`,
        onSubmit: (fd) => post('/admin-users.php', { id: Number(el.dataset.id), action: el.dataset.userAction, reason: fd.get('reason') }),
      });
      if (ok) { toast(suspend ? 'Account suspended.' : 'Account reactivated.'); route(); }
    } else if ((el = go('[data-new-plan]'))) {
      newPlan();
    } else if ((el = go('[data-edit-feature]'))) {
      editFeature(el.dataset.editFeature);
    } else if ((el = go('[data-release-toggle]'))) {
      const online = el.dataset.active !== '1';
      const ok = await ask({ title: online ? 'Put this build online?' : 'Take this build offline?', ok: online ? 'Put online' : 'Take offline', danger: !online,
        body: `<p>${online ? 'Customers can download it again.' : 'Customers can no longer download it; a link already issued for it will fail.'}</p>`,
        onSubmit: () => post('/admin-releases.php', { action: 'update', id: Number(el.dataset.releaseToggle), is_active: online }) });
      if (ok) { toast('Saved.'); route(); }
    } else if ((el = go('[data-release-notes]'))) {
      const ok = await ask({ title: 'Release notes', ok: 'Save notes',
        body: `<label class="field">Notes<textarea class="input" name="notes" rows="6" maxlength="5000">${esc(el.dataset.notes)}</textarea></label>`,
        onSubmit: (fd) => post('/admin-releases.php', { action: 'update', id: Number(el.dataset.releaseNotes), release_notes: fd.get('notes') }) });
      if (ok) { toast('Notes saved.'); route(); }
    } else if ((el = go('[data-admin-read]'))) {
      await post('/admin-notifications.php', {});
      refreshCounts();
      route();
    } else if ((el = go('[data-copy]'))) {
      try { await navigator.clipboard.writeText(el.dataset.copy); toast('Copied.'); } catch { toast('Select the key and copy it manually.', 'error'); }
    } else if ((el = go('[data-reload]'))) {
      route();
    } else if ((el = go('[data-logout]'))) {
      try { await post('/admin-logout.php', {}); } catch { /* signing out regardless */ }
      showLogin();
    } else if ((el = go('[data-logout-everywhere]'))) {
      const ok = await ask({ title: 'Sign out everywhere?', ok: 'Sign out everywhere', danger: true,
        body: '<p>Ends every admin session for your account, including this one.</p>',
        onSubmit: () => post('/admin-logout.php', { everywhere: true }) });
      if (ok) showLogin();
    } else if ((el = go('tr[data-href]')) && !go('a, button, input, select')) {
      window.location.hash = el.dataset.href;
    }
  });

  // --- Session --------------------------------------------------------------
  function showLogin() {
    DiwanApi.setHeader('X-CSRF-Token', null);
    document.getElementById('admin-app').hidden = true;
    document.getElementById('admin-login').hidden = false;
    document.title = 'Admin sign in — Diwan';
    document.querySelector('#login-form input[name="email"]')?.focus();
  }

  function startApp(session) {
    DiwanApi.setHeader('X-CSRF-Token', session.csrf_token);
    document.getElementById('admin-login').hidden = true;
    document.getElementById('admin-app').hidden = false;
    document.querySelectorAll('[data-admin-name]').forEach((n) => { n.textContent = session.admin.name; });
    document.querySelectorAll('[data-admin-email]').forEach((n) => { n.textContent = session.admin.email; });
    document.querySelectorAll('[data-avatar]').forEach((n) => { n.textContent = session.admin.name.charAt(0).toUpperCase(); });
    refreshCounts();
    route();
  }

  document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const status = document.getElementById('login-status');
    const button = form.querySelector('button');
    button.disabled = true;
    status.textContent = 'Signing in…';
    const { ok, data } = await DiwanApi.post('/admin-login.php', Object.fromEntries(new FormData(form)))
      .catch(() => ({ ok: false, data: { error: 'The server could not be reached.' } }));
    button.disabled = false;
    if (!ok) { status.textContent = data.error || 'Sign-in failed.'; return; }
    status.textContent = '';
    form.reset();
    startApp({ ...data, admin: data.admin });
  });

  (async () => {
    const { ok, data } = await DiwanApi.get('/admin-session.php').catch(() => ({ ok: false, data: {} }));
    if (ok) startApp(data);
    else showLogin();
  })();
})();
