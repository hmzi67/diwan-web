// Writes the plans from /api/plans.php into the built HTML, so crawlers and AI
// agents that never run JavaScript still see prices and features, and the cards
// take their space before js/plans.js refreshes them with live data.
//
//   node prerender-plans.mjs <dist-dir> <site-url>
//
// Fills, in every dist/**/*.html:
//   - each empty [data-plans] container, with js/plans.js's own card() markup;
//   - each <span data-plan-price="CODE"></span>, with ", PKR 10,000 / month";
//   - the `offers` and `featureList` of every SoftwareApplication in JSON-LD.
// If the API can't be reached the pages are left as they are (js/plans.js still
// renders the cards in the browser) and the build carries on with a warning.
// shortcut: prices are as of the last deploy; redeploy after a price change in admin.
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import vm from 'node:vm';

const [dist, siteUrl] = process.argv.slice(2);
const plansUrl = process.env.PLANS_URL || `${siteUrl}/api/plans.php`;

let plans;
try {
  const res = await fetch(plansUrl, { signal: AbortSignal.timeout(15000) });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  plans = (await res.json()).plans;
  if (!Array.isArray(plans) || plans.length === 0) throw new Error('no plans in response');
} catch (e) {
  console.log(`::warning::plans not prerendered (${plansUrl}: ${e.message}); pages keep client-side cards only`);
  process.exit(0);
}

// Run the browser script itself, so the static cards are exactly what it renders.
const sandbox = { window: {}, document: { querySelectorAll: () => [] } };
vm.runInNewContext(readFileSync(join(dist, 'js/plans.js'), 'utf8'), sandbox);
const { card, formatPrice } = sandbox.window.DiwanPlans;

const PERIOD = { month: ['P1M', 'MON', 'month'], year: ['P1Y', 'ANN', 'year'] };
const major = (p) => String(p.price_minor / 100);
const amounts = plans.map((p) => p.price_minor / 100);
const offers = {
  '@type': 'AggregateOffer',
  // shortcut: assumes every plan shares the first plan's currency; split per currency if that changes.
  priceCurrency: plans[0].currency,
  lowPrice: String(Math.min(...amounts)),
  highPrice: String(Math.max(...amounts)),
  offerCount: String(plans.length),
  url: `${siteUrl}/pricing.html`,
  offers: plans.map((p) => {
    const [billingDuration, unitCode] = PERIOD[p.billing_period] || [];
    return {
      '@type': 'Offer',
      name: p.name,
      price: major(p),
      priceCurrency: p.currency,
      url: `${siteUrl}/pricing.html`,
      ...(billingDuration && {
        priceSpecification: {
          '@type': 'UnitPriceSpecification',
          price: major(p),
          priceCurrency: p.currency,
          billingDuration,
          unitCode,
        },
      }),
    };
  }),
};
const featureList = [...new Set(plans.flatMap((p) => p.features.map((f) => f.label)))];

function addOffers(node) {
  if (Array.isArray(node)) return node.forEach(addOffers);
  if (!node || typeof node !== 'object') return;
  if (node['@type'] === 'SoftwareApplication') Object.assign(node, { offers, featureList });
  Object.values(node).forEach(addOffers);
}

const priceText = Object.fromEntries(plans.map((p) =>
  [p.code, `, ${formatPrice(p.price_minor, p.currency)} / ${(PERIOD[p.billing_period] || [])[2] || p.billing_period}`]));

function htmlFiles(dir) {
  return readdirSync(dir, { withFileTypes: true }).flatMap((d) =>
    d.isDirectory() ? htmlFiles(join(dir, d.name)) : d.name.endsWith('.html') ? [join(dir, d.name)] : []);
}

let pages = 0;
for (const file of htmlFiles(dist)) {
  const before = readFileSync(file, 'utf8');
  const after = before
    .replace(/(<div\b[^>]*\bdata-plans\b[^>]*>)\s*(<\/div>)/g, (_, open, close) =>
      open + plans.map((p) => card(p)).join('') + close)
    .replace(/<span data-plan-price="([\w-]+)"><\/span>/g, (m, code) => priceText[code] ?? m)
    .replace(/(<script type="application\/ld\+json">)([\s\S]*?)(<\/script>)/g, (m, open, json, close) => {
      if (!json.includes('SoftwareApplication')) return m;
      const data = JSON.parse(json);
      addOffers(data);
      // \u003c keeps an admin-entered "</script>" from closing the tag.
      return `${open}\n${JSON.stringify(data, null, 2).replace(/</g, '\\u003c')}\n${close}`;
    });
  if (after !== before) { writeFileSync(file, after); pages++; }
}
console.log(`    prerendered ${plans.length} plans into ${pages} pages`);
