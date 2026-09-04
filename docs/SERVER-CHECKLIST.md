# Server checklist — verify by hand before trusting the pipeline

Work through this once, on the real host, before the first production deploy.
Every item is something the pipeline **cannot** check for you.

---

## 1. Confirm where FTP actually lands

This is the single assumption everything else rests on. Connect with FileZilla
(or `lftp`) and look at the directory you land in.

- [ ] The landing directory contains `public_html/` (or `www/`, `htdocs/`) as a
      **child**, alongside things like `mail/`, `logs/`, `tmp/`.
- [ ] It does **not** contain `index.html` at its top level. If it does, your FTP
      root *is* the web root — stop and read §7 before continuing.

Then set the GitHub variables to match what you see:

```
WEB_ROOT = /public_html          # or ./public_html — must match the FTP listing
APP_ROOT = /app
```

> Some hosts chroot FTP so `/` *is* your home directory; others hand you an
> absolute path like `/home/diwan1/`. Use whichever form the FTP client shows,
> and confirm with a `dry_run` deploy before a real one — the dry run prints the
> paths it resolves.

## 2. Create the directory layout

```
~/
├── public_html/          existing document root for diwan.com
├── staging/              document root for staging.diwan.com (create the subdomain first)
├── app/
│   ├── src/              (deploy fills this)
│   ├── config/           (deploy writes .env here)
│   └── private-storage/
│       ├── releases/
│       └── logs/
└── app-staging/          same three subdirectories
```

- [ ] `~/app` and `~/app-staging` created, **as siblings of `public_html`, not inside it**.
- [ ] `private-storage/releases/` and `private-storage/logs/` exist in both.
- [ ] Confirm in cPanel → Domains that no addon domain or subdomain has its
      document root set to `~/app` or anything above `public_html`. A subdomain
      pointed at `~` would publish your entire home directory.

## 3. Prove private-storage is unreachable

Put a canary file in place, then try to fetch it:

```bash
# upload a file containing the word CANARY to ~/app/private-storage/releases/canary.txt
curl -i https://diwan.com/private-storage/releases/canary.txt      # expect 404
curl -i https://diwan.com/app/private-storage/releases/canary.txt  # expect 404
curl -i https://diwan.com/../app/private-storage/releases/canary.txt
```

- [ ] All return 404/403 and **no** file contents.
- [ ] Then run the full probe: `bash scripts/verify-private-storage.sh https://diwan.com`
- [ ] Delete the canary file afterwards.

## 4. Confirm `.htaccess` is honoured at all

Shared hosts sometimes ship `AllowOverride None`, which silently disables every
`.htaccess` rule — including the ones this project relies on as its second line
of defence.

```bash
curl -i https://diwan.com/api/_bootstrap.php      # expect 403
curl -i https://diwan.com/build.json              # expect 403
```

- [ ] Both are blocked. If `_bootstrap.php` returns 500 or a blank 200 instead of
      403, `.htaccess` is being ignored — open a support ticket asking for
      `AllowOverride All` on your document root, and treat directory placement
      (§2) as your *only* protection until it is fixed.
- [ ] `mod_rewrite` and `mod_headers` are enabled (the HTTPS redirect and the
      security headers both depend on them). Check response headers for
      `X-Content-Type-Options: nosniff`.

## 5. Permissions

| Path | Mode | Why |
|---|---|---|
| `~/app` and everything under it | `750` dirs | Owner + web server group only |
| `~/app/config/.env` | `600` | Nothing but PHP ever reads it |
| `~/app/private-storage/releases/*` | `640` | Readable by the web server, not writable |
| `~/app/private-storage/logs/` | `750`, **writable** by PHP | `Logger` appends here |
| `~/public_html` and contents | `755` dirs / `644` files | Standard |

- [ ] No file anywhere is `777`. On most shared hosts `777` on a directory
      containing PHP makes it refuse to execute (suEXEC/suPHP), and it is a
      standing invitation besides.
- [ ] Confirm PHP can actually write to `private-storage/logs/`: hit
      `/api/health.php`, then check that a log file appeared.

## 6. PHP and MySQL

- [ ] `php -v` (cPanel → MultiPHP Manager, or a temporary `phpinfo()` page you
      delete immediately) reports **8.1 or newer**. The code uses `str_starts_with`,
      `match`, enums in type hints, `never` return types and constructor promotion
      — it will fatal on 7.x.
- [ ] Update `php-version` in `.github/workflows/deploy.yml` to match the server
      exactly, so CI lints against what actually runs.
- [ ] Extensions present: `pdo_mysql`, `openssl`, `curl`, `mbstring`, `json`.
- [ ] `display_errors = Off` in the production PHP config (the app forces this,
      but a host-level `display_errors = On` during a fatal *before* bootstrap
      can still leak paths).
- [ ] `allow_url_fopen` and `register_globals` — off.
- [ ] MySQL user has only `SELECT, INSERT, UPDATE, DELETE` on its own database.
      **No `DROP`, no `GRANT`, no access to other databases**, and it is not `root`.
- [ ] Separate database and separate MySQL user for staging. Staging must never
      be able to read production orders.
- [ ] `schema.sql` imported into both databases; `SHOW TABLES` lists all seven.

## 7. If your FTP account lands directly in `public_html`

Then there is no directory above the web root to hide things in, and you must
either fix it or fall back to `.htaccess`-only protection.

**Preferred:** ask your host to move the FTP account's home one level up, or
create a *second* FTP account whose home is the parent. Most cPanel hosts will
do this on request, and it is worth asking.

**Fallback**, if they will not:

```
WEB_ROOT = /
APP_ROOT = /_diwan_app
```

`_diwan_app/` then lives inside the web root, protected only by the `.htaccess`
files this repo ships in `backend/src/` and `backend/private-storage/`. If you
go this route:

- [ ] §4 must pass — `.htaccess` being ignored now means total exposure, not
      just a weakened second layer.
- [ ] Re-run `scripts/verify-private-storage.sh` after **every** deploy, not just
      the first, and after any host migration or control-panel change.
- [ ] Add `**/_diwan_app/**` to the `exclude:` list on the web-root FTP step in
      `deploy.yml`, or the site deploy will try to delete your app directory.

## 8. Payment gateway

- [ ] The webhook URL registered in the gateway dashboard is
      `https://diwan.com/api/payment-webhook.php`, over **HTTPS**, and the
      staging gateway account points at the staging URL.
- [ ] Sandbox credentials in the staging environment, live credentials in
      production. Verify you did not paste live keys into staging.
- [ ] Complete one sandbox purchase end to end on staging: order created →
      webhook received → row in `webhook_events` → order `paid` → licence issued
      → email received → download works → **second download attempt with the same
      token is refused**.
- [ ] Replay the same webhook payload twice (curl it) and confirm the second
      returns `{"status":"duplicate"}` and does **not** create a second licence.
- [ ] Send a webhook with a tampered `pp_Amount` and confirm it is rejected with
      400 and logged.

## 9. TLS and email

- [ ] Valid certificate on `diwan.com`, `www.diwan.com` and `staging.diwan.com`.
- [ ] HTTP redirects to HTTPS (the shipped `.htaccess` does this; confirm it
      works behind your host's proxy — some need the `X-Forwarded-Proto` variant,
      which is already included).
- [ ] Only after confirming HTTPS everywhere, uncomment the HSTS header in
      `frontend/src/.htaccess`. Enabling it early with a broken certificate locks
      visitors out.
- [ ] SPF and DKIM records exist for the domain, or licence emails sent by PHP
      `mail()` will land in spam and your customers will not get their keys.
      Consider SMTP relay instead of `mail()` before launch.

## 10. Backups

- [ ] Automated nightly MySQL dump configured (cPanel → Backup, or a cron job).
      The `licenses` table is not reproducible — losing it means every customer
      loses their purchase.
- [ ] `~/app/private-storage/releases/` is backed up somewhere off the host. It
      is deliberately not in git, which means git will not save you.
- [ ] Verify you can actually restore a dump into a scratch database. An
      untested backup is not a backup.

---

## Quick verification run

```bash
bash scripts/smoke-test.sh https://staging.diwan.com
bash scripts/verify-private-storage.sh https://staging.diwan.com
curl -s https://staging.diwan.com/api/health.php | jq
```

All three clean → the pipeline is safe to trust for that environment.
