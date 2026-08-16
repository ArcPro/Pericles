CREATE TABLE IF NOT EXISTS modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(120) NOT NULL,
    name VARCHAR(180) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_modules_slug (slug),
    KEY idx_modules_game_active (game_id, is_active),
    CONSTRAINT fk_modules_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    version VARCHAR(64) NOT NULL,
    source_path VARCHAR(500) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    status ENUM('draft', 'active', 'disabled') NOT NULL DEFAULT 'draft',
    minimum_launcher_version VARCHAR(32) NULL,
    created_at DATETIME NOT NULL,
    published_at DATETIME NULL,
    disabled_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_module_versions_module_version (module_id, version),
    KEY idx_module_versions_active (module_id, status, published_at),
    CONSTRAINT fk_module_versions_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    game_id BIGINT UNSIGNED NOT NULL,
    module_version_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    request_ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_module_tickets_hash (ticket_hash),
    KEY idx_module_tickets_user_created (user_id, created_at),
    KEY idx_module_tickets_device_created (device_id, created_at),
    KEY idx_module_tickets_expiry (expires_at),
    KEY idx_module_tickets_version (module_version_id),
    CONSTRAINT fk_module_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_module_tickets_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_module_tickets_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT,
    CONSTRAINT fk_module_tickets_version FOREIGN KEY (module_version_id) REFERENCES module_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_downloads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    game_id BIGINT UNSIGNED NOT NULL,
    module_version_id BIGINT UNSIGNED NOT NULL,
    module_ticket_id BIGINT UNSIGNED NOT NULL,
    status ENUM('started', 'completed', 'failed') NOT NULL,
    requested_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_module_downloads_ticket (module_ticket_id),
    KEY idx_module_downloads_user_requested (user_id, requested_at),
    KEY idx_module_downloads_device_requested (device_id, requested_at),
    KEY idx_module_downloads_status (status),
    CONSTRAINT fk_module_downloads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_module_downloads_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_module_downloads_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT,
    CONSTRAINT fk_module_downloads_version FOREIGN KEY (module_version_id) REFERENCES module_versions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_module_downloads_ticket FOREIGN KEY (module_ticket_id) REFERENCES module_tickets(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_request_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    request_type ENUM('ticket', 'download') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    requested_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_module_attempts_user_type_time (user_id, request_type, requested_at),
    KEY idx_module_attempts_device_type_time (device_id, request_type, requested_at),
    KEY idx_module_attempts_ip_type_time (ip_address, request_type, requested_at),
    CONSTRAINT fk_module_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_module_attempts_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO modules (game_id, slug, name, is_active, created_at, updated_at)
SELECT g.id, CONCAT(g.slug, '-main'), CONCAT(g.name, ' Main Module'), 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM games g
WHERE g.slug IN ('deadlock', 'counter-strike-2')
ON DUPLICATE KEY UPDATE
    game_id = VALUES(game_id), name = VALUES(name), updated_at = UTC_TIMESTAMP();
