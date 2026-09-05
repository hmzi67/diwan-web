-- =============================================================================
-- 001_customer_accounts_and_license_activation.sql
--
-- Adds: passwordless customer accounts, and one-time device activation for
-- licenses (separate from the existing entitlement `status`, which continues
-- to gate downloads unchanged).
--
-- Applied to production on: <fill in when run>
--
-- Idempotent: safe to re-run. New tables use IF NOT EXISTS. Column/constraint
-- additions are guarded via information_schema checks in a throwaway
-- procedure, because MySQL 5.7 has no `ADD COLUMN IF NOT EXISTS` (only
-- MariaDB does) and this schema supports both.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- customers — one row per email that has ever checked out or logged in.
-- No password column: login is passwordless (magic link via login_tokens).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(190)    NOT NULL,
  name        VARCHAR(160)    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- login_tokens — single-use magic links. Same shape as download_tokens:
-- only the hash is stored, so a DB leak alone cannot forge a session.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash   CHAR(64)        NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  issued_ip    VARCHAR(45)     NULL,
  used_at      DATETIME        NULL,
  expires_at   DATETIME        NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_login_tokens_hash (token_hash),
  KEY idx_login_tokens_expiry (expires_at),
  CONSTRAINT fk_login_tokens_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- license_activation_attempts — rolling window for rate-limiting
-- activate-license.php. Mirrors download_attempts, plus what was targeted,
-- so abuse of one specific key from rotating IPs is also visible.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS license_activation_attempts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip                  VARCHAR(45)     NOT NULL,
  license_key_prefix  VARCHAR(16)     NULL,
  result              ENUM('activated','reactivated','already_bound','invalid_key','rate_limited') NOT NULL,
  attempted_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activation_attempts_ip_time (ip, attempted_at),
  KEY idx_activation_attempts_prefix_time (license_key_prefix, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Guarded ALTERs: orders.customer_id, and the licenses activation columns.
-- -----------------------------------------------------------------------------
DELIMITER $$

CREATE PROCEDURE _migration_001()
BEGIN
  -- orders.customer_id -------------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'customer_id'
  ) THEN
    ALTER TABLE orders ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER product_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_customer'
  ) THEN
    ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id);
  END IF;

  -- licenses.customer_id -------------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'customer_id'
  ) THEN
    ALTER TABLE licenses ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER order_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND CONSTRAINT_NAME = 'fk_licenses_customer'
  ) THEN
    ALTER TABLE licenses ADD CONSTRAINT fk_licenses_customer FOREIGN KEY (customer_id) REFERENCES customers (id);
  END IF;

  -- licenses activation columns ------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'activation_status'
  ) THEN
    ALTER TABLE licenses
      ADD COLUMN activation_status ENUM('unused','activated') NOT NULL DEFAULT 'unused' AFTER status;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'activated_machine_hash'
  ) THEN
    ALTER TABLE licenses
      ADD COLUMN activated_machine_hash CHAR(64) NULL COMMENT 'sha256(machine id), never the raw fingerprint' AFTER activation_status;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'activated_machine_hint'
  ) THEN
    ALTER TABLE licenses
      ADD COLUMN activated_machine_hint VARCHAR(64) NULL COMMENT 'e.g. "Windows 11 x64", for admin display only' AFTER activated_machine_hash;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'activated_at'
  ) THEN
    ALTER TABLE licenses ADD COLUMN activated_at DATETIME NULL AFTER activated_machine_hint;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'last_reactivated_at'
  ) THEN
    ALTER TABLE licenses ADD COLUMN last_reactivated_at DATETIME NULL COMMENT 'throttles self-service device moves to once/30 days' AFTER activated_at;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'reactivated_count'
  ) THEN
    ALTER TABLE licenses ADD COLUMN reactivated_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_reactivated_at;
  END IF;

  -- Drop the old, unused, unhashed device-binding column now superseded by
  -- activated_machine_hash/activated_machine_hint above.
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'activated_machine'
  ) THEN
    ALTER TABLE licenses DROP COLUMN activated_machine;
  END IF;

  -- Helpful for the admin lookup page and customer dashboard.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND INDEX_NAME = 'idx_licenses_activation_status'
  ) THEN
    ALTER TABLE licenses ADD INDEX idx_licenses_activation_status (activation_status);
  END IF;
END$$

DELIMITER ;

CALL _migration_001();
DROP PROCEDURE _migration_001;

-- -----------------------------------------------------------------------------
-- Backfill: create customer rows for every email seen in orders/licenses so
-- far, and link existing orders/licenses to them. Idempotent — re-running
-- inserts nothing new and re-applies the same (already-correct) links.
-- -----------------------------------------------------------------------------
INSERT INTO customers (email, created_at)
SELECT DISTINCT customer_email, NOW() FROM orders
ON DUPLICATE KEY UPDATE email = VALUES(email);

INSERT INTO customers (email, created_at)
SELECT DISTINCT customer_email, NOW() FROM licenses
ON DUPLICATE KEY UPDATE email = VALUES(email);

UPDATE orders o
  JOIN customers c ON c.email = o.customer_email
   SET o.customer_id = c.id
 WHERE o.customer_id IS NULL;

UPDATE licenses l
  JOIN customers c ON c.email = l.customer_email
   SET l.customer_id = c.id
 WHERE l.customer_id IS NULL;
