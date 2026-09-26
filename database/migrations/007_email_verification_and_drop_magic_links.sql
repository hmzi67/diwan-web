-- =============================================================================
-- 007_email_verification_and_drop_magic_links.sql
--
-- Two changes, both part of moving to the five-page auth flow
-- (login, signup, forgot-password, reset-password, verify-email):
--
--   1. Email verification. Signup now requires proving control of the mailbox
--      before a session is ever issued — the licence key is delivered by
--      email, so an unverified address is a purchase that cannot complete.
--      customers.email_verified_at is NULL for accounts created from now on;
--      every account that already exists is backfilled as verified below.
--
--   2. The magic-link flow is gone (LoginService, send-login-link.php,
--      verify-login.php removed in the same pass). login_tokens goes with it.
--
-- Applied to production on: <fill in when run>
-- Idempotent: guarded via information_schema, same pattern as 001/002/004.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- email_verification_tokens — single-use verification links, hash-only like
-- password_reset_tokens: the raw token exists only in the email, so a database
-- leak alone cannot mint a working link.
--
-- 24 hours rather than the reset flow's 60 minutes: signup often happens on a
-- phone at the counter and the email arrives between other tasks, and an
-- expired link that forces a full resend is the most common drop-off point.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_verification_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash   CHAR(64)        NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  issued_ip    VARCHAR(45)     NULL,
  used_at      DATETIME        NULL,
  expires_at   DATETIME        NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_verification_tokens_hash (token_hash),
  KEY idx_email_verification_tokens_expiry (expires_at),
  KEY idx_email_verification_tokens_customer (customer_id),
  CONSTRAINT fk_email_verification_tokens_customer
    FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

CREATE PROCEDURE _migration_007()
BEGIN
  -- -----------------------------------------------------------------------------
  -- customers.email_verified_at — NULL means "not yet verified", which is the
  -- state every account created by signup.php will be in until the emailed
  -- link is clicked.
  --
  -- The backfill runs inside this guard so it fires exactly once, when the
  -- column is added. Every account present at migration time has been
  -- receiving licence emails at this address (that is how the product is
  -- delivered), so treating them as verified is both true and the only
  -- choice that does not lock out paying customers. New rows are created
  -- with NULL by register.php.
  -- -----------------------------------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'email_verified_at'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN email_verified_at DATETIME NULL
        COMMENT 'When the owner proved control of the mailbox. NULL = signup pending verification.'
        AFTER session_epoch;

    UPDATE customers SET email_verified_at = created_at WHERE email_verified_at IS NULL;
  END IF;

  -- -----------------------------------------------------------------------------
  -- login_attempts gains 'register': signup is rate-limited per IP using this
  -- same table (register.php), and rate-limiting it needs its own counter that
  -- does not feed the sign-in windows — isRateLimited() counts only
  -- bad_password/unknown_email, so 'register' rows are invisible to it.
  -- -----------------------------------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'result'
       AND COLUMN_TYPE LIKE '%register%'
  ) THEN
    ALTER TABLE login_attempts
      MODIFY COLUMN result ENUM('ok','bad_password','unknown_email','rate_limited','no_password_set','register') NOT NULL;
  END IF;
END$$

DELIMITER ;

CALL _migration_007();
DROP PROCEDURE _migration_007;

-- -----------------------------------------------------------------------------
-- Drop the magic-link token table. The flow that minted these
-- (send-login-link.php / verify-login.php / LoginService) no longer exists;
-- password auth plus the verification link are the whole system now.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS login_tokens;
