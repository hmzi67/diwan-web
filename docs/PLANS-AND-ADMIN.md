# Plans, licence approval and the admin panel

Diwan is sold as three monthly plans — **BASIC**, **ADVANCE** and **ALL-IN-ONE**
— with **no online payment**. A customer requests a plan from their dashboard,
an administrator approves or rejects it in the private admin panel (`/admin/`),
and only an approval issues a licence. The plan decides which POS modules the
licence unlocks, and the POS enforces that in Rust from the signed licence.

This page is the operator's guide: setup, the workflow, what each piece does,
and what still needs a decision.

## 1. Deploying it

1. **Apply `database/migrations/016_plans_admin_and_license_requests.sql`**
   (phpMyAdmin is fine) *before* deploying the code. It is idempotent and
   keeps every existing row. A fresh install gets the same from `schema.sql`.
2. **Deploy.** `deploy.yml` now writes `TRIAL_SIGNUP_ENABLED=false` and
   `PAYMENTS_ENABLED=false` unless repository variables say otherwise (see §6).
3. **Create the first administrator** — there is no web sign-up and no
   credential in code, SQL or `.env`:

   ```sh
   php scripts/create-admin.php you@yourbusiness.com "Your Name" --env=/path/to/server/.env
   ```

   It prompts for a password (12+ characters, echo off) or reads it from
   STDIN. On a host without a shell, run it locally against the production
   database from a machine allowed to reach it. `--reset` changes an existing
   admin's password and signs that admin out everywhere.
4. Sign in at **`https://<your-site>/admin/`**.
5. Optional: run `php scripts/license-reminders.php --env=…` from a daily
   cron job. Without cron, the admin dashboard sends due reminders itself, at
   most once an hour, whenever an administrator opens it.
6. Release a POS build containing the plan enforcement (§4) — older builds
   ignore the new licence fields and keep every module.

## 2. The customer journey

1. The pricing cards (home page, `pricing.html`, `products.html`, the
   industry pages, the dashboard) are rendered by `frontend/src/js/plans.js`
   from `GET /api/plans.php` — prices and features are never typed into HTML.
2. **Request Access** → sign in or sign up → the dashboard's **Plan & access**
   view with that plan pre-selected → `POST /api/license-requests.php`.
   The request is `pending`; nothing is issued. The customer is notified, and
   so are administrators (in-app, plus `admin_notification_email` if set).
3. An administrator **approves** (choosing the plan, period and device limit,
   default from settings) or **rejects** with a reason the customer sees.
4. **Approval** (transactional; a repeat approval returns the first result):
   - no plan licence yet → a new licence is issued: an `orders` row with
     gateway `admin` and status `fulfilled` (never `paid`), and a licence
     recording the plan, the request and the approving admin. The key is shown
     to the admin once and is in the customer's dashboard;
   - an existing active/expired plan licence → it is **moved to the new plan
     and extended** (same key, same devices) — this is how renewals and
     upgrades work.
5. **Issuance ≠ activation.** A new licence is `issued` (no device). The
   customer enters the key in the app, which calls `activate-license.php` and
   takes one of the licence's device slots — it is then `active`.
6. A rejected customer may request again unless *Admin Settings → Let a
   rejected customer submit a new request* is off. A pending request can be
   cancelled by the customer.

## 3. Plans and what they unlock

The `plans`, `features` and `plan_features` tables are the single source of
truth for the pricing cards **and** the entitlements. Edit them in **Admin →
Plans & Features**.

- A **feature** lists the POS keys it unlocks (`pos_entitlements`) and its
  `availability`: `app` (enforced in the POS), `service` (delivered by the
  Diwan team) or `planned` (not built yet — shown as "coming soon").
- A plan's **entitlements** are the union of its active features' keys.
- `show_on_card` picks what the public card lists; the other rows are
  features deliberately carried up from a lower tier.

| Plan | Price | Card (as published) | Also includes (not on the card) |
|---|---|---|---|
| BASIC | PKR 10,000 / month | POS System, Inventory Management, Sales & Purchase Reports, Expense Management, Daily Sales Tracking, User-Friendly Dashboard, Basic Customer Support | — |
| ADVANCE | PKR 15,000 / month | Complete POS System, Full Inventory Management, Warehouse Management, Advanced Reports & Analytics, AI Features, Multi-User Access, Purchase & Sales Management, Expense Tracking, Priority Support | everything in BASIC except Basic Customer Support |
| ALL-IN-ONE | PKR 20,000 / month | Complete Supply Chain Management, Warehouse & Branch Management, Business Website, Mobile Application (Android / iOS), Online Order Management, Customer Management System, Dedicated Technical Support | everything in ADVANCE except Priority Support |

Feature → POS keys:

| Feature | Unlocks in the POS | Availability |
|---|---|---|
| POS System | billing, shifts, settings, users (Owner account only) | app |
| Complete POS System | tables (dine-in tables & kitchen) | app |
| Inventory Management | inventory | app |
| Full Inventory Management | inventory + `inventory_advanced` (stock takes, CSV import, barcode labels) | app |
| Sales & Purchase Reports | reports (sales, products, tables, refunds, delivery) | app |
| Advanced Reports & Analytics | reports + `reports_advanced` (P&L, order profit, payment types, tax, voids, stock reports) | app |
| Expense Management / Expense Tracking | expenses | app |
| Daily Sales Tracking, User-Friendly Dashboard | dashboard, reports | app |
| Multi-User Access | `multi_user` (staff logins besides the Owner), employees, attendance, salary | app |
| Purchase & Sales Management | purchasing, ledger | app |
| Complete Supply Chain Management | purchasing, ledger (wider supply chain not built) | app |
| Mobile Application | `mobile` (the Android/iOS app runs; Android installer downloads) | app |
| Online Order Management | delivery | app |
| Customer Management System | customers (accounts, pay-later) | app |
| Warehouse Management, Warehouse & Branch Management, AI Features | nothing yet | planned |
| Business Website, support tiers | nothing in the app | service |

**Licences issued before plans** (`plan_id` NULL: paid one-time, trial, App
Store) are unrestricted — no existing customer loses a module. Assign them a
plan from **Admin → Licenses → Change plan** when you are ready.

**Changing a plan's features** changes access for every licence on that plan
at its next check-in. The admin panel says how many active licences that
affects and asks first; the audit log records what was added and removed.
Changing a price never changes existing licences.

## 4. How the POS enforces the plan

`license-status.php` signs, with the Ed25519 key, `plan {code, name}`,
`entitlements` (the sorted POS keys) and `upgrade` (for each missing key, the
cheapest plan that has it) next to the existing status fields. The app
verifies the signature, keeps the entitlements in memory only, and checks them
in Rust on every command (`require_module`, `require_capability`):

- a module outside the plan is refused even if switched on in Settings, and
  appears in the navigation with a lock; opening it shows which plan has it;
- stock takes / CSV import / labels, the advanced reports, adding staff,
  non-Owner sign-in and running on Android/iOS each check their capability;
- a `suspended` licence locks billing like `revoked`, but reversibly.

Editing the WebView or SQLite changes nothing: the list comes from the signed
bytes. A patched binary can always do more; no client-side check prevents
that (see the POS repo's `vendor_gate.rs` note).

**Offline:** the app works up to `LICENSE_MAX_OFFLINE_DAYS` (default 30)
without checking in. A revocation, suspension, plan change or expiry reaches
an online device within ~6 hours (or at once on "Re-check now"); an offline
device learns of it at its next check-in, and locks when the offline window
runs out regardless. An expiry date inside the signed payload is enforced
offline on time.

## 5. Licences, devices and keys

- **Statuses:** `issued` (no device yet), `active`, `expired`, `suspended`,
  `revoked`. Transitions are enforced server-side: active ⇄ suspended,
  active/suspended/expired → revoked (final), expired → active by *Extend*.
- **Devices:** each licence has `max_devices` (plan default, editable per
  licence). It is also the number of **counters** the business can connect —
  one counter per device slot (see [MULTI-COUNTER.md](MULTI-COUNTER.md)). *Free this slot* / *Free all devices* make a device fail its next
  check-in; the customer can then activate another device in the slot.
- **Suspending an account** signs the customer out everywhere and suspends
  every licence they own without editing them; reactivating restores both.
- **Keys** are 64-bit random (`DIWAN-XXXX-XXXX-XXXX-XXXX`), looked up by
  HMAC. An AES-GCM copy is kept so the owner's dashboard can show the key
  (the existing design, see migration 002); the admin panel only ever shows
  the first group, except the one-time display on issue/rotate. *Rotate key*
  replaces a leaked key; the devices keep their slots.
- Activation and status checks are rate-limited per IP and per key prefix
  (`ActivationThrottle`), and wrong/revoked/foreign keys get one generic answer.

## 6. Configuration

| `.env` key | Default | Meaning |
|---|---|---|
| `PAYMENTS_ENABLED` | `false` | `checkout.php` answers 410 while false. Gateway webhooks stay live so an order paid before the switch is still fulfilled. |
| `TRIAL_SIGNUP_ENABLED` | `false` | Free trials issue a licence with no approval, so they are off. |
| `MAIL_*` | — | Without a working `MAIL_FROM` (and `MAIL_HOST` for SMTP) notifications are in-app only and recorded as `not_configured`, never as sent. |
| `LICENSE_MAX_OFFLINE_DAYS` | `30` | Offline grace period the app is told. |

**Admin Settings** (stored in `admin_settings`): default licence period (30
days), reminder lead time (7 days), re-request after rejection, and the
address that receives "new request" emails.

## 7. Download tracking

`download_events` is written only by the real download path:
`issue-download.php` records `requested` (a link was issued), `download.php`
moves it to `served` when the link is redeemed (bytes start, or GitHub's
signed URL is handed over) and to `completed` when PHP streamed the whole
local file without a disconnect, or `failed` with the reason. A page refresh
records nothing; a reused link is not a second download. Each event keeps the
customer, licence, plan **at download time**, version, platform/build,
channel (dashboard vs bare licence key) and licence status — no IP, key or
token. **Admin → Downloads & Analytics** filters by date, plan, platform,
version, status, channel and customer, and exports CSV (formula-safe; the
export itself is audit-logged).

## 8. Security model (summary)

- Admins are a separate table; a customer session can never reach `/api/admin-*`.
- Admin session: signed HttpOnly cookie (`admin:` domain-separated from the
  customer cookie), SameSite=Strict, 12 hours, epoch-revocable; every admin
  POST also needs the `X-CSRF-Token` bound to that cookie.
- Admin sign-in is rate-limited per IP and per address and audit-logged.
- Customer endpoints take the customer id from the session only; request
  bodies cannot set status, plan, licence or owner (mass assignment is ignored).
- Every sensitive action is written to `audit_logs` in the same transaction.

## 9. Tests

```sh
php scripts/tests/run.php <test.env>          # service-level, incl. plans.test.php / requests.test.php
bash scripts/http-check.sh <test.env>         # smoke + scripts/tests/http-flow.php over real HTTP
```

## 10. Open decisions

- **Legal pages** (`terms.html`, `refund-policy.html`, `service-delivery.html`,
  `privacy.html`) still describe one-time payments, refunds and instant
  delivery. They need rewriting for monthly, approval-based plans.
- **Apple in-app purchase** (iOS) still issues licences automatically, as the
  App Store requires for digital unlocks. Decide whether iOS sales continue.
- **Device limits per plan** are seeded as BASIC 1, ADVANCE 3 and
  ALL-IN-ONE 10 (migration 018 sets these where a plan still had 1); adjust
  them in Plans & Features. They are the counter limits too: a restaurant
  with three counters needs 3.
- **"Warehouse", "Branch" and "AI" features** are `planned`: they are in the
  plans but nothing in the app yet.
- **How customers pay** (offline invoice, bank transfer…) is outside the
  system; the pricing copy says payment is agreed directly.
