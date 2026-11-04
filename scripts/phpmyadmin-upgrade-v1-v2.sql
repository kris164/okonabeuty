-- One-time phpMyAdmin import for an initialized MySQL 8.4+ v1 database.
-- Export ALL tables (structure + data) first. Stop every API/cron writer.
-- Select the application database before importing this entire file.
-- STOP ON THE FIRST ERROR. Never continue/retry after partial DDL;
-- keep the application offline and restore the backup or inspect/repair it.
-- Not for a fresh database, MariaDB, v2, or an interrupted migration.
-- Existing bookings, blocks and metadata are preserved, not reinitialized.
-- Keep this file private, outside the public document root.

CREATE TEMPORARY TABLE misseye_upgrade_guard (
    ok TINYINT NOT NULL,
    CONSTRAINT misseye_upgrade_precondition CHECK (ok = 1)
) ENGINE=InnoDB;

-- A failed precondition raises a CHECK constraint error: STOP the import.
INSERT INTO misseye_upgrade_guard (ok)
SELECT IF(DATABASE() IS NOT NULL
    AND VERSION() NOT LIKE '%MariaDB%'
    AND (CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) > 8
        OR (CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) = 8
            AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(VERSION(), '.', 2), '.', -1) AS UNSIGNED) >= 4)), 1, 0);

SET @misseye_lock = CONCAT('misseye-schema-', LEFT(SHA2(DATABASE(), 256), 48));
INSERT INTO misseye_upgrade_guard (ok)
SELECT IF(GET_LOCK(@misseye_lock, 5) = 1, 1, 0);

INSERT INTO misseye_upgrade_guard (ok)
SELECT IF((SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND engine = 'InnoDB'
    AND table_name IN ('misseye_state', 'misseye_bookings', 'misseye_blocks', 'misseye_rates')) = 4
    AND (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
    AND table_name IN ('misseye_calendars', 'misseye_migrations')) = 0, 1, 0);

INSERT INTO misseye_upgrade_guard (ok)
SELECT IF(COUNT(*) = 2 AND SUM(id IN (1, 2) AND schema_version = 1) = 2
    AND SUM(id = 1 AND document IS NOT NULL) = 1, 1, 0)
FROM misseye_state;

SET @misseye_bookings_before = (SELECT COUNT(*) FROM misseye_bookings);
SET @misseye_blocks_before = (SELECT COUNT(*) FROM misseye_blocks);
SET @misseye_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@misseye_sql_mode, ''), 'NO_AUTO_VALUE_ON_ZERO');

CREATE TABLE misseye_migrations (
    version INT UNSIGNED NOT NULL PRIMARY KEY,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) NOT NULL,
    applied_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- SHA-256 of the original migration files with CRLF normalized to LF.
INSERT INTO misseye_migrations (version, checksum, status, applied_at)
VALUES (1, '82858222f917f8842935f5b7a60821452d25ab9c653e85718e92cb90923d7052', 'applied', CURRENT_TIMESTAMP);
INSERT INTO misseye_migrations (version, checksum, status)
VALUES (2, 'a6b1eb76b67826b372b144516efe6e092b33a38953518a7337fc0a6b43706fb3', 'applying');

-- BEGIN exact contents of migrations/002-scoped-calendars.sql
CREATE TABLE misseye_calendars (
    specialist_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

ALTER TABLE misseye_bookings
    ADD UNIQUE KEY misseye_booking_order (position),
    MODIFY position BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD notification VARCHAR(16) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.notification'))) STORED,
    ADD notification_started_at BIGINT GENERATED ALWAYS AS (CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.notification_started_at')), 'null') AS SIGNED)) STORED,
    ADD email_notification VARCHAR(16) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.email_notification'))) STORED,
    ADD email_started_at BIGINT GENERATED ALWAYS AS (CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.email_started_at')), 'null') AS SIGNED)) STORED,
    ADD KEY misseye_booking_dates (starts),
    ADD KEY misseye_whatsapp_queue (notification, status, starts),
    ADD KEY misseye_whatsapp_stale (notification, notification_started_at),
    ADD KEY misseye_email_queue (email_notification, status, starts),
    ADD KEY misseye_email_stale (email_notification, email_started_at);

ALTER TABLE misseye_blocks
    ADD UNIQUE KEY misseye_block_order (position),
    MODIFY position BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD KEY misseye_block_end (specialist_id, status, ends, starts),
    ADD KEY misseye_block_dates (status, ends, starts);

INSERT INTO misseye_calendars (specialist_id) VALUES ('pitstop-1'), ('misseye-1');

INSERT INTO misseye_calendars (specialist_id)
    SELECT DISTINCT specialist_id FROM misseye_bookings
    ON DUPLICATE KEY UPDATE specialist_id = misseye_calendars.specialist_id;

INSERT INTO misseye_calendars (specialist_id)
    SELECT DISTINCT specialist_id FROM misseye_blocks
    ON DUPLICATE KEY UPDATE specialist_id = misseye_calendars.specialist_id;
-- END exact contents of migrations/002-scoped-calendars.sql

SET SESSION sql_mode = @misseye_sql_mode;

-- DDL above cannot be rolled back. Publish v2 only after successful DDL.
START TRANSACTION;
INSERT INTO misseye_upgrade_guard (ok)
SELECT IF((SELECT COUNT(*) FROM misseye_bookings) = @misseye_bookings_before
    AND (SELECT COUNT(*) FROM misseye_blocks) = @misseye_blocks_before, 1, 0);
UPDATE misseye_state SET schema_version = 2;
UPDATE misseye_migrations SET status = 'applied', applied_at = CURRENT_TIMESTAMP WHERE version = 2;
COMMIT;

SELECT id, schema_version, document IS NOT NULL AS initialized FROM misseye_state ORDER BY id;
SELECT version, checksum, status FROM misseye_migrations ORDER BY version;
SELECT COUNT(*) AS bookings_after FROM misseye_bookings;
SELECT COUNT(*) AS blocks_after FROM misseye_blocks;
SELECT RELEASE_LOCK(@misseye_lock) AS migration_lock_released;
DROP TEMPORARY TABLE misseye_upgrade_guard;