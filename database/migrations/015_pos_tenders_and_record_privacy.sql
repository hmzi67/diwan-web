-- =============================================================================
-- 015_pos_tenders_and_record_privacy.sql
--
-- 1. pos_sales gains the POS's per-sale tender breakdown (cash / card /
--    other / pay-later credit) plus service charge and customer name, so the
--    dashboard can split a "split" or "credit" sale by method instead of
--    counting all of it as "other". Rows synced before this stay NULL and are
--    read through their single payment_method, as before.
-- 2. Scrubs the area-PIN hash out of already-mirrored app_config records.
--    POS builds before this release mirrored app_config whole; a 4-6 digit
--    PIN behind a hash is recoverable offline, so the hash must not be kept.
--    The POS no longer sends it and SyncService drops *_hash fields anyway.
--
-- Applied to production on: <fill in when run>
-- Idempotent: safe to re-run (columns guarded via information_schema, same
-- pattern as 002; the scrub only touches rows that still have the key).
-- =============================================================================

SET NAMES utf8mb4;

DELIMITER $$

CREATE PROCEDURE _migration_015_column(IN col VARCHAR(64), IN ddl VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_sales' AND COLUMN_NAME = col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE pos_sales ADD COLUMN ', ddl);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

CALL _migration_015_column('service_charge_minor', 'service_charge_minor BIGINT NOT NULL DEFAULT 0 AFTER tax_minor');
CALL _migration_015_column('cash_minor', 'cash_minor BIGINT NULL AFTER payment_method');
CALL _migration_015_column('card_minor', 'card_minor BIGINT NULL AFTER cash_minor');
CALL _migration_015_column('other_minor', 'other_minor BIGINT NULL AFTER card_minor');
CALL _migration_015_column('credit_minor', 'credit_minor BIGINT NULL AFTER other_minor');
CALL _migration_015_column('customer_name', 'customer_name VARCHAR(100) NULL AFTER cashier_name');
DROP PROCEDURE _migration_015_column;

UPDATE pos_records
   SET record_json = JSON_REMOVE(record_json, '$.sensitive_area_pin_hash')
 WHERE entity_name = 'app_config'
   AND JSON_VALID(record_json)
   AND JSON_CONTAINS_PATH(record_json, 'one', '$.sensitive_area_pin_hash');
