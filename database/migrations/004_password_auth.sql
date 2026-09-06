-- =============================================================================
-- 004_password_auth.sql
--
-- Adds password-based authentication alongside the existing passwordless
-- magic-link flow. The magic-link tables (login_tokens) are deliberately left
-- untouched and functional: they stay as a rollback path until password auth
-- has proven itself, and are removed in a separate, later migration.
--
-- Nothing here rewrites existing data. customers.password_hash is NULLable on
-- purpose — NULL means "account predates password auth, has never set one",
-- which is what routes those customers into the set-a-password flow instead of
-- locking them out. No backfill is required: migration 001 already created a
-- customers row per distinct email in orders/licenses and linked both tables.
--
-- Applied to production on: <fill in when run>
-- Idempotent: guarded via information_schema, same pattern as 001 and 002.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- password_reset_tokens — single-use reset links. Hash-only, exactly like
-- login_tokens and download_tokens: the raw token exists only in the email, so
-- a database leak alone cannot mint a working reset link.
--
-- Window is 1 hour rather than login_tokens' 20 minutes: resetting also
-- requires composing and confirming a new password, and 20 minutes routinely
-- expires mid-task for someone doing this on a phone.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash   CHAR(64)        NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  issued_ip    VARCHAR(45)     NULL,
  used_at      DATETIME        NULL,
  expires_at   DATETIME        NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_tokens_hash (token_hash),
  KEY idx_password_reset_tokens_expiry (expires_at),
  KEY idx_password_reset_tokens_customer (customer_id),
  CONSTRAINT fk_password_reset_tokens_customer
    FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- login_attempts — rolling window for rate-limiting sign-in. Mirrors
-- download_attempts / license_activation_attempts.
--
-- This is genuinely new exposure: guessing a password is feasible in a way
-- guessing a 256-bit magic-link token never was. Two windows are tracked, so
-- both "one IP hammering many accounts" and "many IPs hammering one account"
-- are visible.
--
-- The email is stored HASHED, never in clear: this table would otherwise
-- become a log of which addresses tried to sign in and when.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)     NOT NULL,
  email_hash   CHAR(64)        NULL COMMENT 'hash_hmac(sha256, lowercased email, APP_KEY)',
  result       ENUM('ok','bad_password','unknown_email','rate_limited','no_password_set') NOT NULL,
  attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_ip_time (ip, attempted_at),
  KEY idx_login_attempts_email_time (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Guarded ALTERs: the password columns on customers.
-- -----------------------------------------------------------------------------
DELIMITER $$

CREATE PROCEDURE _migration_004()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'password_hash'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN password_hash VARCHAR(255) NULL
        COMMENT 'password_hash() output. NULL = predates password auth, must set one via the reset flow.'
        AFTER name;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'password_set_at'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN password_set_at DATETIME NULL
        COMMENT 'When the current password was set. Support/audit only.'
        AFTER password_hash;
  END IF;

  -- Lets a password reset actually revoke sessions that already exist.
  --
  -- Sessions are stateless signed cookies (Diwan\Auth\Session): there is no
  -- session table to delete from, so before this column a cookie minted
  -- BEFORE a reset stayed valid after it — precisely the session someone
  -- resetting a compromised account is trying to kill. The epoch is signed
  -- into the cookie and checked on every request, so bumping it here
  -- invalidates every cookie issued earlier, on every device.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'session_epoch'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Bumped on password reset; signed into the session cookie to revoke older ones.'
        AFTER password_set_at;
  END IF;
END$$

DELIMITER ;

CALL _migration_004();
DROP PROCEDURE _migration_004;
