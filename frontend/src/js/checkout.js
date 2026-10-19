// Checkout page: requires a session (checkout.php enforces this too — this
// is just so a logged-out visitor gets a redirect instead of a 401).
const DEFAULT_PLAN = 'diwan-pos-standard';

const rupees = (paisa) => `Rs ${(paisa / 100).toLocaleString('en-PK')}`;

const params = new URLSearchParams(window.location.search);
const requestedPlan = params.get('plan') || DEFAULT_PLAN;
// Settled once the product list arrives: an unrecognised plan falls back to
// the default. checkout.php re-checks the SKU and reads the price itself, so
// nothing shown here is trusted for the charge.
let plan = requestedPlan;

const title   = document.getElementById('checkout-title');
const summary = document.getElementById('checkout-summary');
const form    = document.getElementById('checkout-form');
const emailEl = document.getElementById('checkout-email');
const status  = document.getElementById('checkout-status');

(async () => {
  const [account, catalogue] = await Promise.all([
    DiwanApi.get('/dashboard-data.php'),
    DiwanApi.get('/products.php'),
  ]);
  if (!account.ok) {
    window.location.href = `/auth/login.html?plan=${encodeURIComponent(requestedPlan)}`;
    return;
  }
  const products = catalogue.ok ? catalogue.data.products : [];
  const product = products.find((p) => p.sku === requestedPlan)
    || products.find((p) => p.sku === DEFAULT_PLAN);
  if (!product) {
    DiwanApi.setStatus(status, 'Checkout is unavailable right now. Please try again shortly.', 'error');
    return;
  }
  plan = product.sku;
  title.textContent = `Checkout — ${product.name}`;
  summary.textContent = `${product.name}, ${rupees(product.price_paisa)} one-time.`;
  emailEl.textContent = account.data.email;
  form.hidden = false;
})();

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const button = form.querySelector('button');
  button.disabled = true;
  DiwanApi.setStatus(status, 'Creating your order…');
  try {
    const phone = new FormData(form).get('phone');
    const { redirect_url, fields } = await DiwanApi.postJson('/checkout.php', {
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
    DiwanApi.setStatus(status, err.message, 'error');
    button.disabled = false;
  }
});
