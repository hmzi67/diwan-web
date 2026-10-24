# Migrations

`schema.sql` is the full, current shape of the database and is safe to re-run
(everything is `CREATE TABLE IF NOT EXISTS`). Once the site is live, do not edit
it destructively — add a numbered migration here instead and apply it via
phpMyAdmin or the cPanel MySQL console, then fold the change into `schema.sql`.

Naming: `NNN_short_description.sql`, e.g. `001_add_refund_columns.sql`.
Each file must be idempotent and start with a comment stating what it changes
and the date it was applied to production.

**Migrations are never run by the deploy pipeline.** FTP has no shell, and an
automated schema change with no rollback path is exactly the kind of thing that
takes a payment system down at 2am. Apply them by hand, before the deploy that
needs them.

## 007 — Free trials

Apply `007_free_trial.sql` before deploying trial onboarding. See
[FREE-TRIAL.md](../../docs/FREE-TRIAL.md) for flags, boundaries and upgrade guidance.
Use the updated migration 005 if repeating the old paid-expiry repair.

## 008 — App Store purchases and Sign in with Apple

Apply `008_apple_iap_and_sign_in.sql` before deploying the `apple-*.php`
endpoints and `delete-account.php`. See [APPLE.md](../../docs/APPLE.md) for the
App Store Connect / developer-portal setup and the `.env` values they need.

## 009 — Contact form

Apply `009_contact_messages.sql` before deploying `contact.php`. Set
`CONTACT_EMAIL` in `.env` to the inbox that should receive enquiries.

## 010 — Installers served from GitHub releases

Apply `010_github_release_source.sql` before deploying the GitHub download
source. Then add the Actions **secret** `POS_RELEASES_TOKEN` (fine-grained
PAT, the installer repo only, Contents: read-only) and optionally the variable
`POS_RELEASES_REPO` — GitHub reserves the `GITHUB_` prefix, so deploy.yml maps
these onto `GITHUB_RELEASES_TOKEN` / `GITHUB_RELEASES_REPO` in the server
.env. Then register each release:

    php scripts/sync-github-release.php v1.1.5 --sql > release.sql   # paste into phpMyAdmin

The host has no shell, so run the script locally with a `.env` holding the
token; `--sql` needs no database connection.

## 011 — Email verification (and magic links removed)

Apply `011_email_verification_and_drop_magic_links.sql` before deploying the
login / signup / verify-email pages. It backfills every existing account as
verified and drops `login_tokens`. (Written as 007 on its own branch and
renumbered when merged; 007 was already `007_free_trial.sql`.)

## 012 — Linux downloads and Windows 32-bit

Apply `012_linux_platform.sql` before deploying the dashboard's Linux option,
then re-run `scripts/sync-github-release.php <tag> --sql` and import its output:
it now registers Linux (`appimage` / `deb` / `rpm`) and both Windows builds
(`x64` / `x86`).

## 015 — POS tender breakdown and record privacy

Apply `015_pos_tenders_and_record_privacy.sql` alongside the POS release that
adds split payments and pay-later sales. It adds the per-sale tender columns
to `pos_sales` (older rows stay NULL and keep being read through
`payment_method`) and removes the area-PIN hash from `app_config` records that
older POS builds mirrored. `SyncService` also adds the columns itself if a
deploy lands first, and drops any `*_hash` field on ingest from now on.

## 016 — Plans, licence requests and the admin panel

Apply `016_plans_admin_and_license_requests.sql` before deploying the plan
pricing, the request flow and `/admin/`. It adds `plans`, `features`,
`plan_features` (seeded with BASIC / ADVANCE / ALL-IN-ONE), `license_requests`,
`license_activations` (backfilled with each licence's existing device),
`admin_users`, `admin_login_attempts`, `audit_logs`, `notifications`,
`download_events` and `admin_settings`; gives `licenses` a plan, a device
limit and a `suspended` status, and `customers` an account status. Existing
licences keep `plan_id` NULL, which the POS treats as unrestricted. Seeds use
`INSERT IGNORE`, so a re-run never overwrites edits made in the admin panel.
Then create the first administrator with `php scripts/create-admin.php` — see
`docs/PLANS-AND-ADMIN.md`.

## 017 — One business, many counters

Apply `017_business_workspaces_and_counter_sync.sql` before deploying the
`pos-*.php` sync endpoints and `admin-businesses.php`. It only creates tables
(and adds `terminal` to `audit_logs.actor_type`). See
[MULTI-COUNTER.md](../../docs/MULTI-COUNTER.md).


## 018 — Shared photos, field merge, server load

Apply `018_media_merge_and_load.sql` before deploying `pos-media.php`,
`pos-media-upload.php` and the updated sync endpoints. It adds `sync_media`
(the index of each business's photos and logo, stored in
`private-storage/media/`), `sync_load` (requests per hour, for the admin
panel), `sync_rows.field_versions` (per-field merge) and
`businesses.pin_key_check`, and sets the ADVANCE and ALL-IN-ONE plans to 3
and 10 counters where they still have the seed value of 1. Then add the two
cron jobs in [MULTI-COUNTER.md](../../docs/MULTI-COUNTER.md) §10
(`scripts/media-gc.php`, `scripts/db-backup.php`).
