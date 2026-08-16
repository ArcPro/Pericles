ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('active', 'disabled') NOT NULL DEFAULT 'active' AFTER password_hash,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE users
    MODIFY COLUMN display_name VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS api_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_ip VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_sessions_token_hash (token_hash),
    KEY idx_api_sessions_user (user_id),
    KEY idx_api_sessions_expires (expires_at),
    CONSTRAINT fk_api_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at),
    KEY idx_login_attempts_email_time (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO api_sessions (token_hash, user_id, created_at, expires_at, last_seen_at)
SELECT token_hash, user_id, created_at, expires_at, created_at
FROM oauth_access_tokens;

DROP TABLE IF EXISTS oauth_authorization_codes;
DROP TABLE IF EXISTS oauth_access_tokens;
DROP TABLE IF EXISTS auth_sessions;
