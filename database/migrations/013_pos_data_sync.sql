-- =============================================================================
-- 013_pos_data_sync.sql
--
-- Adds tables to store synced POS terminal data (devices, transactions, and daily
-- performance summaries) associated with customers by user ID / license.
-- Backs pos-sync.php and the customer dashboard's Store Records view.
--
-- Idempotent: safe to run multiple times.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- pos_devices — POS terminals registered to customers via license activation.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pos_devices (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id         BIGINT UNSIGNED NOT NULL,
  license_id          BIGINT UNSIGNED NULL,
  device_fingerprint  VARCHAR(64)     NOT NULL,
  device_name         VARCHAR(100)    NULL,
  last_sync_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_devices_customer_fingerprint (customer_id, device_fingerprint),
  CONSTRAINT fk_pos_devices_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_devices_license FOREIGN KEY (license_id) REFERENCES licenses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pos_sales — itemized transactions synced from POS terminals.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pos_sales (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id         BIGINT UNSIGNED NOT NULL,
  device_fingerprint  VARCHAR(64)     NOT NULL,
  local_sale_id       BIGINT          NOT NULL,
  order_no            INT             NULL,
  table_name          VARCHAR(100)    NULL,
  cashier_name        VARCHAR(100)    NULL,
  total_minor         BIGINT          NOT NULL,
  discount_minor      BIGINT          NOT NULL DEFAULT 0,
  tax_minor           BIGINT          NOT NULL DEFAULT 0,
  payment_method      VARCHAR(20)     NOT NULL DEFAULT 'cash',
  currency            VARCHAR(10)     NOT NULL DEFAULT 'PKR',
  items_count         INT             NOT NULL DEFAULT 1,
  items_summary       TEXT            NULL,
  sale_created_at     DATETIME        NOT NULL,
  synced_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_sales_customer_device_sale (customer_id, device_fingerprint, local_sale_id),
  KEY idx_pos_sales_customer_created (customer_id, sale_created_at),
  CONSTRAINT fk_pos_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pos_daily_summaries — daily aggregated performance metrics for charts & KPIs.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pos_daily_summaries (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id         BIGINT UNSIGNED NOT NULL,
  summary_date        DATE            NOT NULL,
  total_sales_minor   BIGINT          NOT NULL DEFAULT 0,
  refunds_minor       BIGINT          NOT NULL DEFAULT 0,
  net_sales_minor     BIGINT          NOT NULL DEFAULT 0,
  transaction_count   INT             NOT NULL DEFAULT 0,
  cash_minor          BIGINT          NOT NULL DEFAULT 0,
  card_minor          BIGINT          NOT NULL DEFAULT 0,
  other_minor         BIGINT          NOT NULL DEFAULT 0,
  currency            VARCHAR(10)     NOT NULL DEFAULT 'PKR',
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_daily_customer_date (customer_id, summary_date),
  KEY idx_pos_daily_customer_date (customer_id, summary_date),
  CONSTRAINT fk_pos_daily_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
