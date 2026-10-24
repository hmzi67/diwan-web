-- =============================================================================
-- 018_media_merge_and_load.sql
--
-- Follow-ups to 017 (one business, many counters). See docs/MULTI-COUNTER.md.
--
--  1. sync_media — product photos and the logo, shared by a business's
--     counters. The files live in private-storage/media/<business id>/
--     (outside the web root, served only to that business's counters); this
--     table is their index, for quotas, the admin panel and clean-up.
--  2. sync_rows.field_versions — per field, the version and counter of its
--     last write. A counter now sends only the fields it changed, so two
--     counters editing different fields of one record both keep their
--     change; two edits of the *same* field are flagged.
--  3. sync_load — requests per hour and endpoint, so the admin panel shows
--     when shared hosting is getting busy.
--  4. businesses.pin_key_check — the id of the key the counters seal staff
--     PIN hashes with (derived from the licence key, which this server only
--     stores hashed). Set once sealed hashes arrive; shown in the admin panel.
--  5. Counter limits per plan: ADVANCE 3, ALL-IN-ONE 10 (only where the plan
--     still has the seed value of 1, so an administrator's edit is kept).
--
-- Applied to production on: <fill in when run>
-- Idempotent: re-running it is a no-op. No existing record is changed except
-- the two plan defaults above.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sync_media (
  business_id  BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(100)    NOT NULL COMMENT 'File name the counters use (items.image_path / app_config.logo_path)',
  bytes        INT UNSIGNED    NOT NULL,
  terminal_id  BIGINT UNSIGNED NULL COMMENT 'Counter that uploaded it',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (business_id, name),
  CONSTRAINT fk_sync_media_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_rows' AND COLUMN_NAME = 'field_versions');
SET @sql := IF(@has = 0,
  'ALTER TABLE sync_rows ADD COLUMN field_versions MEDIUMTEXT NULL COMMENT ''JSON {field: [version, terminal id]}'' AFTER data',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS sync_load (
  hour_start  DATETIME     NOT NULL,
  endpoint    VARCHAR(24)  NOT NULL COMMENT 'push | pull | reserve | media',
  requests    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (hour_start, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'pin_key_check');
SET @sql := IF(@has = 0,
  'ALTER TABLE businesses ADD COLUMN pin_key_check CHAR(16) NULL COMMENT ''Id of the key counters seal staff PIN hashes with (never the key)'' AFTER seed_completed_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE plans SET max_devices = 3  WHERE code = 'advance'    AND max_devices = 1;
UPDATE plans SET max_devices = 10 WHERE code = 'all_in_one' AND max_devices = 1;
