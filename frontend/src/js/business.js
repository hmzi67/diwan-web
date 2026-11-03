// The owner's back office inside the customer dashboard: catalog, products,
// suppliers, purchases, sales and reports of the business workspace, read and
// edited through /api/business.php. A change saved here reaches every counter
// through the normal sync (docs/MULTI-COUNTER.md §13).
//
// Loaded after dashboard.js: it adds its views to that page's router and uses
// its escapeHtml() and toast().
(() => {
  const SVG = (path) => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
  const ICON = {
    home: '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/>',
    catalog: '<path d="M4 5a2 2 0 0 1 2-2h5v16H6a2 2 0 0 0-2 2z"/><path d="M20 5a2 2 0 0 0-2-2h-5v16h5a2 2 0 0 1 2 2z"/>',
    person: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
    orders: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
    box: '<path d="M3 8l9-5 9 5v8l-9 5-9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
    tool: '<path d="M14.5 6.5a4 4 0 0 0-5.3 5.3L4 17l3 3 5.2-5.2a4 4 0 0 0 5.3-5.3L15 12l-3-3z"/>',
    people: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.7-3.5 3.2-5.5 6.5-5.5s5.8 2 6.5 5.5"/><path d="M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c2 .7 3.2 2.5 3.6 5.2"/>',
    bank: '<path d="M3 9l9-5 9 5z"/><path d="M5 9v8M10 9v8M14 9v8M19 9v8M3 20h18"/>',
    cart: '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 4h2l2.4 11h11l2-8H6"/>',
    trend: '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    table: '<path d="M3 8h18M5 8v12M19 8v12M8 8V4h8v4"/>',
    cash: '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
    report: '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
    trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5M14 11v5"/>',
  };

  // --- Small helpers --------------------------------------------------------
  const esc = (value) => escapeHtml(value == null ? '' : String(value));
  const API = '/business.php';
  const localDate = (date = new Date()) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  };
  const daysAgo = (n) => {
    const date = new Date();
    date.setDate(date.getDate() - n);
    return localDate(date);
  };
  const number = (value) => Number(value || 0).toLocaleString('en-PK', { maximumFractionDigits: 2 });
  const money = (minor) => {
    const amount = number(Math.abs(Number(minor || 0)) / 100);
    const currency = state.context?.currency || 'PKR';
    return `${Number(minor) < 0 ? '-' : ''}${currency === 'PKR' ? 'Rs' : currency} ${amount}`;
  };
  const toMinor = (value) => Math.round(Number(value) * 100);
  const requestId = () => (crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join(''));
  const humanize = (value) => String(value ?? '').replaceAll('_', ' ').replace(/^\w/, (c) => c.toUpperCase());

  const state = {
    context: null,
    records: {},
    version: null,
    tables: {},     // view key -> { search, size, page, sort, dir, filters }
    report: 'profit_loss',
    chart: 'daily',
    dates: {},      // view key -> { from, to }
  };

  const query = (params) => {
    const all = state.context?.business ? { business: state.context.business, ...params } : params;
    return `${API}?${new URLSearchParams(all)}`;
  };
  const post = (body) => DiwanApi.postJson(API, state.context?.business ? { business: state.context.business, ...body } : body);
  const nameOf = (entity, gid) => (state.records[entity] || []).find((row) => row.gid === gid)?.name ?? '';
  const active = (row) => row.is_active === undefined || row.is_active === null || Number(row.is_active) === 1;
  const options = (entity, filter = () => true) => () => (state.records[entity] || [])
    .filter((row) => active(row) && filter(row))
    .map((row) => [row.gid, row.name]);

  // --- Column helpers -------------------------------------------------------
  const text = (label, value) => ({ label, value });
  const moneyCol = (label, value) => ({ label, value: (row) => Number(value(row) || 0), text: (v) => money(v), right: true });
  const numberCol = (label, value) => ({ label, value: (row) => Number(value(row) || 0), text: (v) => number(v), right: true });
  const statusCol = {
    label: 'Status',
    value: (row) => (active(row) ? 'Active' : 'Inactive'),
    html: (v) => `<span class="biz-status biz-status--${v === 'Active' ? 'ok' : 'bad'}">${v}</span>`,
  };
  const tagCol = (label, value, good = [], bad = []) => ({
    label,
    value,
    html: (v) => (v ? `<span class="biz-status biz-status--${good.includes(v) ? 'ok' : bad.includes(v) ? 'bad' : 'neutral'}">${esc(humanize(v))}</span>` : '—'),
  });

  const NAME = { name: 'name', label: 'Name', required: true };
  const STATUS = { name: 'is_active', label: 'Status', type: 'status' };
  const textFilter = (key, label, value) => ({ key, label, type: 'text', test: (row, v) => String(value(row) ?? '').toLowerCase().includes(v.toLowerCase()) });
  const statusFilter = { key: 'status', label: 'Status', type: 'select', choices: [['1', 'Active'], ['0', 'Inactive']], test: (row, v) => active(row) === (v === '1') };
  const selectFilter = (key, label, choices, value) => ({ key, label, type: 'select', choices, test: (row, v) => value(row) === v });
  const dateFilter = { type: 'dates' };

  const simple = (entity, title, singular, more = {}) => ({
    title, singular, entity, load: [entity, ...(more.load || [])],
    columns: more.columns || [text('Name', (r) => r.name), statusCol],
    fields: more.fields || [NAME, STATUS],
    filters: more.filters || [textFilter('name', singular, (r) => r.name), statusFilter],
    ...more.extra,
  });

  const itemFields = (service) => [
    { ...NAME },
    { name: 'price_minor', label: service ? 'Charge' : 'Sale price', type: 'money', required: true },
    ...(service ? [] : [
      { name: 'cost_minor', label: 'Cost price', type: 'money' },
      { name: 'barcode', label: 'Barcode' },
      { name: 'low_stock_threshold', label: 'Low-stock alert at', type: 'number' },
      { name: 'opening_stock', label: 'Opening stock', type: 'qty', newOnly: true, hint: 'Later changes go through “Stock” in the list.' },
      { name: 'unit', label: 'Unit (kg, ltr…)', hint: 'Leave blank for items sold by the piece.' },
      { name: 'sold_by_amount', label: 'Sold by typing an amount (loose goods)', type: 'check' },
    ]),
    { name: 'short_code', label: 'Quick code (2-4 digits)' },
    { name: 'category_id', label: 'Category', type: 'select', options: options('categories') },
    { name: 'sub_category_id', label: 'Sub category', type: 'select', options: options('sub_categories') },
    ...(service ? [] : [
      { name: 'manufacturer_id', label: 'Manufacturer', type: 'select', options: options('manufacturers') },
      { name: 'location_id', label: 'Location', type: 'select', options: options('locations') },
    ]),
    { name: 'counter_id', label: 'Kitchen counter', type: 'select', options: options('counters') },
    { name: 'description', label: 'Description', type: 'textarea', wide: true },
    { ...STATUS },
  ];
  const ITEM_LOAD = ['categories', 'sub_categories', 'manufacturers', 'locations', 'counters'];

  // --- Screens: each is one sidebar link ------------------------------------
  const LISTS = {
    'b-departments': simple('departments', 'Departments', 'Department'),
    'b-categories': simple('categories', 'Categories', 'Category', {
      load: ['departments'],
      columns: [text('Name', (r) => r.name), text('Department', (r) => nameOf('departments', r.department_id)), statusCol],
      fields: [NAME, { name: 'department_id', label: 'Department', type: 'select', options: options('departments') },
        { name: 'is_topping', label: 'Topping group (offered as add-ons, off the billing grid)', type: 'check' },
        { ...STATUS, hint: 'An inactive category and its items leave the billing screen on every counter.' }],
      filters: [textFilter('name', 'Category', (r) => r.name), textFilter('department', 'Department', (r) => nameOf('departments', r.department_id)), statusFilter],
    }),
    'b-sub-categories': simple('sub_categories', 'Sub categories', 'Sub category', {
      load: ['categories'],
      columns: [text('Name', (r) => r.name), text('Category', (r) => nameOf('categories', r.category_id)), statusCol],
      fields: [NAME, { name: 'category_id', label: 'Category', type: 'select', options: options('categories'), required: true }, STATUS],
      filters: [textFilter('name', 'Sub category', (r) => r.name), textFilter('category', 'Category', (r) => nameOf('categories', r.category_id)), statusFilter],
    }),
    'b-manufacturers': simple('manufacturers', 'Manufacturers', 'Manufacturer'),
    'b-locations': simple('locations', 'Locations', 'Location', {
      columns: [text('Location name', (r) => r.name), statusCol, text('Notes', (r) => r.notes)],
      fields: [NAME, { name: 'notes', label: 'Notes', type: 'textarea' }, STATUS],
    }),
    'b-suppliers': simple('suppliers', 'Suppliers', 'Supplier', {
      columns: [
        text('Name', (r) => r.name), text('Contact person', (r) => r.contact_person), text('Phone', (r) => r.phone), text('Email', (r) => r.email),
        numberCol('Total invoices', (r) => r.invoices), moneyCol('Paid amount', (r) => r.paid_minor), moneyCol('Total balance', (r) => r.balance_minor), statusCol,
      ],
      fields: [NAME, { name: 'contact_person', label: 'Contact person' }, { name: 'phone', label: 'Phone' }, { name: 'email', label: 'Email', type: 'email' },
        { name: 'address', label: 'Address', type: 'textarea', wide: true }, { name: 'notes', label: 'Notes', type: 'textarea', wide: true }, STATUS],
      filters: [{ ...textFilter('name', 'Search', (r) => `${r.name} ${r.contact_person || ''} ${r.phone || ''}`) }, statusFilter],
      extra: { importable: ['name', 'contact_person', 'phone', 'email', 'address', 'notes'] },
    }),
    'b-products': {
      title: 'Products', singular: 'Product', entity: 'items', load: ['items', ...ITEM_LOAD],
      only: (r) => !Number(r.is_service),
      columns: [
        text('Name', (r) => r.name), text('Barcode', (r) => r.barcode),
        text('Category', (r) => nameOf('categories', r.category_id)), text('Manufacturer', (r) => nameOf('manufacturers', r.manufacturer_id)),
        moneyCol('Price', (r) => r.price_minor), moneyCol('Cost', (r) => r.cost_minor),
        {
          label: 'Stock', right: true, value: (r) => Number(r.stock_qty || 0), text: (v) => number(v),
          html: (v, r) => `<span class="${v <= Number(r.low_stock_threshold || 0) ? 'biz-low' : ''}">${number(v)}${r.unit ? ` ${esc(r.unit)}` : ''}</span>`,
        },
        statusCol,
      ],
      fields: itemFields(false),
      filters: [textFilter('name', 'Product', (r) => `${r.name} ${r.barcode || ''} ${r.short_code || ''}`),
        { key: 'category', label: 'Category', type: 'select', choices: options('categories'), test: (r, v) => r.category_id === v }, statusFilter],
      rowActions: [{ label: 'Stock', run: (row) => stockDialog(row) }],
    },
    'b-services': {
      title: 'Services', singular: 'Service', entity: 'items', load: ['items', ...ITEM_LOAD],
      only: (r) => Number(r.is_service) === 1,
      newDefaults: { is_service: 1 },
      columns: [text('Name', (r) => r.name), text('Category', (r) => nameOf('categories', r.category_id)), moneyCol('Charge', (r) => r.price_minor), statusCol],
      fields: itemFields(true),
      filters: [textFilter('name', 'Service', (r) => r.name), statusFilter],
      note: 'Services are sold on the POS like any item, but no stock is counted or checked.',
    },
    'b-customers': simple('customers', 'Customers', 'Customer', {
      columns: [text('Name', (r) => r.name), text('Phone', (r) => r.phone), text('Email', (r) => r.email), moneyCol('Balance owed', (r) => r.balance_minor), statusCol],
      fields: [NAME, { name: 'phone', label: 'Phone' }, { name: 'email', label: 'Email', type: 'email' }, { name: 'address', label: 'Address', type: 'textarea', wide: true }, { name: 'notes', label: 'Notes', type: 'textarea', wide: true }, STATUS],
      filters: [textFilter('name', 'Search', (r) => `${r.name} ${r.phone || ''} ${r.email || ''}`), statusFilter],
    }),
    'b-banks': simple('bank_accounts', 'Bank accounts', 'Bank account', {
      columns: [text('Bank', (r) => r.name), text('Account title', (r) => r.account_title), text('Account number', (r) => r.account_number), text('Branch', (r) => r.branch), statusCol],
      fields: [{ ...NAME, label: 'Bank name' }, { name: 'account_title', label: 'Account title' }, { name: 'account_number', label: 'Account number' }, { name: 'branch', label: 'Branch' }, STATUS],
      filters: [textFilter('name', 'Bank', (r) => `${r.name} ${r.account_title || ''} ${r.account_number || ''}`), statusFilter],
    }),
    'b-tables': simple('tables', 'Table management', 'Table', {
      columns: [text('Name', (r) => r.name), numberCol('Seats', (r) => r.seats), tagCol('Right now', (r) => r.status || 'free', ['free'], ['occupied'])],
      fields: [NAME, { name: 'seats', label: 'Seats', type: 'number', required: true, value: 4 }],
      filters: [textFilter('name', 'Table', (r) => r.name)],
    }),
    'b-payouts': {
      title: 'Payouts', singular: 'Payout', entity: 'expenses', load: ['expenses'],
      sort: 0, dir: -1,
      columns: [text('Date', (r) => r.expense_date), text('Category', (r) => r.category), moneyCol('Amount', (r) => r.amount_minor), text('Note', (r) => r.note)],
      fields: [{ name: 'expense_date', label: 'Date', type: 'date', required: true, value: () => localDate() }, { name: 'category', label: 'Category', required: true, placeholder: 'Rent, wages, gas…' },
        { name: 'amount_minor', label: 'Amount', type: 'money', required: true }, { name: 'note', label: 'Note', type: 'textarea', wide: true }],
      filters: [textFilter('category', 'Category', (r) => r.category), { ...dateFilter, field: 'expense_date' }],
      footer: (rows) => `Total ${money(rows.reduce((sum, r) => sum + Number(r.amount_minor || 0), 0))}`,
      note: 'The same records as Expenses on the POS, and they count in Profit and Loss.',
    },
    'b-kitchen': simple('counters', 'Kitchen counters', 'Kitchen counter', { filters: [textFilter('name', 'Counter', (r) => r.name), statusFilter] }),
    'b-platforms': simple('delivery_platforms', 'Delivery platforms', 'Platform', { filters: [textFilter('name', 'Platform', (r) => r.name), statusFilter] }),
    'b-purchase-orders': {
      title: 'Purchase orders', singular: 'Purchase order', load: ['purchase_orders', 'purchase_order_items', 'suppliers', 'items'],
      readOnly: true, sort: 0, dir: -1,
      newAction: () => purchaseDialog('po'),
      columns: [
        text('Order date', (r) => r.order_date), text('Supplier', (r) => nameOf('suppliers', r.supplier_id)), text('Expected', (r) => r.expected_date),
        moneyCol('Total', (r) => poLines(r).reduce((sum, l) => sum + Number(l.qty_ordered) * Number(l.unit_cost_minor), 0)),
        { label: 'Received', right: true, value: (r) => { const lines = poLines(r); const want = lines.reduce((s, l) => s + Number(l.qty_ordered), 0); return want ? Math.round(lines.reduce((s, l) => s + Number(l.qty_received), 0) / want * 100) : 0; }, text: (v) => `${v}%` },
        tagCol('Status', (r) => r.status, ['received', 'ordered'], ['cancelled']),
      ],
      filters: [textFilter('supplier', 'Supplier', (r) => nameOf('suppliers', r.supplier_id)),
        selectFilter('status', 'Status', [['draft', 'Draft'], ['ordered', 'Ordered'], ['partial', 'Partly received'], ['received', 'Received'], ['cancelled', 'Cancelled']], (r) => r.status), { ...dateFilter, field: 'order_date' }],
      rowActions: [
        { label: 'Place', when: (r) => r.status === 'draft', run: (r) => setPoStatus(r, 'ordered') },
        { label: 'Receive', when: (r) => ['ordered', 'partial'].includes(r.status), run: (r) => purchaseDialog('grn', r) },
        { label: 'Cancel', when: (r) => ['draft', 'ordered', 'partial'].includes(r.status), run: (r) => confirmDialog(`Cancel this order from ${nameOf('suppliers', r.supplier_id)}?`, 'Cancel order', () => setPoStatus(r, 'cancelled')) },
      ],
    },
    'b-goods': {
      title: 'Goods received', singular: 'Goods received note', load: ['goods_receipts', 'goods_receipt_items', 'purchase_orders', 'purchase_order_items', 'suppliers', 'items'],
      readOnly: true, sort: 0, dir: -1,
      newAction: () => purchaseDialog('grn'),
      columns: [text('Received', (r) => r.received_date), text('Supplier', (r) => nameOf('suppliers', r.supplier_id)), text('Invoice', (r) => r.invoice_ref),
        numberCol('Lines', (r) => (state.records.goods_receipt_items || []).filter((l) => l.grn_id === r.gid).length), moneyCol('Total', (r) => r.total_cost_minor)],
      filters: [textFilter('supplier', 'Supplier', (r) => nameOf('suppliers', r.supplier_id)), { ...dateFilter, field: 'received_date' }],
      footer: (rows) => `Total ${money(rows.reduce((sum, r) => sum + Number(r.total_cost_minor || 0), 0))}`,
      note: 'Receiving adds to stock on every counter and updates each product’s cost, like receiving on the POS.',
    },
    'b-supplier-payments': {
      title: 'Supplier payments', singular: 'Payment', load: ['supplier_payments', 'suppliers', 'bank_accounts'],
      readOnly: true, sort: 0, dir: -1,
      newAction: () => paymentDialog(),
      columns: [text('Date', (r) => r.paid_date), text('Supplier', (r) => nameOf('suppliers', r.supplier_id)), moneyCol('Amount', (r) => r.amount_minor),
        tagCol('Method', (r) => r.method), text('Bank account', (r) => nameOf('bank_accounts', r.bank_account_id)), text('Reference', (r) => r.reference)],
      filters: [textFilter('supplier', 'Supplier', (r) => nameOf('suppliers', r.supplier_id)), { ...dateFilter, field: 'paid_date' }],
      footer: (rows) => `Total ${money(rows.reduce((sum, r) => sum + Number(r.amount_minor || 0), 0))}`,
    },
    'b-refunds': {
      title: 'Refunds', singular: 'Refund', load: ['refunds'], readOnly: true, noNew: true, sort: 0, dir: -1,
      columns: [text('Date', (r) => r.created_at), moneyCol('Amount', (r) => r.total_refund_amount_minor), text('Reason', (r) => r.reason)],
      filters: [{ ...dateFilter, field: 'created_at' }],
      footer: (rows) => `Total ${money(rows.reduce((sum, r) => sum + Number(r.total_refund_amount_minor || 0), 0))}`,
      note: 'Refunds are recorded on the POS.',
    },
  };
  const poLines = (po) => (state.records.purchase_order_items || []).filter((line) => line.po_id === po.gid);

  const PAGES = {
    'b-dashboard': { title: 'Dashboard', render: renderDashboard },
    'b-orders': { title: 'In-progress orders', render: renderOrders },
    'b-sales': { title: 'Sales', render: renderSales },
    'b-settings': { title: 'Business settings', render: renderSettings },
    'b-reports': { title: 'Reports', render: renderReports },
  };

  const link = (key, label, icon) => ({ key, label, icon });
  const NAV = [
    link('b-dashboard', 'Dashboard', ICON.home),
    { label: 'Catalog', icon: ICON.catalog, children: [link('b-departments', 'Department'), link('b-categories', 'Category'), link('b-sub-categories', 'Sub category'), link('b-manufacturers', 'Manufacturer'), link('b-locations', 'Location')] },
    link('b-suppliers', 'Supplier', ICON.person),
    link('b-orders', 'In-progress orders', ICON.orders),
    link('b-products', 'Product', ICON.box),
    link('b-services', 'Services', ICON.tool),
    link('b-customers', 'Customer', ICON.people),
    link('b-banks', 'Bank', ICON.bank),
    { label: 'Purchases', icon: ICON.cart, children: [link('b-purchase-orders', 'Purchase orders'), link('b-goods', 'Goods received'), link('b-supplier-payments', 'Supplier payments')] },
    { label: 'Sales', icon: ICON.trend, children: [link('b-sales', 'Sales'), link('b-refunds', 'Refunds')] },
    link('b-tables', 'Table management', ICON.table),
    link('b-payouts', 'Payout', ICON.cash),
    { label: 'Settings', icon: ICON.gear, children: [link('b-settings', 'Business settings'), link('b-kitchen', 'Kitchen counters'), link('b-platforms', 'Delivery platforms')] },
    link('b-reports', 'Reports', ICON.report),
  ];
  const ALL_VIEWS = { ...LISTS, ...PAGES };

  // --- Wiring into the dashboard page ---------------------------------------
  const anchor = (item) => `<a href="#${item.key}" data-nav="${item.key}">${item.icon ? SVG(item.icon) : ''}${esc(item.label)}</a>`;

  function mount() {
    Object.entries(ALL_VIEWS).forEach(([key, view]) => { VIEWS[key] = view.title; });

    const nav = document.querySelector('.sidebar__nav');
    nav.insertAdjacentHTML('afterbegin', `<p class="sidebar__label">Business</p>${NAV.map((item) => (item.children
      ? `<details class="sidebar__group"><summary>${SVG(item.icon)}${esc(item.label)}</summary>${item.children.map(anchor).join('')}</details>`
      : anchor(item))).join('')}`);
    const flat = NAV.flatMap((item) => item.children || [item]);
    document.querySelector('.tabbar').insertAdjacentHTML('afterbegin', flat.map((item) => `<a href="#${item.key}" data-nav="${item.key}">${esc(item.label)}</a>`).join(''));

    document.getElementById('content').insertAdjacentHTML('beforeend', Object.keys(ALL_VIEWS)
      .map((key) => `<section class="view biz" data-view="${key}" hidden></section>`).join(''));
    document.body.insertAdjacentHTML('beforeend', '<dialog class="admin-dialog biz-dialog" id="biz-dialog" aria-labelledby="biz-dialog-title"></dialog>');

    window.addEventListener('hashchange', route);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshIfChanged(); });
    setInterval(() => { if (!document.hidden) refreshIfChanged(); }, 30000);
    route();
  }

  const currentKey = () => {
    const name = window.location.hash.replace(/^#\/?/, '');
    return Object.hasOwn(ALL_VIEWS, name) ? name : null;
  };
  const sectionOf = (key) => document.querySelector(`[data-view="${key}"]`);

  let contextLoad = null;
  const loadContext = () => {
    contextLoad ||= DiwanApi.getJson(`${API}?view=context`).then((context) => {
      state.context = context;
      state.version = context.version ?? null;
      return context;
    }).catch((error) => { contextLoad = null; throw error; });
    return contextLoad;
  };

  async function route() {
    const key = currentKey();
    try {
      await loadContext();
    } catch {
      if (key) renderError(key, 'We could not reach your business data.');
      return;
    }
    if (!key) {
      // A business owner lands on the business dashboard, as long as no other view was asked for.
      if (!window.location.hash && state.context.businesses.length) window.location.hash = '#b-dashboard';
      return;
    }
    document.querySelector(`.sidebar__group a[data-nav="${key}"]`)?.closest('details')?.setAttribute('open', '');
    if (!state.context.businesses.length) {
      renderNoBusiness(key);
      return;
    }
    // Rebuilt on arrival so filter dropdowns list what other screens just added.
    delete sectionOf(key).dataset.rendered;
    await show(key, { focus: true });
  }

  async function show(key, { focus = false } = {}) {
    const section = sectionOf(key);
    if (!section.dataset.rendered) section.innerHTML = '<div class="dash-skeleton"><div class="sk sk--title"></div><div class="sk sk--panel"></div></div>';
    try {
      if (LISTS[key]) await renderList(key);
      else await PAGES[key].render(key, section);
      section.dataset.rendered = '1';
      if (focus) section.querySelector('h1')?.focus({ preventScroll: true });
    } catch (error) {
      if (error.status === 401) {
        window.location.href = '/auth/login.html';
        return;
      }
      renderError(key, error.message);
    }
  }

  async function refreshIfChanged() {
    const key = currentKey();
    // Settings is a form someone may be typing in; it reloads when they come back to it.
    if (!key || key === 'b-settings' || !state.context?.business || document.getElementById('biz-dialog').open) return;
    try {
      const { version } = await DiwanApi.getJson(query({ view: 'head' }));
      if (version !== state.version) {
        state.version = version;
        await show(key);
      }
    } catch {
      // Only a background refresh: the next one tries again.
    }
  }

  const reload = (key) => show(key);

  function head(key, title, { action = '', sub = '' } = {}) {
    const businesses = state.context?.businesses || [];
    const picker = businesses.length > 1
      ? `<label class="field biz-picker">Business<select class="input" data-business>${businesses.map((b) => `<option value="${b.id}" ${b.id === state.context.business ? 'selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label>`
      : '';
    return `<header class="view-head">
      <div><h1 tabindex="-1">${esc(title)}</h1>${sub ? `<p>${esc(sub)}</p>` : ''}</div>
      <div class="view-head__actions">${picker}${action}</div>
    </header>`;
  }

  function renderError(key, message) {
    sectionOf(key).innerHTML = `${head(key, ALL_VIEWS[key].title)}<div class="dash-empty"><p>${esc(message)}</p><button class="btn btn--ghost btn--sm" type="button" data-retry>Try again</button></div>`;
    delete sectionOf(key).dataset.rendered;
  }

  function renderNoBusiness(key) {
    sectionOf(key).innerHTML = `${head(key, ALL_VIEWS[key].title)}
      <div class="dash-empty">
        <div class="dash-empty__icon" aria-hidden="true">${SVG(ICON.box)}</div>
        <h3>Connect your first counter</h3>
        <p>Your products, sales and reports appear here once a counter is connected. In the Diwan POS open Settings → Licence &amp; Sync → Business &amp; counters, then press Connect this counter.</p>
        <div class="dash-empty__cta"><a class="btn btn--primary btn--sm" href="#setup">Open setup guide</a><a class="btn btn--ghost btn--sm" href="#licences">View licence keys</a></div>
      </div>`;
  }

  // --- Lists ----------------------------------------------------------------
  const tableState = (key) => {
    const view = ALL_VIEWS[key];
    state.tables[key] ||= { search: '', size: 25, page: 1, sort: view?.sort ?? null, dir: view?.dir ?? 1, filters: {} };
    return state.tables[key];
  };

  async function loadRecords(entities) {
    const data = await DiwanApi.getJson(query({ view: 'records', entities: [...new Set(entities)].join(',') }));
    state.version = data.version;
    Object.assign(state.records, data.records);
  }

  async function renderList(key) {
    const view = LISTS[key];
    await loadRecords(view.load);
    const section = sectionOf(key);
    const newButton = view.noNew ? '' : `<button class="btn btn--primary btn--sm" type="button" data-new>+ New ${esc(view.singular.toLowerCase())}</button>`;
    const importButton = view.importable ? `<label class="btn btn--ghost btn--sm biz-import">Import ${esc(view.singular.toLowerCase())}s<input type="file" accept=".csv,text/csv" data-import hidden></label>` : '';
    if (!section.dataset.rendered) {
      section.innerHTML = `${head(key, view.title, { action: `${newButton}${importButton}`, sub: view.note })}
        ${filterPanel(key, view)}
        <div class="panel biz-card" data-table-host></div>`;
    }
    drawTable(key);
  }

  function filterPanel(key, view) {
    const values = tableState(key).filters;
    const controls = view.filters.map((filter) => {
      if (filter.type === 'dates') {
        return `<label class="field">From<input class="input" type="date" data-filter="from" value="${esc(values.from || '')}"></label>
                <label class="field">To<input class="input" type="date" data-filter="to" value="${esc(values.to || '')}"></label>`;
      }
      if (filter.type === 'select') {
        const choices = typeof filter.choices === 'function' ? filter.choices() : filter.choices;
        return `<label class="field">${esc(filter.label)}<select class="input" data-filter="${filter.key}"><option value="">All</option>${choices.map(([value, label]) => `<option value="${esc(value)}" ${values[filter.key] === value ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label>`;
      }
      return `<label class="field">${esc(filter.label)}<input class="input" type="search" placeholder="Enter ${esc(filter.label.toLowerCase())}" data-filter="${filter.key}" value="${esc(values[filter.key] || '')}"></label>`;
    }).join('');
    return `<form class="panel biz-card biz-filters" data-filter-form>
      <h2>Search &amp; filter</h2>
      <div class="biz-filters__grid">${controls}</div>
      <div class="biz-filters__actions"><button class="btn btn--primary btn--sm" type="submit">Filter</button><button class="btn btn--ghost btn--sm" type="button" data-filter-reset>Reset</button></div>
    </form>`;
  }

  function filteredRows(key) {
    const view = LISTS[key];
    const { filters } = tableState(key);
    let rows = view.rows ? view.rows() : (state.records[view.load[0]] || []);
    if (view.only) rows = rows.filter(view.only);
    view.filters.forEach((filter) => {
      if (filter.type === 'dates') {
        rows = rows.filter((row) => {
          const day = String(row[filter.field] ?? '').slice(0, 10);
          return (!filters.from || day >= filters.from) && (!filters.to || day <= filters.to);
        });
      } else if (filters[filter.key]) {
        rows = rows.filter((row) => filter.test(row, filters[filter.key]));
      }
    });
    return rows;
  }

  function drawTable(key) {
    const view = LISTS[key];
    const actions = (row) => {
      const extra = (view.rowActions || []).filter((a) => !a.when || a.when(row)).map((a) => `<button class="btn btn--ghost btn--sm" type="button" data-row-action="${esc(a.label)}" data-gid="${esc(row.gid)}">${esc(a.label)}</button>`).join('');
      const own = view.readOnly ? '' : `<button class="icon-btn biz-edit" type="button" data-edit="${esc(row.gid)}" aria-label="Edit ${esc(row.name || view.singular)}" title="Edit">${SVG(ICON.edit)}</button>
        <button class="icon-btn" type="button" data-delete="${esc(row.gid)}" aria-label="Delete ${esc(row.name || view.singular)}" title="Delete">${SVG(ICON.trash)}</button>`;
      return `<div class="biz-actions">${extra}${own}</div>`;
    };
    const hasActions = !view.readOnly || (view.rowActions || []).length > 0;
    table(sectionOf(key).querySelector('[data-table-host]'), key, view.columns, filteredRows(key), {
      plural: view.title.toLowerCase(),
      footer: view.footer,
      actions: hasActions ? actions : null,
    });
  }

  // The table every screen shares: search, sort, paging and exports.
  function table(host, key, columns, allRows, { plural = 'entries', footer, actions } = {}) {
    const t = tableState(key);
    const textOf = (column, row) => {
      const value = column.value(row);
      if (value === null || value === undefined || value === '') return '';
      return column.text ? column.text(value) : String(value);
    };
    const searched = t.search
      ? allRows.filter((row) => columns.some((column) => textOf(column, row).toLowerCase().includes(t.search.toLowerCase())))
      : allRows;
    let rows = searched;
    if (t.sort !== null && columns[t.sort]) {
      const column = columns[t.sort];
      rows = [...searched].sort((a, b) => {
        const [x, y] = [column.value(a) ?? '', column.value(b) ?? ''];
        return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true })) * t.dir;
      });
    }
    const size = t.size === 'all' ? Math.max(rows.length, 1) : t.size;
    const pages = Math.max(1, Math.ceil(rows.length / size));
    t.page = Math.min(t.page, pages);
    const shown = rows.slice((t.page - 1) * size, t.page * size);
    host.exportRows = { columns, rows, textOf };

    const heads = columns.map((column, i) => {
      const sorted = t.sort === i;
      return `<th class="${column.right ? 'biz-right' : ''}" aria-sort="${sorted ? (t.dir > 0 ? 'ascending' : 'descending') : 'none'}"><button type="button" class="biz-sort" data-sort="${i}">${esc(column.label)}<span aria-hidden="true">${sorted ? (t.dir > 0 ? ' ▲' : ' ▼') : ''}</span></button></th>`;
    }).join('');
    const body = shown.map((row) => `<tr>${columns.map((column) => {
      const value = column.value(row);
      const printable = textOf(column, row);
      const html = column.html ? column.html(value, row) : (printable === '' ? '—' : esc(printable));
      return `<td class="${column.right ? 'biz-right' : ''}">${html}</td>`;
    }).join('')}${actions ? `<td class="biz-noprint">${actions(row)}</td>` : ''}</tr>`).join('');

    const first = rows.length ? (t.page - 1) * size + 1 : 0;
    const last = Math.min(t.page * size, rows.length);
    const keepFocus = host.contains(document.activeElement) && document.activeElement.matches('[data-search]');
    host.innerHTML = `
      <div class="biz-toolbar biz-noprint">
        <label class="biz-size">Show <select class="input input--sm" data-size>${[10, 25, 50, 100, 'all'].map((n) => `<option value="${n}" ${String(t.size) === String(n) ? 'selected' : ''}>${n === 'all' ? 'All' : n}</option>`).join('')}</select> entries</label>
        <div class="biz-exports" role="group" aria-label="Export this list">
          <button type="button" data-export="copy">Copy</button><button type="button" data-export="csv">CSV</button><button type="button" data-export="excel">Excel</button><button type="button" data-export="pdf">PDF</button><button type="button" data-export="print">Print</button>
        </div>
        <input class="input input--sm biz-search" type="search" placeholder="Search ${esc(plural)}…" aria-label="Search ${esc(plural)}" data-search value="${esc(t.search)}">
      </div>
      <div class="table-wrap"><table class="data-table biz-table"><thead><tr>${heads}${actions ? '<th class="biz-noprint">Actions</th>' : ''}</tr></thead>
        <tbody>${body || `<tr><td colspan="${columns.length + (actions ? 1 : 0)}" class="biz-empty">No data available in table</td></tr>`}</tbody></table></div>
      <div class="biz-pager">
        <span>Showing ${first} to ${last} of ${rows.length} entries${footer && rows.length ? ` · <strong>${esc(footer(rows))}</strong>` : ''}</span>
        <nav class="biz-pages biz-noprint" aria-label="Pages">
          <button type="button" data-page="${t.page - 1}" ${t.page <= 1 ? 'disabled' : ''}>Previous</button>
          <span aria-current="page">${t.page} / ${pages}</span>
          <button type="button" data-page="${t.page + 1}" ${t.page >= pages ? 'disabled' : ''}>Next</button>
        </nav>
      </div>`;
    if (keepFocus) {
      const input = host.querySelector('[data-search]');
      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
    }
  }

  // --- Exports --------------------------------------------------------------
  function exportTable(host, kind, title) {
    const { columns, rows, textOf } = host.exportRows;
    const matrix = [columns.map((c) => c.label), ...rows.map((row) => columns.map((c) => textOf(c, row)))];
    if (kind === 'copy') {
      navigator.clipboard.writeText(matrix.map((r) => r.join('\t')).join('\n')).then(() => toast(`${rows.length} rows copied`, 'ok'), () => toast('Copy is not available here.', 'error'));
      return;
    }
    if (kind === 'pdf' || kind === 'print') {
      printAll(host, kind);
      return;
    }
    // Text from counters could start like a formula; spreadsheets must show it, not run it.
    const safe = (cell) => (/^[=+@\t\r]|^-(?!\d|[A-Z][a-zA-Z]{1,2} )/.test(cell) ? `'${cell}` : cell);
    const file = title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    if (kind === 'csv') {
      const csv = matrix.map((r) => r.map((cell) => `"${safe(cell).replaceAll('"', '""')}"`).join(',')).join('\r\n');
      download(`${file}.csv`, `﻿${csv}`, 'text/csv');
      return;
    }
    // shortcut: Excel 2003 XML saved as .xls, so Excel may warn once when opening it. Write a real .xlsx if customers object.
    const xml = (cell) => cell.replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));
    const sheet = matrix.map((r) => `<Row>${r.map((cell) => `<Cell><Data ss:Type="String">${xml(safe(cell))}</Data></Cell>`).join('')}</Row>`).join('');
    download(`${file}.xls`, `<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="${xml(title.slice(0, 30))}"><Table>${sheet}</Table></Worksheet></Workbook>`, 'application/vnd.ms-excel');
  }

  function download(name, content, type) {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.click();
    URL.revokeObjectURL(url);
  }

  // Prints every filtered row, not just this page, then puts the page back.
  function printAll(host, kind) {
    const key = host.closest('[data-view]').dataset.view;
    const t = tableState(tableKey(key));
    const size = t.size;
    t.size = 'all';
    redraw(key);
    if (kind === 'pdf') toast('Choose “Save as PDF” as the printer.');
    window.addEventListener('afterprint', () => { t.size = size; redraw(key); }, { once: true });
    window.print();
  }

  const redraw = (key) => {
    if (LISTS[key]) drawTable(key);
    else if (key === 'b-sales') drawSales();
    else if (key === 'b-orders') drawOrders();
    else if (key === 'b-reports') drawReport();
  };

  // --- Dialogs --------------------------------------------------------------
  const dialog = () => document.getElementById('biz-dialog');

  function openDialog(title, body, submitLabel, onSubmit) {
    const el = dialog();
    el.innerHTML = `<form method="dialog" novalidate>
      <h2 id="biz-dialog-title">${esc(title)}</h2>
      <div class="biz-form">${body}</div>
      <p class="admin-dialog__error" data-error role="alert"></p>
      <div class="admin-dialog__actions">
        <button class="btn btn--ghost btn--sm" type="button" data-close>Close</button>
        ${onSubmit ? `<button class="btn btn--primary btn--sm" type="submit">${esc(submitLabel)}</button>` : ''}
      </div>
    </form>`;
    const form = el.querySelector('form');
    const requested = requestId();
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('[type="submit"]');
      const error = form.querySelector('[data-error]');
      error.textContent = '';
      button.disabled = true;
      try {
        // The same id on a retry of this dialog, so a lost reply can't save twice.
        await onSubmit(form, requested);
        el.close();
      } catch (failure) {
        error.textContent = failure.status === 500 || !failure.message ? 'That could not be saved. Please try again.' : failure.message;
      } finally {
        button.disabled = false;
      }
    });
    el.showModal();
  }

  const confirmDialog = (message, label, run) => openDialog(label, `<p>${esc(message)}</p>`, label, async () => {
    await run();
  });

  const field = (f, value) => {
    const attrs = `name="${f.name}" ${f.required ? 'required' : ''}`;
    const label = `<span>${esc(f.label)}${f.required ? ' <span aria-hidden="true">*</span>' : ''}</span>`;
    const hint = f.hint ? `<small>${esc(f.hint)}</small>` : '';
    const wide = f.wide ? ' biz-form__wide' : '';
    switch (f.type) {
      case 'check':
        return `<label class="field check biz-form__wide"><input type="checkbox" ${attrs} ${Number(value) ? 'checked' : ''}>${esc(f.label)}</label>`;
      case 'status':
        return `<label class="field${wide}">${label}<select class="input" name="${f.name}"><option value="1" ${Number(value ?? 1) ? 'selected' : ''}>Active</option><option value="0" ${Number(value ?? 1) ? '' : 'selected'}>Inactive</option></select>${hint}</label>`;
      case 'select': {
        const choices = f.options();
        if (value && !choices.some(([gid]) => gid === value)) choices.unshift([value, '(inactive)']);
        return `<label class="field${wide}">${label}<select class="input" ${attrs}><option value="">${f.required ? 'Choose…' : 'None'}</option>${choices.map(([gid, name]) => `<option value="${esc(gid)}" ${gid === value ? 'selected' : ''}>${esc(name)}</option>`).join('')}</select>${hint}</label>`;
      }
      case 'textarea':
        return `<label class="field${wide}">${label}<textarea class="input" rows="2" ${attrs}>${esc(value)}</textarea>${hint}</label>`;
      case 'money':
        return `<label class="field${wide}">${label}<input class="input" type="number" min="0" step="0.01" inputmode="decimal" ${attrs} value="${value === undefined || value === null || value === '' ? '' : Number(value) / 100}">${hint}</label>`;
      case 'number':
        return `<label class="field${wide}">${label}<input class="input" type="number" min="0" step="1" inputmode="numeric" ${attrs} value="${esc(value)}">${hint}</label>`;
      case 'qty':
        return `<label class="field${wide}">${label}<input class="input" type="number" min="0" step="any" inputmode="decimal" ${attrs} value="${esc(value)}">${hint}</label>`;
      case 'date':
      case 'email':
        return `<label class="field${wide}">${label}<input class="input" type="${f.type}" ${attrs} value="${esc(value)}">${hint}</label>`;
      default:
        return `<label class="field${wide}">${label}<input class="input" type="text" maxlength="500" ${f.placeholder ? `placeholder="${esc(f.placeholder)}"` : ''} ${attrs} value="${esc(value)}">${hint}</label>`;
    }
  };

  const defaultOf = (f) => (typeof f.value === 'function' ? f.value() : f.value);

  function readField(form, f) {
    const input = form.elements[f.name];
    if (!input) return undefined;
    switch (f.type) {
      case 'check': return input.checked ? 1 : 0;
      case 'status': return Number(input.value);
      case 'money': return input.value === '' ? null : toMinor(input.value);
      case 'number': return input.value === '' ? null : Number(input.value);
      case 'qty': return input.value === '' ? null : Number(input.value);
      default: return input.value.trim();
    }
  }

  function recordDialog(key, row) {
    const view = LISTS[key];
    const fields = view.fields.filter((f) => !(f.newOnly && row));
    const body = fields.map((f) => field(f, row ? row[f.name] : defaultOf(f))).join('');
    openDialog(`${row ? 'Edit' : 'New'} ${view.singular.toLowerCase()}`, body, 'Save', async (form, request) => {
      const values = {};
      fields.forEach((f) => { values[f.name] = readField(form, f); });
      const missing = fields.find((f) => f.required && (values[f.name] === null || values[f.name] === ''));
      if (missing) throw new Error(`${missing.label} is required.`);
      const payload = { action: 'save', entity: view.entity, request_id: request };
      if (row) {
        // Only what changed, so an edit never overwrites a counter's newer value of another field.
        const changed = {};
        fields.forEach((f) => {
          const before = row[f.name] ?? (f.type === 'status' ? 1 : f.type === 'check' ? 0 : '');
          const after = values[f.name] ?? '';
          if (String(before) !== String(after)) changed[f.name] = values[f.name] ?? (['money', 'number'].includes(f.type) ? 0 : null);
        });
        if (!Object.keys(changed).length) return;
        Object.assign(payload, { gid: row.gid, fields: changed, base: state.version });
      } else {
        const { opening_stock: opening, ...fieldValues } = values;
        Object.keys(fieldValues).forEach((name) => { if (fieldValues[name] === null || fieldValues[name] === '') delete fieldValues[name]; });
        Object.assign(payload, { fields: { ...fieldValues, ...(view.newDefaults || {}) }, opening_stock: opening || 0 });
      }
      await post(payload);
      toast('Saved. Counters receive it within 30 seconds.', 'ok');
      await reload(key);
    });
  }

  function stockDialog(row) {
    const key = currentKey();
    const body = `
      <p>${esc(row.name)}: ${number(row.stock_qty)} in stock now.</p>
      <label class="field">Change<select class="input" name="kind"><option value="add">Stock added</option><option value="damage">Damaged or written off</option><option value="correction">Correct the count to…</option></select></label>
      <label class="field">Quantity<input class="input" type="number" min="0" step="any" inputmode="decimal" name="qty" required></label>
      <label class="field biz-form__wide">Note<input class="input" type="text" name="note" maxlength="500"></label>`;
    openDialog('Change stock', body, 'Save', async (form, request) => {
      if (form.elements.qty.value === '') throw new Error('Enter a quantity.');
      await post({ action: 'adjust_stock', request_id: request, item: row.gid, kind: form.elements.kind.value, qty: Number(form.elements.qty.value), note: form.elements.note.value });
      toast('Stock updated on every counter.', 'ok');
      await reload(key);
    });
  }

  async function setPoStatus(po, status) {
    await post({ action: 'set_po_status', gid: po.gid, status });
    toast(status === 'ordered' ? 'Order placed.' : 'Order cancelled.', 'ok');
    await reload(currentKey());
  }

  function paymentDialog() {
    const key = currentKey();
    const fields = [
      { name: 'supplier', label: 'Supplier', type: 'select', options: options('suppliers'), required: true },
      { name: 'amount', label: 'Amount', type: 'money', required: true },
      { name: 'paid_date', label: 'Date', type: 'date', required: true },
    ];
    const body = `${field(fields[0])}${field(fields[1])}${field(fields[2], localDate())}
      <label class="field">Method<select class="input" name="method"><option value="cash">Cash</option><option value="bank">Bank</option><option value="card">Card</option><option value="other">Other</option></select></label>
      ${field({ name: 'bank_account', label: 'Bank account', type: 'select', options: options('bank_accounts') })}
      ${field({ name: 'reference', label: 'Reference' })}
      ${field({ name: 'note', label: 'Note', type: 'textarea', wide: true })}`;
    openDialog('New supplier payment', body, 'Save', async (form, request) => {
      const e = form.elements;
      if (!e.supplier.value || !e.amount.value) throw new Error('Choose a supplier and enter an amount.');
      await post({
        action: 'pay_supplier', request_id: request, supplier: e.supplier.value, amount_minor: toMinor(e.amount.value), method: e.method.value,
        bank_account: e.method.value === 'bank' ? e.bank_account.value : '', paid_date: e.paid_date.value, reference: e.reference.value, note: e.note.value,
      });
      toast('Payment saved. Counters receive it within 30 seconds.', 'ok');
      await reload(key);
    });
  }

  // A new purchase order, or goods received (optionally against an order).
  function purchaseDialog(kind, po = null) {
    const key = currentKey();
    const receiving = kind === 'grn';
    const items = (state.records.items || []).filter((item) => active(item) && !Number(item.is_service));
    const itemOptions = (selected) => `<option value="">Choose…</option>${items.map((item) => `<option value="${esc(item.gid)}" data-cost="${Number(item.cost_minor || 0)}" ${item.gid === selected ? 'selected' : ''}>${esc(item.name)}</option>`).join('')}`;
    const line = (l = {}) => `<tr>
      <td><select class="input input--sm" name="item" aria-label="Product" ${l.po_item ? 'disabled' : ''}>${itemOptions(l.item)}</select><input type="hidden" name="po_item" value="${esc(l.po_item || '')}"></td>
      <td><input class="input input--sm" name="qty" type="number" min="0" step="any" inputmode="decimal" aria-label="Quantity" value="${esc(l.qty ?? '')}"></td>
      <td><input class="input input--sm" name="cost" type="number" min="0" step="0.01" inputmode="decimal" aria-label="Unit cost" value="${l.cost === undefined ? '' : l.cost / 100}"></td>
      ${receiving ? '<td><input class="input input--sm" name="batch" aria-label="Batch" maxlength="100"></td><td><input class="input input--sm" name="expiry" type="date" aria-label="Expiry date"></td>' : ''}
      <td><button class="icon-btn" type="button" data-remove-line aria-label="Remove line">${SVG(ICON.trash)}</button></td>
    </tr>`;
    const openLines = po ? poLines(po).filter((l) => Number(l.qty_received) < Number(l.qty_ordered)).map((l) => ({
      item: l.item_id, po_item: l.gid, qty: Number(l.qty_ordered) - Number(l.qty_received), cost: Number(l.unit_cost_minor),
    })) : [{}];
    const supplierField = po
      ? `<label class="field">Supplier<input class="input" value="${esc(nameOf('suppliers', po.supplier_id))}" disabled></label>`
      : field({ name: 'supplier', label: 'Supplier', type: 'select', options: options('suppliers'), required: true });
    const body = receiving
      ? `${supplierField}${field({ name: 'received_date', label: 'Received on', type: 'date', required: true }, localDate())}${field({ name: 'invoice_ref', label: 'Supplier invoice no.' })}${field({ name: 'notes', label: 'Notes' })}`
      : `${supplierField}${field({ name: 'order_date', label: 'Order date', type: 'date', required: true }, localDate())}${field({ name: 'expected_date', label: 'Expected on', type: 'date' })}
         <label class="field">Status<select class="input" name="status"><option value="ordered">Placed with the supplier</option><option value="draft">Draft</option></select></label>${field({ name: 'notes', label: 'Notes', type: 'textarea', wide: true })}`;
    const lines = `<div class="biz-form__wide"><div class="table-wrap"><table class="data-table biz-lines"><thead><tr><th>Product</th><th>Qty</th><th>Unit cost</th>${receiving ? '<th>Batch</th><th>Expiry</th>' : ''}<th></th></tr></thead><tbody data-lines>${openLines.map(line).join('')}</tbody></table></div>
      <button class="btn btn--ghost btn--sm" type="button" data-add-line>+ Add line</button></div>`;
    openDialog(receiving ? 'Receive goods' : 'New purchase order', body + lines, receiving ? 'Receive into stock' : 'Save order', async (form, request) => {
      const rows = [...form.querySelectorAll('[data-lines] tr')].map((row) => ({
        item: row.querySelector('[name="item"]').value,
        po_item: row.querySelector('[name="po_item"]').value,
        qty: Number(row.querySelector('[name="qty"]').value),
        unit_cost_minor: toMinor(row.querySelector('[name="cost"]').value || 0),
        batch_no: row.querySelector('[name="batch"]')?.value || '',
        expiry_date: row.querySelector('[name="expiry"]')?.value || '',
      })).filter((row) => row.item || row.qty);
      if (!rows.length) throw new Error('Add at least one line.');
      const e = form.elements;
      const supplier = po ? po.supplier_id : e.supplier.value;
      if (receiving) {
        await post({ action: 'receive_goods', request_id: request, supplier, po: po?.gid || '', received_date: e.received_date.value, invoice_ref: e.invoice_ref.value, notes: e.notes.value, lines: rows });
        toast('Received. Stock is updated on every counter.', 'ok');
      } else {
        await post({ action: 'create_po', request_id: request, supplier, order_date: e.order_date.value, expected_date: e.expected_date.value, status: e.status.value, notes: e.notes.value, lines: rows });
        toast('Order saved.', 'ok');
      }
      await reload(key);
    });
    const form = dialog().querySelector('form');
    form.addEventListener('click', (event) => {
      if (event.target.closest('[data-add-line]')) form.querySelector('[data-lines]').insertAdjacentHTML('beforeend', line());
      event.target.closest('[data-remove-line]')?.closest('tr').remove();
    });
    form.addEventListener('change', (event) => {
      if (event.target.name !== 'item') return;
      const cost = event.target.closest('tr').querySelector('[name="cost"]');
      if (cost.value === '') cost.value = Number(event.target.selectedOptions[0]?.dataset.cost || 0) / 100;
    });
  }

  // Importing suppliers from a CSV: the header row names the fields.
  function parseCsv(content) {
    const rows = [];
    let row = [];
    let cell = '';
    let quoted = false;
    for (let i = 0; i < content.length; i += 1) {
      const c = content[i];
      if (quoted) {
        if (c === '"' && content[i + 1] === '"') { cell += '"'; i += 1; }
        else if (c === '"') quoted = false;
        else cell += c;
      } else if (c === '"') quoted = true;
      else if (c === ',') { row.push(cell); cell = ''; }
      else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
      else if (c !== '\r') cell += c;
    }
    if (cell || row.length) { row.push(cell); rows.push(row); }
    return rows.filter((r) => r.some((value) => value.trim()));
  }

  async function importCsv(key, file) {
    const view = LISTS[key];
    const [header, ...rows] = parseCsv((await file.text()).replace(/^﻿/, ''));
    const names = (header || []).map((h) => h.trim().toLowerCase().replace(/\s+/g, '_'));
    if (!names.includes('name')) {
      toast(`The first row must name the columns, including “name”: ${view.importable.join(', ')}.`, 'error');
      return;
    }
    let saved = 0;
    const failed = [];
    for (const row of rows) {
      const fields = {};
      names.forEach((name, i) => { if (view.importable.includes(name) && row[i]?.trim()) fields[name] = row[i].trim(); });
      try {
        await post({ action: 'save', entity: view.entity, request_id: requestId(), fields });
        saved += 1;
      } catch (error) {
        failed.push(`${fields.name || '?'}: ${error.message}`);
      }
    }
    toast(failed.length ? `Imported ${saved}. Not imported: ${failed.slice(0, 3).join('; ')}${failed.length > 3 ? '…' : ''}` : `Imported ${saved}.`, failed.length ? 'error' : 'ok');
    await reload(key);
  }

  // --- Dashboard ------------------------------------------------------------
  async function renderDashboard(key, section) {
    const data = await DiwanApi.getJson(query({ view: 'dashboard', today: localDate() }));
    state.version = data.version;
    state.dashboard = data;
    const card = (label, tag, value, sub) => `<div class="stat biz-stat"><div class="biz-stat__top"><span class="stat__label">${esc(label)}</span><span class="biz-tag">${esc(tag)}</span></div><strong class="biz-stat__value">${esc(value)}</strong><span class="stat__sub">${esc(sub)}</span></div>`;
    const terminals = state.context.terminals || [];
    const online = terminals.filter((t) => Number(t.online)).length;
    const title = state.context.settings?.business_name || state.context.businesses.find((b) => b.id === state.context.business)?.name || 'Dashboard';
    const reportLink = (name, label) => `<a href="#b-reports" data-report="${name}">${esc(label)}<span aria-hidden="true">→</span></a>`;
    section.innerHTML = `${head(key, title, { sub: `${online} of ${terminals.length} counter${terminals.length === 1 ? '' : 's'} online. Figures update as counters sync.` })}
      <div class="dash-stats">
        ${card('Sales', 'Today', money(data.today.total_minor), `${data.today.orders} orders`)}
        ${card('Sales', 'Last 30 days', money(data.last_30.total_minor), `${data.last_30.orders} orders`)}
        ${card('Purchases', 'This month', money(data.purchases.total_minor), `${data.purchases.count} goods received`)}
        <a class="stat biz-stat" href="#b-products"><div class="biz-stat__top"><span class="stat__label">Alerts</span><span class="biz-tag">All</span></div>
          <strong class="biz-stat__value">${data.alerts.low_stock}<small> low stock</small></strong><span class="stat__sub">${data.alerts.expired} expired batch${data.alerts.expired === 1 ? '' : 'es'} still in stock</span></a>
      </div>
      <section class="panel biz-card" aria-labelledby="biz-chart-title">
        <div class="biz-chart-head">
          <h2 id="biz-chart-title">Sales</h2>
          <div class="biz-exports" role="group" aria-label="Chart period">${[['monthly', 'Monthly'], ['weekly', 'Weekly'], ['daily', 'Daily']].map(([value, label]) => `<button type="button" data-chart="${value}" aria-pressed="${state.chart === value}">${label}</button>`).join('')}</div>
        </div>
        <div data-chart-host></div>
      </section>
      <div class="biz-two">
        <section class="panel biz-card"><h2>Most used reports</h2><div class="biz-links">
          ${reportLink('terminals', 'By terminals')}${reportLink('profit_loss', 'Profit and loss')}${reportLink('inventory', 'Inventory in hand')}${reportLink('suppliers', 'By supplier')}
        </div></section>
        <section class="panel biz-card"><h2>Counters</h2>${terminals.length ? `<ul class="biz-counters">${terminals.map((t) => `<li><span class="dot ${Number(t.online) ? 'dot--ok' : ''}"></span><strong>${esc(t.name)}</strong><small>${t.last_seen_at ? `Last seen ${esc(t.last_seen_at)}` : 'Not seen yet'}${Number(t.pending_count) ? ` · ${Number(t.pending_count)} waiting to sync` : ''}</small></li>`).join('')}</ul>` : '<p>No counters connected.</p>'}</section>
      </div>`;
    drawChart(section);
  }

  function chartBuckets() {
    const days = new Map(state.dashboard.days.map((d) => [d.day, d]));
    const buckets = [];
    const add = (label, from, count) => {
      let total = 0;
      let orders = 0;
      for (let i = 0; i < count; i += 1) {
        const date = new Date(from);
        date.setDate(date.getDate() + i);
        const day = days.get(localDate(date));
        total += day?.total_minor || 0;
        orders += day?.orders || 0;
      }
      buckets.push({ label, total, orders });
    };
    const dayLabel = (date) => date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    const now = new Date();
    if (state.chart === 'daily') {
      for (let i = 29; i >= 0; i -= 1) {
        const date = new Date(now);
        date.setDate(now.getDate() - i);
        add(dayLabel(date), date, 1);
      }
    } else if (state.chart === 'weekly') {
      for (let i = 11; i >= 0; i -= 1) {
        const date = new Date(now);
        date.setDate(now.getDate() - i * 7 - 6);
        add(dayLabel(date), date, 7);
      }
    } else {
      for (let i = 11; i >= 0; i -= 1) {
        const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
        add(date.toLocaleDateString('en-GB', { month: 'short', year: '2-digit' }), date, new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate());
      }
    }
    return buckets;
  }

  function niceMax(value) {
    if (value <= 0) return 100;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    return [1, 2, 2.5, 5, 10].map((step) => step * magnitude).find((step) => step >= value);
  }

  // One series, so no legend: the title names it. Hover or tap shows each point.
  function drawChart(section) {
    const host = section.querySelector('[data-chart-host]');
    const buckets = chartBuckets();
    // Drawn at its real width, so axis labels stay readable on a phone.
    const W = Math.max(300, Math.round(host.clientWidth || 760));
    const [H, L, R, T, B] = [W < 500 ? 220 : 280, 58, 12, 12, 34];
    const maxRupees = niceMax(Math.max(...buckets.map((b) => b.total)) / 100);
    const x = (i) => L + (buckets.length === 1 ? 0 : (i * (W - L - R)) / (buckets.length - 1));
    const y = (minor) => H - B - ((minor / 100) / maxRupees) * (H - T - B);
    const line = buckets.map((b, i) => `${x(i).toFixed(1)},${y(b.total).toFixed(1)}`).join(' ');
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => maxRupees * f);
    const every = Math.ceil(buckets.length / Math.max(3, Math.floor((W - L - R) / 90)));
    host.innerHTML = `<div class="biz-chart">
      <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Sales ${esc(state.chart)}: ${esc(buckets.map((b) => `${b.label} ${money(b.total)}`).slice(-3).join(', '))}">
        ${ticks.map((t) => `<line class="biz-chart__grid" x1="${L}" x2="${W - R}" y1="${y(t * 100)}" y2="${y(t * 100)}"/><text class="biz-chart__label" x="${L - 8}" y="${y(t * 100) + 4}" text-anchor="end">${esc(number(t))}</text>`).join('')}
        ${buckets.map((b, i) => (i % every === 0 ? `<text class="biz-chart__label" x="${x(i)}" y="${H - 12}" text-anchor="middle">${esc(b.label)}</text>` : '')).join('')}
        <polygon class="biz-chart__area" points="${L},${H - B} ${line} ${x(buckets.length - 1)},${H - B}"/>
        <polyline class="biz-chart__line" points="${line}"/>
        <line class="biz-chart__cross" data-cross y1="${T}" y2="${H - B}" hidden/>
        <circle class="biz-chart__dot" data-dot r="5" hidden/>
      </svg>
      <div class="biz-chart__tip" data-tip hidden></div>
    </div>
    <details class="biz-chart__data"><summary>View as table</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Period</th><th class="biz-right">Orders</th><th class="biz-right">Sales</th></tr></thead><tbody>${buckets.map((b) => `<tr><td>${esc(b.label)}</td><td class="biz-right">${b.orders}</td><td class="biz-right">${money(b.total)}</td></tr>`).join('')}</tbody></table></div></details>`;

    const svg = host.querySelector('svg');
    const cross = host.querySelector('[data-cross]');
    const dot = host.querySelector('[data-dot]');
    const tip = host.querySelector('[data-tip]');
    const point = (event) => {
      const box = svg.getBoundingClientRect();
      const px = ((event.clientX - box.left) / box.width) * W;
      const i = Math.max(0, Math.min(buckets.length - 1, Math.round(((px - L) / (W - L - R)) * (buckets.length - 1))));
      const b = buckets[i];
      cross.setAttribute('x1', x(i));
      cross.setAttribute('x2', x(i));
      dot.setAttribute('cx', x(i));
      dot.setAttribute('cy', y(b.total));
      cross.removeAttribute('hidden');
      dot.removeAttribute('hidden');
      tip.hidden = false;
      tip.innerHTML = `<strong>${esc(money(b.total))}</strong><span>${esc(b.label)} · ${b.orders} orders</span>`;
      const left = (x(i) / W) * box.width;
      tip.style.left = `${Math.max(70, Math.min(box.width - 70, left))}px`;
    };
    svg.addEventListener('pointermove', point);
    svg.addEventListener('pointerdown', point);
    svg.addEventListener('pointerleave', () => {
      cross.setAttribute('hidden', '');
      dot.setAttribute('hidden', '');
      tip.hidden = true;
    });
  }

  // --- Orders in progress ---------------------------------------------------
  const ORDER_COLUMNS = [
    numberCol('Order #', (r) => r.order_no), text('Started', (r) => r.created_at), tagCol('Type', (r) => r.order_type),
    text('Table', (r) => r.table), text('Customer', (r) => r.customer), numberCol('Lines', (r) => r.lines), moneyCol('Total', (r) => r.total_minor),
    tagCol('Status', (r) => r.status, ['ready', 'served'], ['held']),
  ];

  async function renderOrders(key, section) {
    const data = await DiwanApi.getJson(query({ view: 'orders' }));
    state.version = data.version;
    state.orders = data.orders;
    if (!section.dataset.rendered) {
      section.innerHTML = `${head(key, 'In-progress orders', { sub: 'What the counters are working on now. Orders are taken and paid on the POS.' })}<div class="panel biz-card" data-table-host></div>`;
    }
    drawOrders();
  }
  const drawOrders = () => table(sectionOf('b-orders').querySelector('[data-table-host]'), 'b-orders', ORDER_COLUMNS, state.orders, { plural: 'orders' });

  // --- Sales ----------------------------------------------------------------
  const SALE_COLUMNS = [
    text('Date & time', (r) => r.sold_at), text('Counter', (r) => r.terminal), text('Cashier', (r) => r.cashier), text('Customer', (r) => r.customer), text('Table', (r) => r.table),
    tagCol('Payment', (r) => r.payment_method), moneyCol('Discount', (r) => r.discount_minor), moneyCol('Tax', (r) => r.tax_minor), moneyCol('Total', (r) => r.total_minor),
  ];

  const datesOf = (key, days = 6) => {
    state.dates[key] ||= { from: daysAgo(days), to: localDate() };
    return state.dates[key];
  };

  async function renderSales(key, section) {
    const dates = datesOf(key);
    const data = await DiwanApi.getJson(query({ view: 'sales', from: dates.from, to: dates.to }));
    state.sales = data.sales;
    state.salesTruncated = data.truncated;
    if (!section.dataset.rendered) {
      section.innerHTML = `${head(key, 'Sales', { sub: 'Every sale from every counter. Sales are recorded on the POS.' })}
        <form class="panel biz-card biz-filters" data-dates-form>
          <h2>Search &amp; filter</h2>
          <div class="biz-filters__grid">
            <label class="field">From<input class="input" type="date" name="from" value="${esc(dates.from)}" required></label>
            <label class="field">To<input class="input" type="date" name="to" value="${esc(dates.to)}" required></label>
          </div>
          <div class="biz-filters__actions"><button class="btn btn--primary btn--sm" type="submit">Filter</button></div>
        </form>
        <p class="biz-warning" data-truncated hidden>This range has more than 2,000 sales, so only the newest are listed. Choose a shorter range.</p>
        <div class="panel biz-card" data-table-host></div>`;
    }
    drawSales();
  }

  function drawSales() {
    const section = sectionOf('b-sales');
    section.querySelector('[data-truncated]').hidden = !state.salesTruncated;
    table(section.querySelector('[data-table-host]'), 'b-sales', SALE_COLUMNS, state.sales, {
      plural: 'sales',
      footer: (rows) => `Total ${money(rows.reduce((sum, r) => sum + Number(r.total_minor), 0))}`,
      actions: (row) => `<div class="biz-actions"><button class="btn btn--ghost btn--sm" type="button" data-sale="${esc(row.gid)}">View</button></div>`,
    });
  }

  async function saleDialog(gid) {
    const sale = state.sales.find((row) => row.gid === gid);
    const data = await DiwanApi.getJson(query({ view: 'sale', gid }));
    openDialog(`Sale ${sale?.sold_at || ''}`, `<div class="biz-form__wide"><div class="table-wrap"><table class="data-table"><thead><tr><th>Item</th><th class="biz-right">Qty</th><th class="biz-right">Price</th><th class="biz-right">Amount</th></tr></thead>
      <tbody>${data.lines.map((l) => `<tr><td>${esc(l.item)}${l.notes ? `<br><small>${esc(l.notes)}</small>` : ''}</td><td class="biz-right">${number(l.qty)}</td><td class="biz-right">${money(l.price_minor)}</td><td class="biz-right">${money(Math.round(l.qty * l.price_minor))}</td></tr>`).join('') || '<tr><td colspan="4">No lines synced yet.</td></tr>'}</tbody></table></div>
      <p><strong>Total ${money(sale?.total_minor)}</strong> · ${data.payments.map((p) => `${esc(humanize(p.method))} ${money(p.amount_minor)}`).join(', ') || esc(humanize(sale?.payment_method))}</p></div>`);
  }

  // --- Reports --------------------------------------------------------------
  const REPORTS = {
    terminals: { label: 'By terminals', columns: [text('Counter', (r) => r.terminal), numberCol('Orders', (r) => r.orders), moneyCol('Sales', (r) => r.total_minor)] },
    profit_loss: { label: 'Profit and loss' },
    inventory: {
      label: 'Inventory in hand', noDates: true,
      columns: [text('Product', (r) => r.name), text('Category', (r) => r.category),
        { label: 'In stock', right: true, value: (r) => r.qty, text: (v) => number(v), html: (v, r) => `<span class="${r.low ? 'biz-low' : ''}">${number(v)}</span>` },
        moneyCol('Value at cost', (r) => r.cost_value_minor), moneyCol('Value at sale price', (r) => r.retail_value_minor)],
      footer: (rows) => `At cost ${money(rows.reduce((sum, r) => sum + r.cost_value_minor, 0))}`,
    },
    suppliers: { label: 'By supplier', columns: [text('Supplier', (r) => r.name), numberCol('Invoices', (r) => r.invoices), moneyCol('Purchased', (r) => r.purchased_minor), moneyCol('Paid', (r) => r.paid_minor), moneyCol('Balance owed (all time)', (r) => r.balance_minor)] },
  };

  async function renderReports(key, section) {
    const dates = datesOf(key, 29);
    const report = REPORTS[state.report];
    state.reportData = await DiwanApi.getJson(query({ view: 'report', name: state.report, from: dates.from, to: dates.to }));
    section.innerHTML = `${head(key, 'Reports')}
      <div class="panel biz-card">
        <div class="biz-exports biz-report-tabs" role="group" aria-label="Report">${Object.entries(REPORTS).map(([name, r]) => `<button type="button" data-report="${name}" aria-pressed="${name === state.report}">${esc(r.label)}</button>`).join('')}</div>
        ${report.noDates ? '' : `<form class="biz-filters__grid biz-report-dates" data-dates-form>
          <label class="field">From<input class="input" type="date" name="from" value="${esc(dates.from)}" required></label>
          <label class="field">To<input class="input" type="date" name="to" value="${esc(dates.to)}" required></label>
          <div class="biz-filters__actions"><button class="btn btn--primary btn--sm" type="submit">Run</button></div>
        </form>`}
      </div>
      <div class="panel biz-card" data-table-host></div>`;
    drawReport();
  }

  function drawReport() {
    const host = sectionOf('b-reports').querySelector('[data-table-host]');
    const report = REPORTS[state.report];
    if (report.columns) {
      table(host, `b-report-${state.report}`, report.columns, state.reportData.rows, { plural: 'rows', footer: report.footer });
      return;
    }
    const d = state.reportData;
    const line = (label, amount, total = false) => ({ label, amount, total });
    table(host, 'b-report-profit_loss', [
      { label: 'Line', value: (r) => r.label, html: (v, r) => (r.total ? `<strong>${esc(v)}</strong>` : esc(v)) },
      { label: 'Amount', right: true, value: (r) => r.amount, text: (v) => money(v), html: (v, r) => (r.total ? `<strong>${money(v)}</strong>` : money(v)) },
    ], [
      line('Gross sales', d.gross_minor), line('Discounts', -d.discounts_minor), line('Refunds', -d.refunds_minor), line('Service charge', d.service_minor),
      line('Net revenue', d.net_revenue_minor, true), line('Cost of goods', -d.cogs_minor), line('Gross profit', d.gross_profit_minor, true),
      line('Expenses', -d.expenses_minor), line('Salaries', -d.salaries_minor), line('Net profit', d.net_profit_minor, true), line('Tax collected (not revenue)', d.tax_minor),
    ], { plural: 'lines', footer: () => `${d.transactions} sales` });
  }

  // --- Settings -------------------------------------------------------------
  const SETTINGS_FIELDS = [
    { name: 'business_name', label: 'Business name', required: true },
    { name: 'phone', label: 'Phone' },
    { name: 'delivery_number', label: 'Delivery number' },
    { name: 'business_website', label: 'Website' },
    { name: 'business_address', label: 'Address', type: 'textarea', wide: true },
    { name: 'tax_percent', label: 'Tax %', type: 'qty' },
    { name: 'service_charge_percent', label: 'Service charge %', type: 'qty' },
    { name: 'receipt_tagline', label: 'Receipt tagline' },
    { name: 'receipt_footer', label: 'Receipt footer' },
  ];

  async function renderSettings(key, section) {
    contextLoad = null;
    await loadContext();
    const settings = state.context.settings || {};
    section.innerHTML = `${head(key, 'Business settings', { sub: 'Shared by every counter. Printers and theme stay on each device.' })}
      <form class="panel biz-card" data-settings>
        <div class="biz-form">${SETTINGS_FIELDS.map((f) => field(f, settings[f.name])).join('')}</div>
        <p class="admin-dialog__error" data-error role="alert"></p>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Save settings</button></div>
      </form>`;
  }

  async function saveSettings(form) {
    const key = currentKey();
    const settings = state.context.settings || {};
    const changed = {};
    SETTINGS_FIELDS.forEach((f) => {
      const value = readField(form, f);
      if (String(settings[f.name] ?? '') !== String(value ?? '')) changed[f.name] = value ?? (f.type === 'qty' ? 0 : null);
    });
    const error = form.querySelector('[data-error]');
    error.textContent = '';
    if (!Object.keys(changed).length) return;
    try {
      await post({ action: 'save', entity: 'app_config', gid: 'config', fields: changed, base: state.version });
      toast('Saved. Counters receive it within 30 seconds.', 'ok');
      await reload(key);
    } catch (failure) {
      error.textContent = failure.message;
    }
  }

  // --- Events ---------------------------------------------------------------
  const rowOf = (key, gid) => (state.records[LISTS[key]?.load[0]] || []).find((row) => row.gid === gid);

  document.addEventListener('click', async (event) => {
    const target = event.target;
    if (target.closest('#biz-dialog [data-close]')) {
      dialog().close();
      return;
    }
    const reportLink = target.closest('[data-report]');
    if (reportLink) {
      state.report = reportLink.dataset.report;
      // A dashboard link renders it through its hash change; a tab has no such change.
      if (reportLink.tagName !== 'A') await show('b-reports');
      return;
    }
    const section = target.closest('.biz');
    if (!section) return;
    const key = section.dataset.view;

    if (target.closest('[data-retry]')) {
      contextLoad = null;
      route();
    } else if (target.closest('[data-new]')) {
      const view = LISTS[key];
      if (view.newAction) view.newAction();
      else recordDialog(key);
    } else if (target.closest('[data-edit]')) {
      recordDialog(key, rowOf(key, target.closest('[data-edit]').dataset.edit));
    } else if (target.closest('[data-delete]')) {
      const gid = target.closest('[data-delete]').dataset.delete;
      const row = rowOf(key, gid);
      confirmDialog(`Delete ${row?.name || `this ${LISTS[key].singular.toLowerCase()}`}? Every counter removes it too.`, 'Delete', async () => {
        await post({ action: 'delete', entity: LISTS[key].entity, gid });
        toast('Deleted.', 'ok');
        await reload(key);
      });
    } else if (target.closest('[data-row-action]')) {
      const button = target.closest('[data-row-action]');
      const row = rowOf(key, button.dataset.gid);
      const action = LISTS[key].rowActions.find((a) => a.label === button.dataset.rowAction);
      try {
        await action.run(row);
      } catch (error) {
        toast(error.message, 'error');
      }
    } else if (target.closest('[data-sale]')) {
      saleDialog(target.closest('[data-sale]').dataset.sale).catch((error) => toast(error.message, 'error'));
    } else if (target.closest('[data-sort]')) {
      const index = Number(target.closest('[data-sort]').dataset.sort);
      const t = tableState(tableKey(key));
      t.dir = t.sort === index ? -t.dir : 1;
      t.sort = index;
      redraw(key);
    } else if (target.closest('[data-page]')) {
      tableState(tableKey(key)).page = Number(target.closest('[data-page]').dataset.page);
      redraw(key);
    } else if (target.closest('[data-export]')) {
      exportTable(target.closest('[data-table-host]'), target.closest('[data-export]').dataset.export, ALL_VIEWS[key].title);
    } else if (target.closest('[data-chart]')) {
      state.chart = target.closest('[data-chart]').dataset.chart;
      section.querySelectorAll('[data-chart]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.chart === state.chart)));
      drawChart(section);
    } else if (target.closest('[data-filter-reset]')) {
      const t = tableState(key);
      t.filters = {};
      t.page = 1;
      section.querySelectorAll('[data-filter]').forEach((input) => { input.value = ''; });
      drawTable(key);
    }
  });

  // Reports each keep their own table state.
  const tableKey = (key) => (key === 'b-reports' ? `b-report-${state.report}` : key);

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (form.matches('[data-filter-form]')) {
      event.preventDefault();
      const key = form.closest('.biz').dataset.view;
      const t = tableState(key);
      t.filters = {};
      form.querySelectorAll('[data-filter]').forEach((input) => { if (input.value) t.filters[input.dataset.filter] = input.value; });
      t.page = 1;
      drawTable(key);
    } else if (form.matches('[data-dates-form]')) {
      event.preventDefault();
      const key = form.closest('.biz').dataset.view;
      state.dates[key] = { from: form.elements.from.value, to: form.elements.to.value };
      await show(key);
    } else if (form.matches('[data-settings]')) {
      event.preventDefault();
      await saveSettings(form);
    }
  });

  document.addEventListener('input', (event) => {
    if (!event.target.matches('.biz [data-search]')) return;
    const key = event.target.closest('.biz').dataset.view;
    const t = tableState(tableKey(key));
    t.search = event.target.value;
    t.page = 1;
    redraw(key);
  });

  document.addEventListener('change', (event) => {
    const target = event.target;
    if (target.matches('.biz [data-size]')) {
      const key = target.closest('.biz').dataset.view;
      const t = tableState(tableKey(key));
      t.size = target.value === 'all' ? 'all' : Number(target.value);
      t.page = 1;
      redraw(key);
    } else if (target.matches('.biz [data-import]')) {
      const key = target.closest('.biz').dataset.view;
      if (target.files[0]) importCsv(key, target.files[0]);
      target.value = '';
    } else if (target.matches('.biz [data-business]')) {
      state.context.business = Number(target.value);
      state.records = {};
      contextLoad = null;
      document.querySelectorAll('.biz').forEach((section) => { delete section.dataset.rendered; });
      DiwanApi.getJson(`${API}?view=context&business=${Number(target.value)}`).then((context) => {
        state.context = context;
        contextLoad = Promise.resolve(context);
        show(currentKey());
      }).catch((error) => toast(error.message, 'error'));
    }
  });

  mount();
})();
