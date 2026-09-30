-- =============================================================================
-- 012_linux_platform.sql
--
-- Adds 'linux' to releases.platform so the dashboard can offer the Linux
-- builds (.AppImage / .deb / .rpm) the installer releases already contain.
-- Each format is a variant ('appimage', 'deb', 'rpm'), the same way macOS
-- ships 'apple-silicon' / 'intel'. Windows 32-bit needs no schema change: it
-- is the variant 'x86' next to 'x64'.
--
-- Apply BEFORE deploying the matching code and BEFORE re-running
-- scripts/sync-github-release.php, whose SQL inserts linux rows.
--
-- Applied to production on: <fill in when run>
-- Idempotent: guarded via information_schema, same pattern as 010.
-- =============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _migration_012;

DELIMITER $$

CREATE PROCEDURE _migration_012()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'releases'
       AND COLUMN_NAME = 'platform' AND COLUMN_TYPE LIKE '%''linux''%'
  ) THEN
    ALTER TABLE releases
      MODIFY COLUMN platform ENUM('windows','macos','android','linux') NOT NULL;
  END IF;
END$$

DELIMITER ;

CALL _migration_012();
DROP PROCEDURE _migration_012;
