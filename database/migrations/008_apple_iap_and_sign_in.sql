-- =============================================================================
-- 008_apple_iap_and_sign_in.sql
--
-- App Store in-app purchases and Sign in with Apple. Apply BEFORE deploying
-- the apple-*.php endpoints and delete-account.php.
--
--   customers.apple_sub                      stable Apple ID subject — the only
--                                            thing an Apple sign-in is matched by
--   customers.apple_refresh_token_encrypted  AES-GCM, kept only so account
--   customers.apple_token_client_id          deletion can revoke it at Apple
--   customers.app_account_token              StoreKit appAccountToken (UUID)
--   customers.deleted_at                     set by delete-account.php
--   apple_transactions                       one row per App Store purchase,
--                                            keyed by originalTransactionId
--
-- App Store orders reuse `orders`/`licenses` as-is: gateway = "apple",
-- status = "paid", gateway_txn_id = originalTransactionId. A purchase made
-- without an account has customer_id NULL and customer_email '' (App Review
-- forbids requiring registration before a non-account purchase).
--
-- Nothing is rewritten. Applied to production on: <fill in when run>
-- Idempotent: guarded via information_schema, same pattern as 004.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS apple_transactions (
  original_transaction_id VARCHAR(64)     NOT NULL,
  order_id                BIGINT UNSIGNED NOT NULL,
  product_id              VARCHAR(190)    NOT NULL COMMENT 'App Store product id, e.g. com.diwan.pos.license.starter',
  environment             VARCHAR(16)     NOT NULL COMMENT 'Production | Sandbox (Sandbox includes App Review)',
  latest_transaction_id   VARCHAR(64)     NOT NULL,
  app_account_token       CHAR(36)        NULL,
  revoked_at              DATETIME        NULL COMMENT 'Set by a REFUND/REVOKE notification',
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NULL,
  PRIMARY KEY (original_transaction_id),
  UNIQUE KEY uq_apple_transactions_order (order_id),
  CONSTRAINT fk_apple_transactions_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

CREATE PROCEDURE _migration_008()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'apple_sub'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN apple_sub VARCHAR(255) NULL
        COMMENT 'Sign in with Apple subject. Stable per Apple ID + team.' AFTER session_epoch,
      ADD UNIQUE KEY uq_customers_apple_sub (apple_sub);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'apple_refresh_token_encrypted'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN apple_refresh_token_encrypted VARCHAR(1024) NULL
        COMMENT 'AES-256-GCM; only used to revoke at Apple on account deletion' AFTER apple_sub,
      ADD COLUMN apple_token_client_id VARCHAR(190) NULL
        COMMENT 'Bundle id or Services ID the refresh token was issued to' AFTER apple_refresh_token_encrypted;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'app_account_token'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN app_account_token CHAR(36) NULL
        COMMENT 'StoreKit appAccountToken (UUID) for purchases made while signed in' AFTER apple_token_client_id,
      ADD UNIQUE KEY uq_customers_app_account_token (app_account_token);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'deleted_at'
  ) THEN
    ALTER TABLE customers
      ADD COLUMN deleted_at DATETIME NULL
        COMMENT 'Account deleted by its owner; personal data already scrubbed' AFTER app_account_token;
  END IF;
END$$

DELIMITER ;

CALL _migration_008();
DROP PROCEDURE _migration_008;
