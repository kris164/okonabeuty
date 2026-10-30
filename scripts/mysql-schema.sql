CREATE TABLE IF NOT EXISTS misseye_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    schema_version INT UNSIGNED NOT NULL,
    document LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    CONSTRAINT misseye_state_json CHECK (document IS NULL OR JSON_VALID(document)),
    CONSTRAINT misseye_state_id CHECK (id IN (1, 2))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS misseye_bookings (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position BIGINT UNSIGNED NOT NULL,
    specialist_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starts BIGINT NOT NULL,
    ends BIGINT NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    UNIQUE KEY misseye_request_id (request_id),
    KEY misseye_booking_calendar (specialist_id, status, starts, ends),
    CONSTRAINT misseye_booking_interval CHECK (ends > starts),
    CONSTRAINT misseye_booking_status CHECK (status IN ('confirmed', 'cancelled')),
    CONSTRAINT misseye_booking_json CHECK (JSON_VALID(payload))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS misseye_blocks (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    position BIGINT UNSIGNED NOT NULL,
    specialist_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starts BIGINT NOT NULL,
    ends BIGINT NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    KEY misseye_block_calendar (specialist_id, status, starts, ends),
    CONSTRAINT misseye_block_interval CHECK (ends > starts),
    CONSTRAINT misseye_block_status CHECK (status IN ('active', 'cancelled')),
    CONSTRAINT misseye_block_json CHECK (JSON_VALID(payload))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS misseye_rates (
    client_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    expires BIGINT NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    KEY misseye_rate_expiry (expires)
) ENGINE=InnoDB;

INSERT INTO misseye_state (id, schema_version, document) VALUES (1, 1, NULL), (2, 1, NULL)
ON DUPLICATE KEY UPDATE id = misseye_state.id;
