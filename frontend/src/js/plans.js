// Pricing cards rendered from GET /api/plans.php — the same database rows the
// licence entitlements come from, so a card can never promise something the
// app does not unlock (and prices are never typed into HTML).
//
// Any element with [data-plans] is filled on load. Exposes one global,
// `DiwanPlans`, for pages that render cards themselves (the dashboard).
(() => {
  const PERIOD = { month: 'per month', year: 'per year' };

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  /** Integer paisa → "10,000" (whole rupees; a paisa remainder is shown). */
  function formatAmount(minor) {
    const rupees = minor / 100;
    return rupees.toLocaleString('en-PK', {
      minimumFractionDigits: Number.isInteger(rupees) ? 0 : 2,
      maximumFractionDigits: 2,
    });
  }

  function formatPrice(minor, currency) {
    return `${currency} ${formatAmount(minor)}`;
  }

  /**
   * One plan card. opts.cta(plan) → {href, label, attrs?} lets a page decide
   * where the button goes; opts.current marks the customer's own plan.
   */
  function card(plan, opts = {}) {
    const cta = opts.cta ? opts.cta(plan) : {
      href: `/auth/login.html?plan=${encodeURIComponent(plan.code)}`,
      label: plan.cta_label,
    };
    const isCurrent = opts.current === plan.code;
    const features = plan.features.map((f) =>
      `<li${f.description ? ` title="${escapeHtml(f.description)}"` : ''}>${escapeHtml(f.label)}</li>`).join('');
    return `
      <article class="plan plan--${escapeHtml(plan.theme)}${isCurrent ? ' is-current' : ''}" data-plan-card="${escapeHtml(plan.code)}">
        <p class="plan__tag">${escapeHtml(plan.name)}</p>
        <div class="plan__head">
          <p class="plan__desc">${escapeHtml(plan.tagline || '')}</p>
        </div>
        <p class="plan__price">
          <span class="plan__amount"><span class="plan__currency">${escapeHtml(plan.currency)}</span>${formatAmount(plan.price_minor)}</span>
          <span class="plan__period">${PERIOD[plan.billing_period] || ''}</span>
        </p>
        ${isCurrent
          ? '<span class="btn btn--ghost plan__cta" aria-disabled="true">Your current plan</span>'
          : `<a class="btn plan__cta" href="${escapeHtml(cta.href)}" data-plan="${escapeHtml(plan.code)}" ${cta.attrs || ''}>${escapeHtml(cta.label)}</a>`}
        <p class="plan__note">No online payment — approved by our team</p>
        <div class="plan__divider"></div>
        <p class="plan__includes">${escapeHtml(plan.name)} includes:</p>
        <ul class="plan__features">${features}</ul>
      </article>`;
  }

  function render(container, plans, opts) {
    container.innerHTML = plans.length
      ? plans.map((plan) => card(plan, opts)).join('')
      : '<p class="plans-status">Plans are being updated — please check back shortly or contact us.</p>';
  }

  let pending = null;
  /** Fetches the plans once per page, however many containers ask. */
  function load() {
    pending ??= DiwanApi.getJson('/plans.php').then((data) => data.plans);
    return pending;
  }

  async function mount(container) {
    container.setAttribute('aria-busy', 'true');
    container.innerHTML = '<p class="plans-status">Loading plans…</p>';
    try {
      render(container, await load());
    } catch {
      pending = null;
      container.innerHTML = '<p class="plans-status">We couldn\'t load the plans just now. '
        + '<a class="text-accent" href="/contact.html">Contact us</a> or refresh the page.</p>';
    } finally {
      container.removeAttribute('aria-busy');
    }
  }

  document.querySelectorAll('[data-plans]').forEach(mount);

  window.DiwanPlans = { load, render, card, formatPrice };
})();
