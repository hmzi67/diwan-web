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
  status          ENUM('active','suspended') NOT NULL DEFAULT 'active' COMMENT 'Suspended by an administrator (016): no sign-in, no licence check-ins',
  suspended_at    DATETIME        NULL,
  business_name   VARCHAR(160)    NULL,
  phone           VARCHAR(20)     NULL,
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
  last_active_at  DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_email (email),
  KEY idx_customers_status (status, created_at),
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
-- plans / features / plan_features (migration 016) — the one source of truth
-- for the public pricing cards (GET /api/plans.php) and for what a licence
-- unlocks in the POS (the signed `entitlements` list). Seeded at the bottom.
-- admin_users are platform administrators, never customers.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(40)  NOT NULL COMMENT 'Stable identifier, e.g. basic. Never shown as a price.',
  name           VARCHAR(80)  NOT NULL,
  tagline        VARCHAR(190) NULL,
  price_minor    BIGINT UNSIGNED NOT NULL COMMENT 'Integer paisa per billing period. Never FLOAT.',
  currency       CHAR(3)      NOT NULL DEFAULT 'PKR',
  billing_period ENUM('month','year') NOT NULL DEFAULT 'month',
  theme          ENUM('blue','green','purple','slate') NOT NULL DEFAULT 'slate',
  cta_label      VARCHAR(40)  NOT NULL DEFAULT 'Request Access',
  max_devices    SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Devices a new licence on this plan may activate on',
  sort_order     SMALLINT     NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = hidden from pricing, no new requests; existing licences unaffected',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS features (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(60)  NOT NULL COMMENT 'Stable identifier, e.g. inventory_full',
  name             VARCHAR(120) NOT NULL,
  description      VARCHAR(500) NULL,
  -- Comma-separated POS module/capability keys this feature unlocks in the
  -- app (db::modules::key in the POS, plus capabilities such as
  -- inventory_advanced). Empty = nothing in the app (a service, or a
  -- feature not built into the app yet — see `availability`).
  pos_entitlements VARCHAR(500) NOT NULL DEFAULT '',
  availability     ENUM('app','service','planned') NOT NULL DEFAULT 'app'
                   COMMENT 'app = enforced in the POS; service = delivered by the Diwan team; planned = not built yet',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_features_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A plan's entitlement is every row here; `show_on_card` picks the ones the
-- public pricing card lists (in sort_order, under `display_label`). Rows with
-- show_on_card = 0 are features deliberately carried up from a lower tier.
CREATE TABLE IF NOT EXISTS plan_features (
  plan_id       INT UNSIGNED NOT NULL,
  feature_id    INT UNSIGNED NOT NULL,
  display_label VARCHAR(120) NULL COMMENT 'Card wording when it differs from features.name',
  show_on_card  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (plan_id, feature_id),
  KEY idx_plan_features_feature (feature_id),
  CONSTRAINT fk_plan_features_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE CASCADE,
  CONSTRAINT fk_plan_features_feature FOREIGN KEY (feature_id) REFERENCES features (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
  session_epoch INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bumped to sign the admin out everywhere',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)     NOT NULL,
  email_hash   CHAR(64)        NULL,
  result       ENUM('ok','failed','rate_limited') NOT NULL,
  attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_login_attempts_ip_time (ip, attempted_at),
  KEY idx_admin_login_attempts_email_time (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_requests (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id    BIGINT UNSIGNED NOT NULL,
  plan_id        INT UNSIGNED    NOT NULL,
  status         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  business_name  VARCHAR(160)    NULL,
  contact_phone  VARCHAR(20)     NULL,
  message        VARCHAR(1000)   NULL,
  decision_note  VARCHAR(1000)   NULL COMMENT 'Shown to the customer on approval/rejection',
  decided_by     INT UNSIGNED    NULL,
  decided_at     DATETIME        NULL,
  license_id     BIGINT UNSIGNED NULL COMMENT 'Set on approval; the licence it produced (no FK: licences reference requests)',
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NULL,
  PRIMARY KEY (id),
  KEY idx_license_requests_status_created (status, created_at),
  KEY idx_license_requests_customer (customer_id, created_at),
  KEY idx_license_requests_license (license_id),
  CONSTRAINT fk_license_requests_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT fk_license_requests_plan FOREIGN KEY (plan_id) REFERENCES plans (id),
  CONSTRAINT fk_license_requests_admin FOREIGN KEY (decided_by) REFERENCES admin_users (id)
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
  plan_id                INT UNSIGNED    NULL COMMENT 'NULL = pre-plan licence (before 016): every POS module stays available',
  license_request_id     BIGINT UNSIGNED NULL COMMENT 'The approved request that produced this licence',
  issued_by              INT UNSIGNED    NULL COMMENT 'Approving administrator',
  license_key_hash       CHAR(64)        NOT NULL COMMENT 'hash_hmac(sha256, key, APP_KEY) — sole source of truth for lookups',
  license_key_encrypted  VARCHAR(255)    NULL COMMENT 'AES-256-GCM(key), reversible, for owner dashboard and email recovery — see migration 002',
  license_key_prefix     VARCHAR(16)     NOT NULL COMMENT 'First group only, for support lookup',
  customer_email         VARCHAR(190)    NOT NULL,
  status                 ENUM('active','suspended','revoked','expired') NOT NULL DEFAULT 'active',
  status_changed_at      DATETIME        NULL,
  status_reason          VARCHAR(255)    NULL,
  activation_status      ENUM('unused','activated') NOT NULL DEFAULT 'unused',
  activated_machine_hash CHAR(64)        NULL COMMENT 'sha256(machine id), never the raw fingerprint',
  activated_machine_hint VARCHAR(64)     NULL COMMENT 'e.g. "Windows 11 x64", for admin display only',
  activated_at           DATETIME        NULL,
  max_devices            SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Devices this licence may be active on (see license_activations)',
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
  UNIQUE KEY uq_licenses_request (license_request_id),
  KEY idx_licenses_plan (plan_id, status),
  CONSTRAINT fk_licenses_order FOREIGN KEY (order_id) REFERENCES orders (id),
  CONSTRAINT fk_licenses_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT fk_licenses_plan FOREIGN KEY (plan_id) REFERENCES plans (id),
  CONSTRAINT fk_licenses_request FOREIGN KEY (license_request_id) REFERENCES license_requests (id),
  CONSTRAINT fk_licenses_issued_by FOREIGN KEY (issued_by) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per device a licence is bound to (016). `licenses.activated_machine_*`
-- still records the first binding for older readers.
CREATE TABLE IF NOT EXISTS license_activations (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id     BIGINT UNSIGNED NOT NULL,
  machine_hash   CHAR(64)        NOT NULL COMMENT 'sha256(machine fingerprint), never the raw value',
  machine_hint   VARCHAR(64)     NULL,
  activated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at   DATETIME        NULL COMMENT 'Last licence-status check from this device',
  deactivated_at DATETIME        NULL COMMENT 'Set when an administrator frees the slot',
  PRIMARY KEY (id),
  UNIQUE KEY uq_license_activations_device (license_id, machine_hash),
  KEY idx_license_activations_active (license_id, deactivated_at),
  CONSTRAINT fk_license_activations_license FOREIGN KEY (license_id) REFERENCES licenses (id)
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
                           'status_refused','status_not_activated','status_active','status_revoked','status_expired',
                           'status_suspended') NOT NULL,
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
  customer_name       VARCHAR(100)    NULL,
  total_minor         BIGINT          NOT NULL,
  discount_minor      BIGINT          NOT NULL DEFAULT 0,
  tax_minor           BIGINT          NOT NULL DEFAULT 0,
  service_charge_minor BIGINT         NOT NULL DEFAULT 0,
  -- 'cash' | 'card' | 'other' | 'platform', or 'split' / 'credit' (pay later).
  payment_method      VARCHAR(20)     NOT NULL DEFAULT 'cash',
  -- Tender breakdown (migration 015). NULL on rows synced before it: read
  -- those through payment_method instead. credit_minor is the pay-later part.
  cash_minor          BIGINT          NULL,
  card_minor          BIGINT          NULL,
  other_minor         BIGINT          NULL,
  credit_minor        BIGINT          NULL,
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

-- pos_records — forward-compatible mirror of all non-sensitive POS entities.
-- Each sync replaces a device's rows per entity it sends; any *_hash field is
-- dropped on ingest (SyncService::PRIVATE_FIELD), so no PIN hash is stored.
-- The POS application evolves independently, so the dashboard stores the
-- original row as JSON while indexing the tenant/entity/local id for lookup.
CREATE TABLE IF NOT EXISTS pos_records (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id         BIGINT UNSIGNED NOT NULL,
  device_fingerprint  VARCHAR(64)     NOT NULL,
  entity_name         VARCHAR(64)     NOT NULL,
  local_record_id     VARCHAR(100)    NOT NULL,
  record_json         LONGTEXT        NOT NULL,
  synced_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_records_customer_device_entity_record
    (customer_id, device_fingerprint, entity_name, local_record_id),
  KEY idx_pos_records_customer_entity (customer_id, entity_name),
  CONSTRAINT fk_pos_records_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type  ENUM('admin','customer','system','terminal') NOT NULL,
  actor_id    BIGINT UNSIGNED NULL,
  actor_label VARCHAR(190)    NULL COMMENT 'Email at the time, so the log survives account changes',
  action      VARCHAR(64)     NOT NULL COMMENT 'e.g. license_request.approve',
  target_type VARCHAR(40)     NULL,
  target_id   BIGINT UNSIGNED NULL,
  details     TEXT            NULL COMMENT 'JSON; never secrets or full licence keys',
  ip          VARCHAR(45)     NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_created (created_at),
  KEY idx_audit_logs_target (target_type, target_id, created_at),
  KEY idx_audit_logs_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  audience     ENUM('customer','admin') NOT NULL,
  customer_id  BIGINT UNSIGNED NULL COMMENT 'Set when audience = customer',
  type         VARCHAR(48)     NOT NULL COMMENT 'e.g. license.approved',
  title        VARCHAR(190)    NOT NULL,
  body         VARCHAR(2000)   NOT NULL,
  link         VARCHAR(255)    NULL,
  -- What actually happened to the email copy. 'not_configured' is recorded
  -- honestly when MAIL_* is unset, never reported as sent.
  email_status ENUM('none','sent','failed','not_configured') NOT NULL DEFAULT 'none',
  dedupe_key   VARCHAR(120)    NULL COMMENT 'Stops a cron re-run sending the same reminder twice',
  read_at      DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notifications_dedupe (dedupe_key),
  KEY idx_notifications_customer (customer_id, read_at, created_at),
  KEY idx_notifications_audience (audience, read_at, created_at),
  CONSTRAINT fk_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per download attempt. `requested` = a link was issued; `served`
-- = the link was redeemed and the bytes started (or GitHub's signed URL was
-- handed over); `completed` = PHP streamed the whole file without the client
-- disconnecting (only measurable for locally stored installers); `failed` =
-- refused or interrupted. A page refresh creates nothing.
CREATE TABLE IF NOT EXISTS download_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uid      CHAR(32)        NOT NULL COMMENT 'Opaque event identifier',
  token_id       BIGINT UNSIGNED NULL COMMENT 'The download_tokens row this attempt used',
  customer_id    BIGINT UNSIGNED NULL,
  license_id     BIGINT UNSIGNED NULL,
  plan_id        INT UNSIGNED    NULL COMMENT 'Licence plan at download time (NULL = pre-plan licence)',
  release_id     INT UNSIGNED    NULL,
  version        VARCHAR(32)     NULL,
  platform       VARCHAR(16)     NULL,
  variant        VARCHAR(32)     NULL,
  channel        ENUM('dashboard','licence_key','other') NOT NULL DEFAULT 'other',
  status         ENUM('requested','served','completed','failed') NOT NULL,
  failure_reason VARCHAR(64)     NULL,
  license_status VARCHAR(16)     NULL COMMENT 'Licence status at download time',
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_download_events_uid (event_uid),
  KEY idx_download_events_created (created_at),
  KEY idx_download_events_customer (customer_id, created_at),
  KEY idx_download_events_token (token_id),
  KEY idx_download_events_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_settings (
  setting_key   VARCHAR(64)  NOT NULL,
  setting_value VARCHAR(500) NOT NULL,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME     NULL,
  PRIMARY KEY (setting_key)
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

-- Plans, features and admin settings (migration 016). INSERT IGNORE: a
-- re-import never overwrites what an administrator has edited since.
INSERT IGNORE INTO plans (code, name, tagline, price_minor, currency, billing_period, theme, cta_label, max_devices, sort_order)
VALUES
  ('basic',      'BASIC',      'Everything a single shop needs to start selling.',        1000000, 'PKR', 'month', 'blue',   'Request Access', 1, 1),
  ('advance',    'ADVANCE',    'For growing businesses with a team and a warehouse.',     1500000, 'PKR', 'month', 'green',  'Request Access', 3, 2),
  ('all_in_one', 'ALL-IN-ONE', 'Your whole business: branches, website, app and orders.', 2000000, 'PKR', 'month', 'purple', 'Request Access', 10, 3);

INSERT IGNORE INTO features (code, name, description, pos_entitlements, availability)
VALUES
  ('pos_core',                  'POS System',                       'Billing, receipts, shifts and settings.',                               'billing,shifts,settings,users', 'app'),
  ('pos_complete',              'Complete POS System',              'Adds dine-in tables and kitchen tokens to the POS.',                    'tables',                        'app'),
  ('inventory_basic',           'Inventory Management',             'Items, categories, stock levels and adjustments.',                      'inventory',                     'app'),
  ('inventory_full',            'Full Inventory Management',        'Adds stock takes, CSV import and barcode label printing.',              'inventory,inventory_advanced',  'app'),
  ('sales_purchase_reports',    'Sales & Purchase Reports',         'Sales, product, category and refund reports.',                         'reports',                       'app'),
  ('expense_management',        'Expense Management',               'Record and review business expenses.',                                  'expenses',                      'app'),
  ('daily_sales_tracking',      'Daily Sales Tracking',             'Today''s takings on the dashboard and the daily sales report.',         'dashboard,reports',             'app'),
  ('dashboard',                 'User-Friendly Dashboard',          'At-a-glance business dashboard.',                                       'dashboard',                     'app'),
  ('basic_support',             'Basic Customer Support',           'Email support during business hours.',                                  '',                              'service'),
  ('warehouse_management',      'Warehouse Management',             'Stock across warehouses. Not built into the app yet.',                  '',                              'planned'),
  ('advanced_analytics',        'Advanced Reports & Analytics',     'Profit & loss, order profit, tax, voids, stock usage and expiry.',      'reports,reports_advanced',      'app'),
  ('ai_features',               'AI Features',                      'AI-assisted insights. Not built into the app yet.',                     '',                              'planned'),
  ('multi_user_access',         'Multi-User Access',                'More than one staff login, plus employees, attendance and salary.',     'multi_user,employees,attendance,salary', 'app'),
  ('purchase_sales_management', 'Purchase & Sales Management',      'Suppliers, purchase orders, goods received and the ledger.',            'purchasing,ledger',             'app'),
  ('priority_support',          'Priority Support',                 'Faster response from the support team.',                                '',                              'service'),
  ('supply_chain_management',   'Complete Supply Chain Management', 'Purchasing and supplier ledger today; wider supply chain planned.',     'purchasing,ledger',             'app'),
  ('branch_management',         'Warehouse & Branch Management',    'Several branches under one account. Not built into the app yet.',      '',                              'planned'),
  ('business_website',          'Business Website',                 'A website for your business, set up by the Diwan team.',               '',                              'service'),
  ('mobile_application',        'Mobile Application (Android / iOS)', 'Run Diwan on Android phones and tablets, and on iOS.',                'mobile',                        'app'),
  ('online_order_management',   'Online Order Management',          'Delivery and online orders.',                                           'delivery',                      'app'),
  ('customer_management',       'Customer Management System',       'Customer accounts, pay-later sales and installments.',                  'customers',                     'app'),
  ('dedicated_support',         'Dedicated Technical Support',      'A named support contact for your business.',                           '',                              'service');

-- (plan code, feature code, card label, shown on card, order)
INSERT IGNORE INTO plan_features (plan_id, feature_id, display_label, show_on_card, sort_order)
SELECT p.id, f.id, s.label, s.shown, s.ord
  FROM (
            SELECT 'basic' AS plan, 'pos_core' AS feature, NULL AS label, 1 AS shown, 1 AS ord
  UNION ALL SELECT 'basic', 'inventory_basic', NULL, 1, 2
  UNION ALL SELECT 'basic', 'sales_purchase_reports', NULL, 1, 3
  UNION ALL SELECT 'basic', 'expense_management', NULL, 1, 4
  UNION ALL SELECT 'basic', 'daily_sales_tracking', NULL, 1, 5
  UNION ALL SELECT 'basic', 'dashboard', NULL, 1, 6
  UNION ALL SELECT 'basic', 'basic_support', NULL, 1, 7

  UNION ALL SELECT 'advance', 'pos_complete', NULL, 1, 1
  UNION ALL SELECT 'advance', 'inventory_full', NULL, 1, 2
  UNION ALL SELECT 'advance', 'warehouse_management', NULL, 1, 3
  UNION ALL SELECT 'advance', 'advanced_analytics', NULL, 1, 4
  UNION ALL SELECT 'advance', 'ai_features', NULL, 1, 5
  UNION ALL SELECT 'advance', 'multi_user_access', NULL, 1, 6
  UNION ALL SELECT 'advance', 'purchase_sales_management', NULL, 1, 7
  UNION ALL SELECT 'advance', 'expense_management', 'Expense Tracking', 1, 8
  UNION ALL SELECT 'advance', 'priority_support', NULL, 1, 9
  UNION ALL SELECT 'advance', 'pos_core', NULL, 0, 20
  UNION ALL SELECT 'advance', 'inventory_basic', NULL, 0, 21
  UNION ALL SELECT 'advance', 'sales_purchase_reports', NULL, 0, 22
  UNION ALL SELECT 'advance', 'daily_sales_tracking', NULL, 0, 23
  UNION ALL SELECT 'advance', 'dashboard', NULL, 0, 24

  UNION ALL SELECT 'all_in_one', 'supply_chain_management', NULL, 1, 1
  UNION ALL SELECT 'all_in_one', 'branch_management', NULL, 1, 2
  UNION ALL SELECT 'all_in_one', 'business_website', NULL, 1, 3
  UNION ALL SELECT 'all_in_one', 'mobile_application', NULL, 1, 4
  UNION ALL SELECT 'all_in_one', 'online_order_management', NULL, 1, 5
  UNION ALL SELECT 'all_in_one', 'customer_management', NULL, 1, 6
  UNION ALL SELECT 'all_in_one', 'dedicated_support', NULL, 1, 7
  UNION ALL SELECT 'all_in_one', 'pos_core', NULL, 0, 20
  UNION ALL SELECT 'all_in_one', 'pos_complete', NULL, 0, 21
  UNION ALL SELECT 'all_in_one', 'inventory_basic', NULL, 0, 22
  UNION ALL SELECT 'all_in_one', 'inventory_full', NULL, 0, 23
  UNION ALL SELECT 'all_in_one', 'sales_purchase_reports', NULL, 0, 24
  UNION ALL SELECT 'all_in_one', 'expense_management', NULL, 0, 25
  UNION ALL SELECT 'all_in_one', 'daily_sales_tracking', NULL, 0, 26
  UNION ALL SELECT 'all_in_one', 'dashboard', NULL, 0, 27
  UNION ALL SELECT 'all_in_one', 'warehouse_management', NULL, 0, 28
  UNION ALL SELECT 'all_in_one', 'advanced_analytics', NULL, 0, 29
  UNION ALL SELECT 'all_in_one', 'ai_features', NULL, 0, 30
  UNION ALL SELECT 'all_in_one', 'multi_user_access', NULL, 0, 31
  UNION ALL SELECT 'all_in_one', 'purchase_sales_management', NULL, 0, 32
       ) s
  JOIN plans p ON p.code = s.plan
  JOIN features f ON f.code = s.feature;

INSERT INTO products (sku, name, description, price_paisa, currency, is_active)
VALUES ('diwan-plan-licence', 'Diwan POS — Plan licence',
        'Issued by an administrator after approving a plan request. The plan is recorded on the licence.', 0, 'PKR', 0)
ON DUPLICATE KEY UPDATE sku = VALUES(sku);

INSERT IGNORE INTO admin_settings (setting_key, setting_value) VALUES
  ('license_period_days', '30'),
  ('expiry_reminder_days', '7'),
  ('allow_request_after_rejection', '1'),
  ('admin_notification_email', '');

-- =============================================================================
-- Business workspaces and multi-counter sync (migration 017) — see
-- docs/MULTI-COUNTER.md.
-- =============================================================================
CREATE TABLE IF NOT EXISTS businesses (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id             BIGINT UNSIGNED NOT NULL,
  customer_id            BIGINT UNSIGNED NULL COMMENT 'Owner account; NULL for a licence with no account',
  name                   VARCHAR(120)    NOT NULL,
  sync_version           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seeded_by_terminal_id  BIGINT UNSIGNED NULL COMMENT 'The counter whose existing data started the workspace',
  seed_started_at        DATETIME        NULL,
  seed_completed_at      DATETIME        NULL COMMENT 'Other counters may join only after this',
  pin_key_check          CHAR(16)        NULL COMMENT 'Id of the key counters seal staff PIN hashes with (never the key)',
  created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_businesses_license (license_id),
  KEY idx_businesses_customer (customer_id),
  CONSTRAINT fk_businesses_license FOREIGN KEY (license_id) REFERENCES licenses (id),
  CONSTRAINT fk_businesses_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_terminals (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id    BIGINT UNSIGNED NOT NULL,
  activation_id  BIGINT UNSIGNED NOT NULL COMMENT 'The device slot this counter occupies',
  code           CHAR(8)         NOT NULL COMMENT 'Short public id shown in reports',
  name           VARCHAR(60)     NOT NULL,
  install_id     CHAR(16)        NOT NULL COMMENT 'Prefix of the gids this counter mints',
  token_hash     CHAR(64)        NOT NULL COMMENT 'sha256 of the bearer token; the token itself is never stored',
  status         ENUM('active','revoked') NOT NULL DEFAULT 'active',
  platform       VARCHAR(20)     NULL,
  app_version    VARCHAR(32)     NULL,
  joined_at      DATETIME        NULL COMMENT 'Set once the counter holds the business data; it may push only after this',
  last_seen_at   DATETIME        NULL,
  last_push_at   DATETIME        NULL,
  last_pull_at   DATETIME        NULL,
  pending_count  INT UNSIGNED    NULL COMMENT 'Changes waiting on the counter, as it last reported',
  last_error     VARCHAR(255)    NULL COMMENT 'The counter''s last sync failure, as it reported',
  last_error_at  DATETIME        NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at     DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_terminals_activation (activation_id),
  UNIQUE KEY uq_pos_terminals_code (code),
  UNIQUE KEY uq_pos_terminals_token (token_hash),
  KEY idx_pos_terminals_business (business_id, status),
  KEY idx_pos_terminals_install (business_id, install_id),
  CONSTRAINT fk_pos_terminals_business FOREIGN KEY (business_id) REFERENCES businesses (id),
  CONSTRAINT fk_pos_terminals_activation FOREIGN KEY (activation_id) REFERENCES license_activations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_rows (
  business_id        BIGINT UNSIGNED NOT NULL,
  entity             VARCHAR(40)     NOT NULL,
  gid                VARCHAR(64)     NOT NULL,
  data               MEDIUMTEXT      NOT NULL COMMENT 'JSON; foreign keys are gids',
  field_versions     MEDIUMTEXT      NULL COMMENT 'JSON {field: [version, terminal id]}',
  deleted            TINYINT(1)      NOT NULL DEFAULT 0,
  version            BIGINT UNSIGNED NOT NULL,
  origin_terminal_id BIGINT UNSIGNED NULL COMMENT 'Counter that first sent this record',
  updated_by_terminal_id BIGINT UNSIGNED NULL,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (business_id, entity, gid),
  KEY idx_sync_rows_version (business_id, version),
  CONSTRAINT fk_sync_rows_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_stock (
  business_id  BIGINT UNSIGNED NOT NULL,
  item_gid     VARCHAR(64)     NOT NULL,
  qty          DECIMAL(18,4)   NOT NULL DEFAULT 0,
  version      BIGINT UNSIGNED NOT NULL,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (business_id, item_gid),
  KEY idx_sync_stock_version (business_id, version),
  CONSTRAINT fk_sync_stock_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_ledger (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id    BIGINT UNSIGNED NOT NULL,
  event_key      VARCHAR(100)    NOT NULL COMMENT 'Unique per business and item: a replayed change is ignored',
  item_gid       VARCHAR(64)     NOT NULL,
  qty_change     DECIMAL(18,4)   NOT NULL,
  balance_after  DECIMAL(18,4)   NOT NULL,
  kind           ENUM('change','reservation','release') NOT NULL,
  terminal_id    BIGINT UNSIGNED NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_ledger_event (business_id, event_key, item_gid),
  KEY idx_stock_ledger_item (business_id, item_gid, id),
  CONSTRAINT fk_stock_ledger_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_reservations (
  business_id     BIGINT UNSIGNED NOT NULL,
  reservation_key VARCHAR(100)    NOT NULL,
  terminal_id     BIGINT UNSIGNED NOT NULL,
  status          ENUM('committed','released') NOT NULL DEFAULT 'committed',
  `lines`         TEXT            NOT NULL COMMENT 'JSON {item_gid: qty} actually taken',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at     DATETIME        NULL,
  PRIMARY KEY (business_id, reservation_key),
  CONSTRAINT fk_stock_reservations_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_conflicts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id  BIGINT UNSIGNED NOT NULL,
  terminal_id  BIGINT UNSIGNED NULL,
  kind         VARCHAR(40)     NOT NULL COMMENT 'negative_stock | rejected_change | counter_reported',
  entity       VARCHAR(40)     NULL,
  gid          VARCHAR(64)     NULL,
  detail       VARCHAR(500)    NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME        NULL,
  resolved_by  BIGINT UNSIGNED NULL COMMENT 'admin_users.id',
  PRIMARY KEY (id),
  KEY idx_sync_conflicts_open (business_id, resolved_at),
  CONSTRAINT fk_sync_conflicts_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_media (
  business_id  BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(100)    NOT NULL COMMENT 'File name the counters use (items.image_path / app_config.logo_path)',
  bytes        INT UNSIGNED    NOT NULL,
  terminal_id  BIGINT UNSIGNED NULL COMMENT 'Counter that uploaded it',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (business_id, name),
  CONSTRAINT fk_sync_media_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_load (
  hour_start  DATETIME     NOT NULL,
  endpoint    VARCHAR(24)  NOT NULL COMMENT 'push | pull | reserve | media',
  requests    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (hour_start, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_sales (
  business_id    BIGINT UNSIGNED NOT NULL,
  sale_gid       VARCHAR(64)     NOT NULL,
  terminal_id    BIGINT UNSIGNED NULL COMMENT 'Counter that rang it up',
  cashier_gid    VARCHAR(64)     NULL,
  total_minor    BIGINT          NOT NULL,
  payment_method VARCHAR(20)     NOT NULL,
  sold_at        DATETIME        NOT NULL COMMENT 'The counter''s local time of sale',
  deleted        TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (business_id, sale_gid),
  KEY idx_business_sales_time (business_id, sold_at),
  KEY idx_business_sales_terminal (business_id, terminal_id, sold_at),
  CONSTRAINT fk_business_sales_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
