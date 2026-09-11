-- =============================================================================
-- 006_license_status_audit_results.sql
--
-- The desktop app now refreshes a signed licence status from
-- api/license-status.php on every online launch. That endpoint shares
-- activate-license.php's rate-limit window and audit table
-- (license_activation_attempts) via Diwan\License\ActivationThrottle, so its
-- outcomes need to fit the `result` ENUM — otherwise the audit INSERT fails
-- under strict SQL mode and every status refresh 500s.
--
-- New values (all prefixed status_ so an audit query can tell the two
-- endpoints apart):
--   status_refused        wrong key / wrong device — the generic 403
--   status_not_activated  key exists, never activated (409)
--   status_active         signed payload issued, licence entitled
--   status_revoked        signed payload issued, licence revoked
--   status_expired        signed payload issued, licence past expiry
--
-- Existing values are kept in place and in order; MODIFY on an ENUM that only
-- APPENDS members rewrites no rows. Safe to re-run.
--
-- ORDER OF OPERATIONS — run this BEFORE deploying the code that adds
-- license-status.php.
--
-- Applied to production on: <fill in when run>
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE license_activation_attempts
  MODIFY result ENUM(
    'activated', 'reactivated', 'already_bound', 'invalid_key', 'rate_limited',
    'status_refused', 'status_not_activated', 'status_active', 'status_revoked', 'status_expired'
  ) NOT NULL;
