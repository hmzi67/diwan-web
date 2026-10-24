# One business, many counters

A restaurant is **one business workspace**. Every counter (POS terminal) of
the business works on the same products, stock, sales, customers, staff and
settings. Reinstalling the app or replacing a device never creates a new
inventory: the business is keyed by the licence, and a new device loads it.

This page covers the design, setup, the API, and the known limits.

## 1. Before and after

**Before.**
- Each installation had its own SQLite database: its own products, its own
  stock and its own staff.
- `pos-sync.php` copied each device's data, one way, into per-device rows
  for the web dashboard.
- Two counters of one shop were two unrelated inventories.

**After.**
- **The server holds the business's authoritative copy.** Each record lives
  once per business: `sync_rows`, plus `sync_stock` for stock.
- **Every counter keeps a full local copy.** Billing, reports and every
  module work exactly as before, including offline. The counter then:
  - sends its own changes (*push*);
  - reads everyone else's (*pull*): every 2 seconds while the business is
    busy, slowing to 30 seconds when it is quiet, and at once after a sale
    on this counter or on another counter on the same network (§4).
- **Stock is central** and moves only by signed changes.
- **An online sale takes its stock centrally *before* it is recorded**, so
  two counters cannot sell the last unit twice.

```
 Counter 1 (SQLite) ─┐  push changes / pull changes   ┌─ businesses (1 per licence)
 Counter 2 (SQLite) ─┼──────── HTTPS, token ──────────┤  pos_terminals (1 per device slot)
 Counter 3 (SQLite) ─┘   reserve stock before a sale  └─ sync_rows · sync_stock · stock_ledger
```

## 2. How the pieces relate

| Concept | Where | Relationship |
|---|---|---|
| Business | `businesses` | One per licence (`license_id` is unique). A renewal, plan change or key rotation keeps the licence row, so it keeps the business. Owner is `customer_id`. |
| Licence | `licenses` | Has `plan_id` and `max_devices`. **`max_devices` is the counter limit.** Admin → Licenses → Device limit sets it, and plans give the default. |
| Counter | `pos_terminals` | Exactly one per device slot (`license_activations` row). Has a name, a public `code`, a token (stored as sha256 only), and the `install_id` that prefixes the ids its records get. |
| Staff user | the POS's `users` table, shared | A shared record of the business: a cashier created on one counter can sign in on every counter. Roles are enforced in Rust on each counter. |
| Record | `sync_rows (business_id, entity, gid)` | `gid` is the record's global id, `<install id>:<local id>`. Foreign keys travel as gids, so each counter keeps its own local integer ids. |

**No request ever chooses a business.** Registration derives it from the
licence key and the device's activation. After that, the counter's token is
the only credential, and it resolves to exactly one business. A `business_id`
or `customer_id` in a request body is ignored.

## 3. Connecting a counter

**Settings → Licence & Sync → Business & counters** (Owner or Admin):

1. **Activate the licence on the device**, as before. This takes a device
   slot.
2. **Press *Connect this counter*.** The device registers and gets its token
   (`pos-terminal-register.php`). The server then says whether the business
   already has data.
3. **Make the one choice.** Nothing is ever merged silently:
   - **Empty business: *Use this counter's data for the business*.** This
     counter's products, stock (as opening balances), sales history,
     customers, staff and settings are uploaded. Only one counter can win
     this (`claim_seed`). Other counters can join once the upload completes
     (`complete_seed`).
   - **Business has data: *Replace with the business data*.**
     1. The whole business is downloaded into staging tables.
     2. This counter's database file is backed up to
        `app_data_dir/backups/pos-before-business-data-<time>.db`.
     3. Its shared data is replaced in **one** transaction, so a dropped
        connection never leaves half a business.
     4. Everyone is signed out, because the staff accounts are now the
        business's.
4. **The counter may push only after one of these.** The server refuses
   `not_joined` before that, so a stray install can never merge its own
   inventory into a business.

**A reinstalled or replaced device:** enter the key, connect, and choose
*Replace with the business data*. It gets everything back. The old
installation's token stops working.

## 4. Synchronisation

**Push (`pos-sync-push.php`).**
- SQLite triggers queue every local insert, update and delete of a shared
  table. The push re-reads each row, so ten edits travel once.
- The server applies a push in one transaction, holding the business row
  lock. Each change takes the next `businesses.sync_version`, so versions
  commit in order.
- Only what the server acknowledges leaves the counter's queue. A lost reply
  means a resend, and a resend is harmless:
  - rows are last-write-wins upserts;
  - stock changes are keyed (see §5).

**Pull (`pos-sync-pull.php?since=N`).**
- Returns everything after version N, oldest first, from one consistent
  snapshot: the changed rows (with tombstones), the changed stock balances,
  and the business's counters.
- A counter that was offline for a week simply pulls from where it stopped.
  Nothing is missed and nothing is applied twice.

**Applying.**
- Rows are applied parent-first.
- A record that points at something not yet received is *parked* and retried
  on every pull. For example, a sale arriving before its product.
- A record that breaks a local rule is parked too, and shown under *Needs a
  look*. For example, two counters creating a category with the same name
  offline: rename one and it applies.
- A product deleted on one counter but sold meanwhile on another is
  **archived**, not deleted, and the archive is sent back.

**Conflicts are resolved per field.**
- A counter sends, with each edited record, the fields it changed and its
  sync cursor at the time (`fields`, `base`). The server replaces only those
  fields, so one counter changing a product's price while another renames it
  keeps both edits.
- When two counters change the *same* field, the change that reaches the
  server last wins. If that counter had not seen the other's change (the
  other change has a version above its `base`), the overwrite is recorded as
  a `concurrent_edit` conflict for Admin → Businesses.
- A new record, or a change from an older POS build, carries the whole
  record and replaces it, keeping fields it does not carry.
- `sync_rows.field_versions` holds, per field, the version and counter of
  its last write. Stock is the exception (§5).

**What is per device.**
- Printer, theme and sidebar.
- Everything in `app_config` listed in `workspace::spec::CONFIG_COLUMNS` is
  shared, including the logo, the sensitive-area PIN and the offline-sales
  setting.

**Polling, sized for shared hosting.**
- A counter waits 2 seconds between rounds while the business is busy (a
  write here, changes arriving, or a nudge in the last minute), then 5
  seconds, 15 seconds after 10 quiet minutes, and 30 seconds after an hour.
- A pull that finds nothing new and reports nothing new writes nothing on
  the server (the counter's *last seen* is refreshed at most every 30
  seconds).
- **Same-network nudge (desktop).** After a counter sends changes it
  broadcasts a small UDP datagram on port 47291. Counters of the same
  business on that network sync at once. The datagram carries no data, only
  a hash of the business id, so a lost or forged one only moves a sync
  earlier or later. Phones do not take part (broadcasts need extra
  permissions there); they poll. Windows may ask once whether Diwan may use
  the network: allowing it enables the nudge, refusing it only means
  polling.

**Status, shown in the top bar.**

| Shown | Meaning |
|---|---|
| *Saved centrally* | Nothing is waiting to sync. |
| *N waiting to sync* | Changes are queued and not yet acknowledged. |
| *Offline · N waiting* | The server can't be reached; changes stay queued. |
| *Sync problem* | Sync is failing. Settings shows the reason, the counters, and anything waiting. |

## 5. Stock and concurrent sales

- **A counter never sends a stock level.** Every change of `items.stock_qty`
  becomes a signed delta: a sale, refund, goods receipt, adjustment, stock
  take, or an edit in the item form.
- **Each delta has a key that is unique per business.** The server's
  `stock_ledger` has a unique `(business, key, item)`, so a replay is a
  no-op.
- **The balance is updated under a row lock**, so concurrent changes add up:
  100 − 5 − 3 = **92**.
- **The item form sends the stock it loaded.** Saving applies the difference
  typed, so a sale made while the form was open is not undone.

**An online sale** (`billing_create_sale`, `orders_pay`):

1. **Reserve (`pos-stock-reserve.php`).** The counter asks the server, with a
   4-second timeout, to take the cart's stock. All or nothing, under the
   lock.
2. **The answer decides what happens:**
   - **Refused:** the cashier sees "Not enough stock across the business:
     Cola has 2 left…". Nothing is recorded, and this counter's stock figures
     are refreshed.
   - **Taken:** the sale is recorded locally. Its own stock deltas are later
     pushed *under the reservation key*, which the ledger already holds for
     those items, so they are not applied twice.
   - **No answer (offline):** the sale is still recorded. Its deltas apply
     when the counter reconnects.
3. **A reservation whose sale then fails is released**, once. So is one
   orphaned by a crash: anything not used after 2 minutes is swept and
   released.

**Offline overselling** cannot be prevented, because no one can be asked. When
the delta arrives and the balance goes below zero:

- the balance stays negative and is shown as such on every counter;
- a `negative_stock` conflict is recorded for review in Admin → Businesses.

Central stock is never overwritten with a stale local figure.

## 6. Photos and the logo

A product photo or the logo set on one counter appears on every counter of
the business.

- **Shrunk when picked.** The counter stores a JPEG or PNG at most 1024
  pixels on its longer side (about 100–300 KB), named by a hash of its
  content (`item-<hash>.jpg`). The same photo has the same name on every
  counter.
- **Files travel beside the records.** Before pushing, a counter asks the
  server which of the files its records use are missing
  (`POST pos-media.php {names}`) and uploads those
  (`pos-media-upload.php?name=`, raw bytes). After pulling, it downloads the
  files its records name that it lacks (`GET pos-media.php?name=`). A file
  the server does not have yet is asked for again a minute later.
- **Stored privately.** Files live in
  `private-storage/media/<business id>/`, outside the web root, and are only
  ever sent to a counter of that business, as `application/octet-stream`
  with `nosniff`. `sync_media` indexes them.
- **Checked.** A raster image must decode as the type its name says, within
  4096 pixels, and is re-encoded with GD when the host has it. An SVG logo
  with script, entities or external references is refused. 3 MB per file,
  5,000 files per business.
- **Kept on the counter.** Photos show offline. A file no record uses any
  more is deleted from the counter a day later, and from the server after 7
  days by `scripts/media-gc.php` (§10).
- **Counters connected before this release** send their existing photo and
  logo names once, as a change of that field only, and upload the files.

## 7. Security

- **Staff PIN hashes travel sealed.** A counter encrypts each argon2 PIN
  hash (staff accounts and the sensitive-area PIN) with ChaCha20-Poly1305
  before sending it. The key is derived from the licence key with HKDF.
  Every counter of the business has the licence key; the server stores only
  its hash. A copy of the database or a backup therefore holds no PIN hash
  that can be attacked offline. Connecting a counter needs no extra step.
  - A value sealed with an earlier licence key (the key was replaced) cannot
    be opened. The counter keeps its own hash and sends it back sealed with
    the current key, so the business converges.
  - Counters connected before this release re-send their hashes sealed
    once. The admin panel shows whether a business's PINs arrive sealed.
- **The counter's token** is kept in the operating system's credential
  store: Windows Credential Manager, and the Keychain on iOS and on macOS
  release builds. The database then holds only a marker. Linux, Android and
  macOS debug builds keep it in the database.

## 8. Reports

- **Every counter holds every sale of the business**, so the existing
  reports on any counter are business-wide.
- **Reports → Counter Wise Sales** splits a range by counter and by cashier,
  and adds up to the Overview total.
- **On the server, `business_sales`** gives totals per business and per
  counter for the admin panel.
- **The customer dashboard's records view** reads the shared workspace once a
  business has data. Its sales list still comes from each counter's own
  sales.

**Closing the day.** Shifts → *Day across all drawers* lists every
counter's cash drawer opened that day, with expected cash, counted cash and
short/over, and the totals. A refund comes out of the drawer of the counter
it was recorded on.

**Receipts and kitchen tokens name the counter** once the business has
more than one: `Sale #C2-12` and `Counter: Takeaway`, `TOKEN #C2-14`. The
number is the creating counter's own, so a receipt reprinted on another
counter shows the same number. A single-counter business sees no change.

## 9. Admin panel

**Admin → Businesses & Counters** lists, for each business:

- owner, plan and licence state;
- counters in use against the limit, last seen;
- sales recorded on the server and products;
- what needs attention.

A business's page shows:

- each counter's state (online, idle or error), its version, queue size and
  last error;
- sales per counter;
- open sync conflicts, including fields edited on two counters at once;
- record counts, images stored and history;
- whether staff PINs arrive sealed.

**Server load** (top of Businesses & Counters): sync requests this hour, in
the last 24 hours by endpoint, and the busiest hour as requests per second,
with a guide: below 5/s is comfortable for shared hosting, 5–15/s is busy,
above 15/s means moving to Business or Cloud hosting (no code change is
needed for that).

**Actions** (all audited, with a CSRF token):

- rename a counter;
- **disconnect** a counter, which stops its sync and frees its device slot.
  Its records stay;
- mark conflicts reviewed.

**Privacy.** The panel never shows record contents, staff accounts or PIN
hashes.

## 10. Deploying

1. **Apply the migrations before the code**, in order:
   - `017_business_workspaces_and_counter_sync.sql` creates the workspace
     tables and widens `audit_logs.actor_type`;
   - `018_media_merge_and_load.sql` adds `sync_media`, `sync_load`,
     `sync_rows.field_versions`, `businesses.pin_key_check`, and sets the
     ADVANCE and ALL-IN-ONE plans to 3 and 10 counters where they still have
     the seed value of 1.

   Both are idempotent and change no existing record otherwise.
2. **Deploy the site.** The endpoints are:
   - `pos-terminal-register.php`, `pos-workspace.php`
   - `pos-sync-push.php`, `pos-sync-pull.php`
   - `pos-stock-reserve.php`
   - `pos-media.php`, `pos-media-upload.php`
   - `admin-businesses.php`
3. **Add two cron jobs in hPanel → Advanced → Cron Jobs**, using the PHP
   path hPanel shows:
   - daily: `php <site>/scripts/media-gc.php`, deletes unused images;
   - nightly: `php <site>/scripts/db-backup.php`, writes
     `private-storage/backups/diwan-<date>.sql.gz` and keeps 14. Download
     one now and then: a backup on the same account does not survive the
     loss of the account.
4. **Check PHP's upload limits** (hPanel → PHP Configuration):
   `post_max_size` at least 8M. Photos are at most 3 MB, so the defaults
   usually suffice. GD should be enabled (it is by default on Hostinger).
5. **Release a POS build that contains `src-tauri/src/workspace/`.** Older
   builds keep working, sending whole records and unsealed PIN hashes.
6. **Device limits per plan.** Plans & Features sets the default (BASIC 1,
   ADVANCE 3, ALL-IN-ONE 10); Licenses → Device limit sets it per licence.

Nothing new goes in `.env`.

**Request limits.**
- 500 changes, 5,000 stock changes and 200 releases per push.
- 1,000 records per pull page.
- 16 MB per request.

- 3 MB per image, 5,000 images per business.

Shared hosting is enough: sync is plain HTTPS polling, with no WebSockets
or long-lived connections. Hostinger Premium's 100 GB and 400,000 files leave
room for about a thousand businesses with a few hundred photos each; the
load panel (§9) shows when request volume, not storage, calls for bigger
hosting.

## 11. Tests

```sh
php scripts/tests/run.php <test.env> workspace     # server: scenarios A–D, F, G, admin
bash scripts/http-check.sh <test.env>             # the same API over real HTTP
cd ../point-of-sale/src-tauri && cargo test workspace   # two real counter databases, stand-in server
# End to end: two counter databases, the real PHP server (4 workers), real MySQL:
DIWAN_LICENSE_API_BASE=http://127.0.0.1:8099/api DIWAN_LIVE_LICENSE_KEY=DIWAN-… \
  cargo test live_two_counters -- --ignored --nocapture
```

The live test:

- has two counters sell the same item **at the same moment**: 100 → 92 on
  both;
- races both for the **last unit**: exactly one succeeds;
- sets a product photo on one counter: the file arrives on the other;
- edits the price on one counter and the name on the other: both survive;
- oversells offline: the change is kept, applied once and flagged;
- reinstalls a counter: same business, same data.

## 12. Known limits

- **Not instant across networks.** Counters on the same network nudge each
  other; elsewhere a busy business sees another counter's change within
  about 2 seconds, a quiet one within 30. Stock is never affected: an online
  sale always asks the server first.
- **The same field edited on two counters at once:** the later change to
  reach the server wins everywhere. It is flagged in the admin panel.
- **An offline sale can oversell.** It is flagged, never hidden. A business
  that prefers no sale at all while offline can choose that in Settings →
  Business & counters (*When a counter can't reach the server*).
- **Staff PIN hashes are on the server, sealed** with a key derived from
  the licence key. Someone holding both the database and the licence key
  could still attack them offline, as they could on a counter.
- **The counter token stays in the database** on Linux, Android and macOS
  debug builds (no credential store is used there).
- **Shifts (cash drawers) are per counter**: a cashier's open shift is
  found only on the counter it was opened on. The day summary covers them
  all.
- **"Warehouse", "Branch" and multi-location stock** are not built. One
  business has one stock.
- **iOS / Android** use the same code path, but were only exercised in this
  change through the desktop build and the tests; the same-network nudge is
  desktop only.
