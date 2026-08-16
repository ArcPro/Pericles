CREATE TABLE IF NOT EXISTS devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id CHAR(32) NOT NULL,
    public_key TEXT NOT NULL,
    public_key_hash CHAR(64) NOT NULL,
    key_algorithm VARCHAR(32) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NULL,
    verified_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_device_id (device_id),
    KEY idx_devices_user (user_id),
    KEY idx_devices_user_active (user_id, revoked_at),
    CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenge_id CHAR(64) NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    challenge_hash CHAR(64) NOT NULL,
    challenge_data VARBINARY(32) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_challenges_challenge_id (challenge_id),
    KEY idx_device_challenges_device (device_id),
    KEY idx_device_challenges_expiry (expires_at),
    CONSTRAINT fk_device_challenges_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
