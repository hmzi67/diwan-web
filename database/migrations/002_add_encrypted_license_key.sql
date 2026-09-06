-- =============================================================================
-- 002_add_encrypted_license_key.sql
--
-- Adds a REVERSIBLE, encrypted copy of the license key alongside the existing
-- one-way hash, so the customer dashboard can offer "resend my key" without
-- minting a new one. This is a deliberate trade-off, not an oversight:
--
--   Before: license_key_hash only. A DB leak alone reveals nothing usable —
--           HMAC cannot be reversed even if APP_KEY also leaks.
--   After:  license_key_encrypted (AES-256-GCM, key derived from APP_KEY) is
--           reversible. A DB leak *combined with* an APP_KEY leak now exposes
--           real license keys directly, instead of requiring an infeasible
--           brute force of the key's ~2^64 keyspace.
--
-- license_key_hash remains the sole source of truth for every lookup
-- (findActive(), activate()) — license_key_encrypted is read ONLY by the
-- dashboard's resend-license-email.php, never used for authentication.
--
-- Applied to production on: <fill in when run>
-- Idempotent: safe to re-run (guarded via information_schema, same pattern as 001).
-- =============================================================================

SET NAMES utf8mb4;

DELIMITER $$

CREATE PROCEDURE _migration_002()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'license_key_encrypted'
  ) THEN
    ALTER TABLE licenses
      ADD COLUMN license_key_encrypted VARCHAR(255) NULL
        COMMENT 'AES-256-GCM(key), base64(nonce . tag . ciphertext), derived from APP_KEY. Existing rows are NULL until reissued.'
        AFTER license_key_hash;
  END IF;
END$$

DELIMITER ;

CALL _migration_002();
DROP PROCEDURE _migration_002;

-- NOTE: this does NOT backfill existing licenses (the plaintext of already-
-- issued keys was never stored, so there is nothing to encrypt retroactively).
-- "Resend" only works for licenses issued after this migration ships. Older
-- customers still fall back to support-assisted reissue.
