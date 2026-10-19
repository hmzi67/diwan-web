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
-- customers — one row per email that has ever checked out or signed up.
-- Signs in with a password (email_verified_at gates the session, see 011) or
-- with Apple (apple_sub; password_hash NULL; Apple has verified the address).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190)    NOT NULL,
  name            VARCHAR(160)    NULL,
  password_hash   VARCHAR(255)    NULL COMMENT 'password_hash() output. NULL = predates password auth, must set one via the reset flow.',
  password_set_at DATETIME        NULL COMMENT 'When the current password was set. Support/audit only.',
  session_epoch   INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Bumped on password reset; signed into the session cookie to revoke older ones.',
  email_verified_at DATETIME      NULL COMMENT 'When the owner proved control of the mailbox. NULL = signup pending verification.',
  apple_sub       VARCHAR(255)    NULL COMMENT 'Sign in with Apple subject. Stable per Apple ID + team.',
  apple_refresh_token_encrypted VARCHAR(1024) NULL COMMENT 'AES-256-GCM; only used to revoke at Apple on account deletion',
  apple_token_client_id VARCHAR(190) NULL COMMENT 'Bundle id or Services ID the refresh token was issued to',
  app_account_token CHAR(36)      NULL COMMENT 'StoreKit appAccountToken (UUID) for purchases made while signed in',
  deleted_at      DATETIME        NULL COMMENT 'Account deleted by its owner; personal data already scrubbed',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_email (email),
  UNIQUE KEY uq_customers_apple_sub (apple_sub),
  UNIQUE KEY uq_customers_app_account_token (app_account_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- password_reset_tokens — single-use reset links, hash-only like
-- download_tokens. Also serves as the "set your first password" path for
-- accounts created before passwords existed (password_hash IS NULL).
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
-- login_attempts — rolling window for rate-limiting password sign-in. Mirrors
-- download_attempts. Emails are stored hashed so this never becomes a log of
-- who tried to sign in and when.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)     NOT NULL,
  email_hash   CHAR(64)        NULL COMMENT 'hash_hmac(sha256, lowercased email, APP_KEY)',
  result       ENUM('ok','bad_password','unknown_email','rate_limited','no_password_set','register') NOT NULL,
  attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_ip_time (ip, attempted_at),
  KEY idx_login_attempts_email_time (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- email_verification_tokens — single-use signup-verification links, hash-only
-- like download_tokens. Redeeming one sets customers.email_verified_at and
-- starts a session; see EmailVerificationService.
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

-- -----------------------------------------------------------------------------
-- orders — checkout attempts and zero-value fulfilled trial grants.
-- gateway=trial is never paid, and never carries a payment transaction.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_ref       VARCHAR(40)     NOT NULL COMMENT 'Our reference, sent to the gateway',
  product_id      INT UNSIGNED    NOT NULL,
  customer_id     BIGINT UNSIGNED NULL COMMENT 'Set at checkout time from customer_email',
  customer_email  VARCHAR(190)    NOT NULL,
  customer_phone  VARCHAR(20)     NOT NULL,
  amount_paisa    INT UNSIGNED    NOT NULL,
  currency        CHAR(3)         NOT NULL DEFAULT 'PKR',
  gateway         VARCHAR(24)     NOT NULL,
  gateway_txn_id  VARCHAR(80)     NULL,
  status          ENUM('pending','paid','failed','refunded','fulfilled') NOT NULL DEFAULT 'pending',
  failure_reason  VARCHAR(255)    NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_ref (order_ref),
  KEY idx_orders_email (customer_email),
  KEY idx_orders_status_created (status, created_at),
  CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- licenses — one per order: verified paid webhook or explicit TrialService.
-- HMAC for lookup; encrypted copy for authenticated dashboard/recovery.
--
-- `status` is the download/entitlement gate (unchanged, still checked by
-- issue-download.php). `activation_status` is a separate axis: whether the
-- key has been bound to a device yet. A license can be status=active
-- (downloads allowed) and activation_status=unused (not yet installed) at
-- the same time — that's the normal state right after purchase.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS licenses (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id               BIGINT UNSIGNED NOT NULL,
  customer_id            BIGINT UNSIGNED NULL,
  license_key_hash       CHAR(64)        NOT NULL COMMENT 'hash_hmac(sha256, key, APP_KEY) — sole source of truth for lookups',
  license_key_encrypted  VARCHAR(255)    NULL COMMENT 'AES-256-GCM(key), reversible, for owner dashboard and email recovery — see migration 002',
  license_key_prefix     VARCHAR(16)     NOT NULL COMMENT 'First group only, for support lookup',
  customer_email         VARCHAR(190)    NOT NULL,
  status                 ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  activation_status      ENUM('unused','activated') NOT NULL DEFAULT 'unused',
  activated_machine_hash CHAR(64)        NULL COMMENT 'sha256(machine id), never the raw fingerprint',
  activated_machine_hint VARCHAR(64)     NULL COMMENT 'e.g. "Windows 11 x64", for admin display only',
  activated_at           DATETIME        NULL,
  last_reactivated_at    DATETIME        NULL COMMENT 'throttles self-service device moves to once/30 days',
  reactivated_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_downloads          SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  downloads_used         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at             DATETIME        NULL,
  created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_licenses_hash (license_key_hash),
  UNIQUE KEY uq_licenses_order (order_id) COMMENT 'Hard guarantee: one licence per order',
  KEY idx_licenses_email (customer_email),
  KEY idx_licenses_activation_status (activation_status),
  CONSTRAINT fk_licenses_order FOREIGN KEY (order_id) REFERENCES orders (id),
  CONSTRAINT fk_licenses_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent trial eligibility ledger. Retain expired/revoked grants.
CREATE TABLE IF NOT EXISTS trial_grants (
  customer_id      BIGINT UNSIGNED NOT NULL,
  normalized_email VARCHAR(190)    NOT NULL,
  order_id         BIGINT UNSIGNED NOT NULL,
  issued_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (normalized_email),
  UNIQUE KEY uq_trial_grants_customer (customer_id),
  UNIQUE KEY uq_trial_grants_order (order_id),
  CONSTRAINT fk_trial_grants_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT fk_trial_grants_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- apple_transactions — one row per App Store purchase (migration 008). Keyed
-- by originalTransactionId, which a non-consumable keeps across restores and
-- devices, so re-verifying a purchase always finds the same order + licence.
-- The order itself is an ordinary `orders` row with gateway = "apple".
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- releases — the installer catalogue. storage_path is RELATIVE to
-- private-storage/releases/ and is validated with realpath() before use.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS releases (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform        ENUM('windows','macos','android','linux') NOT NULL,
  variant         VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '\'\' = only build; else e.g. apple-silicon / intel, x64 / x86, appimage / deb / rpm',
  version         VARCHAR(32)  NOT NULL,
  filename        VARCHAR(190) NOT NULL COMMENT 'Name shown to the customer',
  storage_path    VARCHAR(255) NOT NULL COMMENT 'Relative to private-storage/releases/',
  source          ENUM('local','github') NOT NULL DEFAULT 'local' COMMENT 'Where the installer lives',
  github_tag      VARCHAR(64)  NULL COMMENT 'GitHub release tag, e.g. v1.1.5',
  github_asset    VARCHAR(255) NULL COMMENT 'Asset file name on that GitHub release',
  checksum_sha256 CHAR(64)     NULL,
  size_bytes      BIGINT UNSIGNED NULL,
  release_notes   TEXT         NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  released_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_releases_platform_version (platform, version, variant),
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
-- license_activation_attempts — rolling window for rate-limiting
-- activate-license.php. Mirrors download_attempts, plus what was targeted, so
-- abuse of one specific key from rotating IPs is also visible.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS license_activation_attempts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip                  VARCHAR(45)     NOT NULL,
  license_key_prefix  VARCHAR(16)     NULL,
  -- activated..rate_limited: activate-license.php. status_*: license-status.php
  -- (migration 006) — both endpoints share this table via ActivationThrottle.
  result              ENUM('activated','reactivated','already_bound','invalid_key','rate_limited',
                           'status_refused','status_not_activated','status_active','status_revoked','status_expired') NOT NULL,
  attempted_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activation_attempts_ip_time (ip, attempted_at),
  KEY idx_activation_attempts_prefix_time (license_key_prefix, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- contact_messages — contact form submissions (migration 009). Stored before
-- they are emailed, so a mail failure never loses an enquiry. Also the window
-- ContactService rate-limits against.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120)    NOT NULL,
  email       VARCHAR(190)    NOT NULL,
  topic       ENUM('sales','support','licence','device','refund','privacy','other') NOT NULL,
  order_ref   VARCHAR(40)     NULL,
  message     TEXT            NOT NULL,
  ip          VARCHAR(45)     NOT NULL,
  email_sent  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_contact_messages_ip_time (ip, created_at),
  KEY idx_contact_messages_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- -----------------------------------------------------------------------------
-- Seed data
--
-- These three tiers are the real, sellable catalogue. The descriptions and
-- prices here are published in three other places and must be changed in all
-- four together:
--   frontend/src/index.html   (#pricing cards)
--   frontend/src/products.html (catalogue)
--   frontend/src/refund-policy.html + terms.html (reference the tier names)
-- -----------------------------------------------------------------------------
INSERT INTO products (sku, name, description, price_paisa, currency, is_active)
VALUES
  ('diwan-pos-starter', 'Diwan POS — Starter Licence',
   'One-time licence for a single terminal at one branch. Core billing and inventory, offline-first sync and email support.',
   800000, 'PKR', 1),
  ('diwan-pos-standard', 'Diwan POS — Standard Licence',
   'One-time licence for up to 3 terminals at one branch. Adds reporting, receipt and kitchen printer support, and priority email support.',
   1200000, 'PKR', 1),
  ('diwan-pos-enterprise', 'Diwan POS — Enterprise Licence',
   'One-time licence for unlimited terminals across multiple branches. Adds multi-branch sync and priority phone and email support.',
   2500000, 'PKR', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Reserved for TrialService; is_active=0 excludes this SKU from paid checkout.
INSERT INTO products (sku, name, description, price_paisa, currency, is_active)
VALUES ('diwan-pos-trial', 'Diwan POS — 30-day free trial',
        'One trial per account, one device, 30 days from issuance. No payment.', 0, 'PKR', 0)
ON DUPLICATE KEY UPDATE sku = VALUES(sku);
