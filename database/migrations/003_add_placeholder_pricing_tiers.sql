-- =============================================================================
-- 003_add_placeholder_pricing_tiers.sql
--
-- Adds two DUMMY product rows (Starter, Enterprise) alongside the existing
-- diwan-pos-standard, so the redesigned 3-tier pricing page has something
-- real to check out against end-to-end in the meantime.
--
-- These prices and feature lists are PLACEHOLDERS, invented for layout
-- purposes — not real business decisions. Replace price_paisa and the
-- frontend copy in index.html's .plan cards with the actual tiers before
-- launch, then drop this comment.
--
-- Applied to production on: <fill in when run>
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE, safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO products (sku, name, description, price_paisa, currency, is_active)
VALUES
  ('diwan-pos-starter', 'Diwan POS — Starter Licence',
   'PLACEHOLDER: single-terminal licence for a single counter, 1 year of updates.',
   800000, 'PKR', 1),
  ('diwan-pos-enterprise', 'Diwan POS — Enterprise Licence',
   'PLACEHOLDER: multi-branch licence, unlimited terminals, 2 years of updates.',
   2500000, 'PKR', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);
