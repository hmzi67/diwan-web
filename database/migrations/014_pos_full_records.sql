-- 014_pos_full_records.sql
-- Forward-compatible mirror for the complete non-sensitive POS read model.
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
