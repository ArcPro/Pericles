ALTER TABLE api_sessions
    ADD COLUMN IF NOT EXISTS device_id BIGINT UNSIGNED NULL AFTER user_id;

ALTER TABLE api_sessions
    ADD KEY idx_api_sessions_device (device_id);

CREATE TABLE IF NOT EXISTS games (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    short_description VARCHAR(500) NOT NULL,
    image_url VARCHAR(2048) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_games_slug (slug),
    KEY idx_games_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('game', 'bundle') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_games (
    product_id BIGINT UNSIGNED NOT NULL,
    game_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, game_id),
    KEY idx_product_games_game (game_id),
    CONSTRAINT fk_product_games_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_games_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    duration_days INT UNSIGNED NULL,
    is_lifetime TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_product_slug (product_id, slug),
    KEY idx_plans_product (product_id),
    KEY idx_plans_active (is_active),
    CONSTRAINT fk_plans_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active', 'cancelled', 'expired', 'suspended') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    bound_device_id BIGINT UNSIGNED NULL,
    bound_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    suspended_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscriptions_user_product (user_id, product_id),
    KEY idx_subscriptions_user (user_id),
    KEY idx_subscriptions_product (product_id),
    KEY idx_subscriptions_bound_device (bound_device_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscriptions_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscriptions_bound_device FOREIGN KEY (bound_device_id) REFERENCES devices(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activation_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_hash CHAR(64) NOT NULL,
    key_hint VARCHAR(16) NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('unused', 'redeemed', 'revoked', 'expired') NOT NULL DEFAULT 'unused',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    redeemed_at DATETIME NULL,
    redeemed_by_user_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    transferable TINYINT(1) NOT NULL DEFAULT 0,
    max_transfers INT UNSIGNED NOT NULL DEFAULT 0,
    transfer_count INT UNSIGNED NOT NULL DEFAULT 0,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_activation_keys_hash (key_hash),
    KEY idx_activation_keys_status (status),
    KEY idx_activation_keys_plan (plan_id),
    KEY idx_activation_keys_user (redeemed_by_user_id),
    KEY idx_activation_keys_subscription (subscription_id),
    CONSTRAINT fk_activation_keys_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_activation_keys_user FOREIGN KEY (redeemed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_activation_keys_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_activations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id BIGINT UNSIGNED NOT NULL,
    activation_key_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    activated_at DATETIME NOT NULL,
    previous_expires_at DATETIME NULL,
    new_expires_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_activations_key (activation_key_id),
    KEY idx_subscription_activations_subscription (subscription_id),
    KEY idx_subscription_activations_plan (plan_id),
    CONSTRAINT fk_subscription_activations_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_activations_key FOREIGN KEY (activation_key_id) REFERENCES activation_keys(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_activations_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activation_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_activation_attempts_user_time (user_id, attempted_at),
    KEY idx_activation_attempts_ip_time (ip_address, attempted_at),
    CONSTRAINT fk_activation_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO games (slug, name, short_description, image_url, is_active, sort_order, created_at, updated_at)
VALUES
    ('deadlock', 'Deadlock', 'Shooter tactique en équipe.', 'https://cdn.medal.tv/asset/games/deadlock/thumbnail-1776536278620.jpg?width=800&height=800', 1, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('counter-strike-2', 'Counter-Strike 2', 'FPS compétitif tactique.', 'https://cdn.medal.tv/asset/games/counter-strike-2/cover-1717452418342.jpg?width=800&height=800', 1, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    name = VALUES(name), short_description = VALUES(short_description), image_url = VALUES(image_url),
    is_active = VALUES(is_active), sort_order = VALUES(sort_order), updated_at = UTC_TIMESTAMP();

INSERT INTO products (slug, name, type, is_active, created_at, updated_at)
VALUES
    ('deadlock', 'Deadlock', 'game', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('counter-strike-2', 'Counter-Strike 2', 'game', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    name = VALUES(name), type = VALUES(type), is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP();

INSERT IGNORE INTO product_games (product_id, game_id)
SELECT p.id, g.id FROM products p INNER JOIN games g ON g.slug = p.slug
WHERE p.slug IN ('deadlock', 'counter-strike-2');

INSERT INTO plans (product_id, slug, name, duration_days, is_lifetime, is_active, created_at, updated_at)
SELECT p.id, seed.slug, seed.name, seed.duration_days, seed.is_lifetime, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM products p
INNER JOIN (
    SELECT '30-days' AS slug, '30 jours' AS name, 30 AS duration_days, 0 AS is_lifetime
    UNION ALL SELECT '90-days', '90 jours', 90, 0
    UNION ALL SELECT 'lifetime', 'À vie', NULL, 1
) seed
WHERE p.slug IN ('deadlock', 'counter-strike-2')
ON DUPLICATE KEY UPDATE
    name = VALUES(name), duration_days = VALUES(duration_days), is_lifetime = VALUES(is_lifetime),
    is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP();

ALTER TABLE api_sessions
    ADD CONSTRAINT fk_api_sessions_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL;
