-- =============================================================================
-- 010_github_release_source.sql
--
-- Lets a `releases` row point at an asset on a GitHub release instead of a
-- file in private-storage/releases/. Apply BEFORE deploying the matching
-- DownloadService change.
--
--   releases.source        'local'  -> storage_path under private-storage (as before)
--                          'github' -> github_tag + github_asset on GITHUB_RELEASES_REPO
--   releases.github_tag    release tag, e.g. v1.1.5
--   releases.github_asset  exact asset file name on that release
--   releases.variant       '' for a platform's only build, or e.g.
--                          'apple-silicon' / 'intel' when one platform ships
--                          several builds; the unique key now includes it
--
-- For GitHub rows storage_path is left as ''. Existing rows become 'local'.
-- Nothing is rewritten. Applied to production on: <fill in when run>
-- Idempotent: guarded via information_schema, same pattern as 008.
-- =============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _migration_010;

DELIMITER $$

CREATE PROCEDURE _migration_010()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'releases' AND COLUMN_NAME = 'source'
  ) THEN
    ALTER TABLE releases
      ADD COLUMN source ENUM('local','github') NOT NULL DEFAULT 'local'
        COMMENT 'Where the installer lives' AFTER storage_path,
      ADD COLUMN github_tag VARCHAR(64) NULL
        COMMENT 'GitHub release tag, e.g. v1.1.5' AFTER source,
      ADD COLUMN github_asset VARCHAR(255) NULL
        COMMENT 'Asset file name on that GitHub release' AFTER github_tag;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'releases' AND COLUMN_NAME = 'variant'
  ) THEN
    ALTER TABLE releases
      ADD COLUMN variant VARCHAR(32) NOT NULL DEFAULT ''
        COMMENT '\'\' = only build; else e.g. apple-silicon / intel' AFTER platform;
  END IF;

  -- One row per platform + version + variant (was platform + version).
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'releases'
       AND INDEX_NAME = 'uq_releases_platform_version' AND COLUMN_NAME = 'variant'
  ) THEN
    ALTER TABLE releases
      DROP INDEX uq_releases_platform_version,
      ADD UNIQUE KEY uq_releases_platform_version (platform, version, variant);
  END IF;
END$$

DELIMITER ;

CALL _migration_010();
DROP PROCEDURE _migration_010;
