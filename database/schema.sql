-- =============================================================================
-- Diwan POS — sales & licensing schema
-- MySQL 5.7+ / MariaDB 10.3+  (shared hosting safe: no CHECK constraints,
-- no JSON column type, no window functions)
--
-- Apply with:  mysql -u USER -p DBNAME < database/schema.sql
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00';

-- -----------------------------------------------------------------------------
-- products — what is for sale. Price lives here so the browser can never set it.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku          VARCHAR(64)  NOT NULL,
  name         VARCHAR(160) NOT NULL,
  description  TEXT         NULL,
  price_paisa  INT UNSIGNED NOT NULL COMMENT 'Integer paisa. Never use FLOAT for money.',
  currency     CHAR(3)      NOT NULL DEFAULT 'PKR',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- orders — one row per checkout attempt.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_ref       VARCHAR(40)     NOT NULL COMMENT 'Our reference, sent to the gateway',
  product_id      INT UNSIGNED    NOT NULL,
  customer_email  VARCHAR(190)    NOT NULL,
  customer_phone  VARCHAR(20)     NOT NULL,
  amount_paisa    INT UNSIGNED    NOT NULL,
  currency        CHAR(3)         NOT NULL DEFAULT 'PKR',
  gateway         VARCHAR(24)     NOT NULL,
  gateway_txn_id  VARCHAR(80)     NULL,
  status          ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  failure_reason  VARCHAR(255)    NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_ref (order_ref),
  KEY idx_orders_email (customer_email),
  KEY idx_orders_status_created (status, created_at),
  CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- licenses — issued only by the verified webhook. One per paid order.
-- Only an HMAC of the key is stored; the plaintext is emailed once.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS licenses (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id           BIGINT UNSIGNED NOT NULL,
  license_key_hash   CHAR(64)        NOT NULL COMMENT 'hash_hmac(sha256, key, APP_KEY)',
  license_key_prefix VARCHAR(16)     NOT NULL COMMENT 'First group only, for support lookup',
  customer_email     VARCHAR(190)    NOT NULL,
  status             ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  max_downloads      SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  downloads_used     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  activated_machine  VARCHAR(190)    NULL COMMENT 'Optional device binding',
  expires_at         DATETIME        NULL,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_licenses_hash (license_key_hash),
  UNIQUE KEY uq_licenses_order (order_id) COMMENT 'Hard guarantee: one licence per order',
  KEY idx_licenses_email (customer_email),
  CONSTRAINT fk_licenses_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- releases — the installer catalogue. storage_path is RELATIVE to
-- private-storage/releases/ and is validated with realpath() before use.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS releases (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform        ENUM('windows','macos','android') NOT NULL,
  version         VARCHAR(32)  NOT NULL,
  filename        VARCHAR(190) NOT NULL COMMENT 'Name shown to the customer',
  storage_path    VARCHAR(255) NOT NULL COMMENT 'Relative to private-storage/releases/',
  checksum_sha256 CHAR(64)     NULL,
  size_bytes      BIGINT UNSIGNED NULL,
  release_notes   TEXT         NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  released_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_releases_platform_version (platform, version),
  KEY idx_releases_active (platform, is_active, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- download_tokens — single-use, short-lived. This is what download.php checks.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS download_tokens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash  CHAR(64)        NOT NULL,
  license_id  BIGINT UNSIGNED NOT NULL,
  release_id  INT UNSIGNED    NOT NULL,
  issued_ip   VARCHAR(45)     NULL,
  used_ip     VARCHAR(45)     NULL,
  used_at     DATETIME        NULL,
  expires_at  DATETIME        NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_expiry (expires_at),
  CONSTRAINT fk_tokens_license FOREIGN KEY (license_id) REFERENCES licenses (id),
  CONSTRAINT fk_tokens_release FOREIGN KEY (release_id) REFERENCES releases (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- webhook_events — raw gateway callbacks. The UNIQUE key is what makes the
-- webhook idempotent: a replayed event fails to insert and is ignored.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gateway     VARCHAR(24)     NOT NULL,
  event_id    VARCHAR(120)    NOT NULL COMMENT 'Gateway transaction id',
  order_ref   VARCHAR(40)     NULL,
  payload     TEXT            NULL,
  received_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_gateway_event (gateway, event_id),
  KEY idx_events_order (order_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- download_attempts — rolling window for rate limiting issue-download.php.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS download_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)     NOT NULL,
  attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Seed data
-- -----------------------------------------------------------------------------
INSERT INTO products (sku, name, description, price_paisa, currency, is_active)
VALUES ('diwan-pos-standard', 'Diwan POS — Standard Licence',
        'Single-terminal perpetual licence with 1 year of updates.',
        1200000, 'PKR', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);
