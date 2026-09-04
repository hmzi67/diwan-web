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
