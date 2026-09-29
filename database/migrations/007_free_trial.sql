-- Temporary no-payment trial onboarding. Apply BEFORE deploying trial code.
-- Idempotent. Existing order enum positions and paid records are unchanged.
ALTER TABLE orders MODIFY COLUMN status
  ENUM('pending','paid','failed','refunded','fulfilled') NOT NULL DEFAULT 'pending';

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

-- Reserved for TrialService; is_active=0 excludes this SKU from paid checkout.
INSERT INTO products (sku, name, description, price_paisa, currency, is_active)
VALUES ('diwan-pos-trial', 'Diwan POS — 30-day free trial',
        'One trial per account, one device, 30 days from issuance. No payment.', 0, 'PKR', 0)
ON DUPLICATE KEY UPDATE sku = VALUES(sku);
