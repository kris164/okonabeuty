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
