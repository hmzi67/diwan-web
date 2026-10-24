-- =============================================================================
-- 017_business_workspaces_and_counter_sync.sql
--
-- One restaurant = one business workspace, shared by every counter (POS
-- terminal) that its licence is activated on. See docs/MULTI-COUNTER.md.
--
--  1. businesses — one per licence (a renewal or plan change keeps the same
--     licence row, so the workspace survives them). `sync_version` is the
--     business's change counter: every accepted write takes the next value
--     while holding this row's lock, so versions commit in order and a
--     counter that pulls "everything after N" can never skip a change.
--  2. pos_terminals — a counter. Exactly one per device slot
--     (license_activations row), so the licence's max_devices is also the
--     counter limit. Authenticates with a random token stored here only as
--     sha256.
--  3. sync_rows — the authoritative copy of every shared record (items,
--     sales, customers, users, settings …), one row per (entity, gid), with
--     tombstones for deletes. `gid` is the record's global id, minted by the
--     counter that created it.
--  4. sync_stock + stock_ledger — central stock. Counters never send a stock
--     *level*, only signed changes, each with a key that is unique per
--     business, so a retried push can never apply the same change twice and
--     two counters selling at once both land (100 - 5 - 3 = 92).
--  5. stock_reservations — an online sale's stock, taken centrally before
--     the counter records the sale, so two counters cannot both sell the
--     last unit. Released if the counter then fails to record the sale.
--  6. sync_conflicts — what needs a person: stock that went negative because
--     of an offline sale, records a counter could not apply, and so on.
--  7. business_sales — a flat projection of each sale for server-side
--     totals (admin panel: by business, by counter).
--  8. audit_logs.actor_type gains 'terminal' (a counter registering,
--     seeding, joining).
--
-- Applied to production on: <fill in when run>
-- Idempotent: CREATE TABLE IF NOT EXISTS, plus one enum widened in place
-- (re-running it is a no-op). No existing row is changed.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS businesses (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id             BIGINT UNSIGNED NOT NULL,
  customer_id            BIGINT UNSIGNED NULL COMMENT 'Owner account; NULL for a licence with no account',
  name                   VARCHAR(120)    NOT NULL,
  sync_version           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seeded_by_terminal_id  BIGINT UNSIGNED NULL COMMENT 'The counter whose existing data started the workspace',
  seed_started_at        DATETIME        NULL,
  seed_completed_at      DATETIME        NULL COMMENT 'Other counters may join only after this',
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

ALTER TABLE audit_logs MODIFY actor_type ENUM('admin','customer','system','terminal') NOT NULL;
