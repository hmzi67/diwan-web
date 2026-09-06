-- =============================================================================
-- 005 — Repair licences wrongly given a 30-day expiry.
--
-- THE BUG
-- LicenseService::issueForOrder() set `licenses.expires_at` from
-- DOWNLOAD_TOKEN_TTL_DAYS (default 30). That setting was meant for download
-- links, not licences. The result: every licence ever issued expired 30 days
-- after purchase, even though the product is sold as a one-time, non-expiring
-- licence.
--
-- Both read paths enforce it, so an affected customer is fully locked out:
--   LicenseService::findActive()  — `expires_at IS NULL OR expires_at > NOW()`
--   LicenseService::activate()    — same condition, via the `entitled` column
--   DownloadService               — checks license_expires_at separately
--
-- THE FIX
-- Code now reads LICENSE_VALIDITY_DAYS, defaulting to 0 = never expires =
-- expires_at NULL. This migration retro-fits existing rows to match.
--
-- ORDER OF OPERATIONS — deploy the code FIRST, then run this. If you run this
-- first, any licence issued in between gets a fresh 30-day expiry and you have
-- to run it again.
--
-- Safe to re-run: every statement is idempotent.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- STEP 1 — DIAGNOSTIC. Run this BEFORE anything else and keep the output.
-- Nothing here changes data.
-- -----------------------------------------------------------------------------

-- 1a. How many licences are affected, and how many customers are locked out
--     RIGHT NOW versus merely carrying a wrong future expiry date?
SELECT
  COUNT(*)                                                    AS total_licences,
  SUM(expires_at IS NOT NULL)                                 AS with_an_expiry,
  SUM(expires_at IS NOT NULL AND expires_at <= NOW())         AS already_expired_locked_out,
  SUM(expires_at IS NOT NULL AND expires_at >  NOW())         AS will_expire_but_still_working,
  SUM(expires_at IS NOT NULL AND expires_at <= NOW()
      AND activation_status = 'activated')                    AS locked_out_and_already_installed
FROM licenses;

-- 1b. WHO is affected. This is the list to contact.
--     `days_dead` = how long they have already been locked out.
SELECT
  l.id                AS license_id,
  l.customer_email,
  l.license_key_prefix,
  o.order_ref,
  o.amount_paisa / 100 AS amount_pkr,
  l.status,
  l.activation_status,
  l.activated_machine_hint,
  l.created_at        AS purchased_at,
  l.expires_at,
  DATEDIFF(NOW(), l.expires_at) AS days_dead
FROM licenses l
JOIN orders o ON o.id = l.order_id
WHERE l.expires_at IS NOT NULL
  AND l.expires_at <= NOW()
ORDER BY l.expires_at ASC;

-- 1c. Sanity check: are there any licences whose expiry was NOT set by this
--     bug? A deliberately time-limited licence would not be exactly 30 days
--     (or whatever DOWNLOAD_TOKEN_TTL_DAYS was set to) after creation.
--     Anything listed here needs a human decision before step 2.
SELECT
  id, customer_email, created_at, expires_at,
  DATEDIFF(expires_at, created_at) AS validity_days_granted
FROM licenses
WHERE expires_at IS NOT NULL
  AND DATEDIFF(expires_at, created_at) NOT IN (30)
ORDER BY created_at;


-- -----------------------------------------------------------------------------
-- STEP 2 — THE REPAIR.
--
-- Clears the wrongly-set expiry so these licences become perpetual, matching
-- what the customer was actually sold.
--
-- Scope is deliberately narrow: only rows whose expiry is exactly the buggy
-- 30-day window from creation. A licence deliberately time-limited to some
-- other period is left alone (review step 1c first).
--
-- Revoked licences are also left alone — a revoked licence should stay dead.
-- -----------------------------------------------------------------------------

UPDATE licenses
   SET expires_at = NULL
 WHERE expires_at IS NOT NULL
   AND DATEDIFF(expires_at, created_at) = 30
   AND status <> 'revoked';


-- -----------------------------------------------------------------------------
-- STEP 3 — VERIFY. Should return zero rows.
-- -----------------------------------------------------------------------------

SELECT id, customer_email, created_at, expires_at
FROM licenses
WHERE expires_at IS NOT NULL
  AND expires_at <= NOW()
  AND status <> 'revoked';


-- -----------------------------------------------------------------------------
-- STEP 4 — AFTER RUNNING
--
-- 1. Set LICENSE_VALIDITY_DAYS=0 in the server .env (and as a GitHub Variable),
--    so newly issued licences are perpetual.
-- 2. DOWNLOAD_TOKEN_TTL_DAYS is now unused by any code. Remove it from .env and
--    from the deploy workflow so nobody re-wires it to something by mistake.
-- 3. Email every customer from the 1b list. They were locked out of software
--    they paid for; they should hear it from you rather than discover it works
--    again by chance. Anyone who asked for support about it and was told
--    nothing could be done needs a personal reply.
-- =============================================================================
