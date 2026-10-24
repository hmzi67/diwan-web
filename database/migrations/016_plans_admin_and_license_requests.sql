-- =============================================================================
-- 016_plans_admin_and_license_requests.sql
--
-- Plan-based licensing with admin approval, the private admin panel, and
-- download analytics.
--
--  1. plans / features / plan_features — the ONE source of truth for the
--     public pricing cards (GET /api/plans.php) and for what a licence
--     unlocks in the POS (the signed `entitlements` list). A feature lists
--     the POS module/capability keys it unlocks in `pos_entitlements`.
--  2. license_requests — a signed-in customer asks for a plan; nothing is
--     issued until an administrator approves it.
--  3. licenses gains plan_id (NULL = issued before plans existed: the POS
--     keeps every module, so no existing customer silently loses access),
--     license_request_id, max_devices, and a reversible `suspended` status.
--  4. license_activations — one row per device a licence is bound to, so a
--     plan can allow more than one device. Backfilled from the single
--     binding already stored on each activated licence.
--  5. customers gains an account status (suspend/reactivate), business
--     details and last activity.
--  6. admin_users / admin_login_attempts — platform administrators. They are
--     never `customers`; the first one is created with
--     `php scripts/create-admin.php` (no credentials live in code or SQL).
--  7. audit_logs, notifications, download_events, admin_settings.
--
-- Applied to production on: <fill in when run>
-- Idempotent: safe to re-run. Columns are guarded via information_schema
-- (same pattern as 015); seeds use INSERT IGNORE so a re-run never
-- overwrites prices or features an administrator has since edited.
-- =============================================================================

SET NAMES utf8mb4;

DELIMITER $$

CREATE PROCEDURE _migration_016_column(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl VARCHAR(500))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', ddl);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

CREATE PROCEDURE _migration_016_index(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN ddl VARCHAR(500))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD ', ddl);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

CREATE PROCEDURE _migration_016_fk(IN tbl VARCHAR(64), IN fk VARCHAR(64), IN ddl VARCHAR(500))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND CONSTRAINT_NAME = fk
  ) THEN
    SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD CONSTRAINT ', fk, ' ', ddl);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

-- -----------------------------------------------------------------------------
-- 1. Plans and features
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

INSERT IGNORE INTO plans (code, name, tagline, price_minor, currency, billing_period, theme, cta_label, max_devices, sort_order)
VALUES
  ('basic',      'BASIC',      'Everything a single shop needs to start selling.',        1000000, 'PKR', 'month', 'blue',   'Request Access', 1, 1),
  ('advance',    'ADVANCE',    'For growing businesses with a team and a warehouse.',     1500000, 'PKR', 'month', 'green',  'Request Access', 1, 2),
  ('all_in_one', 'ALL-IN-ONE', 'Your whole business: branches, website, app and orders.', 2000000, 'PKR', 'month', 'purple', 'Request Access', 1, 3);

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

-- Orders behind admin-approved licences point at this inactive product; the
-- plan itself (and its price at approval time) is on the licence and order.
INSERT INTO products (sku, name, description, price_paisa, currency, is_active)
VALUES ('diwan-plan-licence', 'Diwan POS — Plan licence',
        'Issued by an administrator after approving a plan request. The plan is recorded on the licence.', 0, 'PKR', 0)
ON DUPLICATE KEY UPDATE sku = VALUES(sku);

-- -----------------------------------------------------------------------------
-- 2. Customers: account status and business details
-- -----------------------------------------------------------------------------
CALL _migration_016_column('customers', 'status', 'status ENUM(''active'',''suspended'') NOT NULL DEFAULT ''active'' AFTER name');
CALL _migration_016_column('customers', 'suspended_at', 'suspended_at DATETIME NULL AFTER status');
CALL _migration_016_column('customers', 'business_name', 'business_name VARCHAR(160) NULL AFTER suspended_at');
CALL _migration_016_column('customers', 'phone', 'phone VARCHAR(20) NULL AFTER business_name');
CALL _migration_016_column('customers', 'last_active_at', 'last_active_at DATETIME NULL AFTER created_at');
CALL _migration_016_index('customers', 'idx_customers_status', 'KEY idx_customers_status (status, created_at)');

-- -----------------------------------------------------------------------------
-- 3. Admin accounts (before anything references them)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 4. Licence requests
-- -----------------------------------------------------------------------------
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
-- 5. Licences: plan, request, device limit, suspension
-- -----------------------------------------------------------------------------
ALTER TABLE licenses MODIFY status ENUM('active','suspended','revoked','expired') NOT NULL DEFAULT 'active';
CALL _migration_016_column('licenses', 'plan_id', 'plan_id INT UNSIGNED NULL COMMENT ''NULL = pre-plan licence: every POS module stays available'' AFTER customer_id');
CALL _migration_016_column('licenses', 'license_request_id', 'license_request_id BIGINT UNSIGNED NULL AFTER plan_id');
CALL _migration_016_column('licenses', 'max_devices', 'max_devices SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER activated_at');
CALL _migration_016_column('licenses', 'status_changed_at', 'status_changed_at DATETIME NULL AFTER status');
CALL _migration_016_column('licenses', 'status_reason', 'status_reason VARCHAR(255) NULL AFTER status_changed_at');
CALL _migration_016_column('licenses', 'issued_by', 'issued_by INT UNSIGNED NULL AFTER license_request_id');
CALL _migration_016_index('licenses', 'uq_licenses_request', 'UNIQUE KEY uq_licenses_request (license_request_id)');
CALL _migration_016_index('licenses', 'idx_licenses_plan', 'KEY idx_licenses_plan (plan_id, status)');
CALL _migration_016_fk('licenses', 'fk_licenses_plan', 'FOREIGN KEY (plan_id) REFERENCES plans (id)');
CALL _migration_016_fk('licenses', 'fk_licenses_request', 'FOREIGN KEY (license_request_id) REFERENCES license_requests (id)');
CALL _migration_016_fk('licenses', 'fk_licenses_issued_by', 'FOREIGN KEY (issued_by) REFERENCES admin_users (id)');

ALTER TABLE license_activation_attempts
  MODIFY result ENUM('activated','reactivated','already_bound','invalid_key','rate_limited',
                     'status_refused','status_not_activated','status_active','status_revoked',
                     'status_expired','status_suspended') NOT NULL;

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

-- Every licence bound before this migration keeps exactly its one device.
INSERT IGNORE INTO license_activations (license_id, machine_hash, machine_hint, activated_at)
SELECT id, activated_machine_hash, activated_machine_hint, COALESCE(activated_at, created_at)
  FROM licenses
 WHERE activation_status = 'activated' AND activated_machine_hash IS NOT NULL;

-- -----------------------------------------------------------------------------
-- 6. Audit log, notifications, download events, settings
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type  ENUM('admin','customer','system') NOT NULL,
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

INSERT IGNORE INTO admin_settings (setting_key, setting_value) VALUES
  ('license_period_days', '30'),
  ('expiry_reminder_days', '7'),
  ('allow_request_after_rejection', '1'),
  ('admin_notification_email', '');

DROP PROCEDURE _migration_016_column;
DROP PROCEDURE _migration_016_index;
DROP PROCEDURE _migration_016_fk;
