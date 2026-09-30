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
