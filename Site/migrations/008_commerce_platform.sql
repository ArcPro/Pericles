ALTER TABLE games
    ADD COLUMN IF NOT EXISTS commercial_status ENUM('operational','updating','maintenance','unavailable','discontinued') NOT NULL DEFAULT 'operational' AFTER image_url,
    ADD COLUMN IF NOT EXISTS purchases_allowed TINYINT(1) NOT NULL DEFAULT 1 AFTER commercial_status,
    ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
    ADD COLUMN IF NOT EXISTS status_updated_at DATETIME NULL AFTER updated_at;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER name,
    ADD COLUMN IF NOT EXISTS compatibility VARCHAR(500) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS seo_title VARCHAR(255) NULL AFTER compatibility,
    ADD COLUMN IF NOT EXISTS seo_description VARCHAR(500) NULL AFTER seo_title,
    ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

ALTER TABLE plans
    ADD COLUMN IF NOT EXISTS price_cents INT UNSIGNED NULL AFTER is_lifetime,
    ADD COLUMN IF NOT EXISTS sale_price_cents INT UNSIGNED NULL AFTER price_cents,
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'EUR' AFTER sale_price_cents,
    ADD COLUMN IF NOT EXISTS badge VARCHAR(50) NULL AFTER currency,
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER is_active;

INSERT INTO plans (product_id, slug, name, duration_days, is_lifetime, price_cents, currency, badge, is_active, sort_order, created_at, updated_at)
SELECT p.id, '24-hours', '24 Hours', 1, 0,
    CASE WHEN p.slug = 'deadlock' THEN 399 ELSE 499 END, 'EUR', 'Try it', 1, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM products p WHERE p.slug IN ('deadlock', 'counter-strike-2')
ON DUPLICATE KEY UPDATE name = VALUES(name), duration_days = VALUES(duration_days), is_lifetime = VALUES(is_lifetime),
    price_cents = COALESCE(plans.price_cents, VALUES(price_cents)), currency = VALUES(currency), badge = VALUES(badge),
    sort_order = VALUES(sort_order), updated_at = UTC_TIMESTAMP();

INSERT INTO plans (product_id, slug, name, duration_days, is_lifetime, price_cents, currency, badge, is_active, sort_order, created_at, updated_at)
SELECT p.id, '7-days', '7 Days', 7, 0,
    CASE WHEN p.slug = 'deadlock' THEN 999 ELSE 1299 END, 'EUR', NULL, 1, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM products p WHERE p.slug IN ('deadlock', 'counter-strike-2')
ON DUPLICATE KEY UPDATE name = VALUES(name), duration_days = VALUES(duration_days), is_lifetime = VALUES(is_lifetime),
    price_cents = COALESCE(plans.price_cents, VALUES(price_cents)), currency = VALUES(currency),
    sort_order = VALUES(sort_order), updated_at = UTC_TIMESTAMP();

UPDATE plans pl INNER JOIN products p ON p.id = pl.product_id
SET pl.price_cents = CASE
        WHEN p.slug = 'deadlock' AND pl.slug = '30-days' THEN 1999
        WHEN p.slug = 'deadlock' AND pl.slug = '90-days' THEN 4999
        WHEN p.slug = 'deadlock' AND pl.slug = 'lifetime' THEN 14999
        WHEN p.slug = 'counter-strike-2' AND pl.slug = '30-days' THEN 2499
        WHEN p.slug = 'counter-strike-2' AND pl.slug = '90-days' THEN 5999
        WHEN p.slug = 'counter-strike-2' AND pl.slug = 'lifetime' THEN 17999
        ELSE pl.price_cents END,
    pl.currency = 'EUR',
    pl.badge = CASE WHEN pl.slug = '30-days' THEN 'Most Popular' WHEN pl.slug = '90-days' THEN 'Best Value' ELSE pl.badge END,
    pl.sort_order = CASE WHEN pl.slug = '30-days' THEN 30 WHEN pl.slug = '90-days' THEN 40 WHEN pl.slug = 'lifetime' THEN 50 ELSE pl.sort_order END,
    pl.updated_at = UTC_TIMESTAMP()
WHERE p.slug IN ('deadlock', 'counter-strike-2') AND pl.slug IN ('30-days', '90-days', 'lifetime');

UPDATE products p INNER JOIN product_games pg ON pg.product_id = p.id INNER JOIN games g ON g.id = pg.game_id
SET p.description = COALESCE(p.description, CONCAT('Premium third-party game enhancement software for ', g.name, ' delivered through Pericles.')),
    p.compatibility = COALESCE(p.compatibility, 'Windows 10/11'),
    p.seo_title = COALESCE(p.seo_title, CONCAT(g.name, ' Cheat & Game Enhancement | Pericles')),
    p.seo_description = COALESCE(p.seo_description, CONCAT('Explore the Pericles ', g.name, ' game enhancement, available features, pricing, updates and access plans.'));

CREATE TABLE IF NOT EXISTS checkout_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    source VARCHAR(100) NOT NULL,
    status ENUM('open','converted','expired','cancelled') NOT NULL DEFAULT 'open',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checkout_token_hash (token_hash),
    KEY idx_checkout_user_status (user_id, status),
    KEY idx_checkout_expires (expires_at),
    CONSTRAINT fk_checkout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_checkout_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_checkout_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    checkout_session_id BIGINT UNSIGNED NULL,
    status ENUM('pending','paid','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    product_name_snapshot VARCHAR(180) NOT NULL,
    plan_name_snapshot VARCHAR(150) NOT NULL,
    duration_days_snapshot INT UNSIGNED NULL,
    is_lifetime_snapshot TINYINT(1) NOT NULL DEFAULT 0,
    terms_version VARCHAR(50) NULL,
    immediate_delivery_consent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_number (order_number),
    UNIQUE KEY uq_orders_checkout (checkout_session_id),
    KEY idx_orders_user_created (user_id, created_at),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_checkout FOREIGN KEY (checkout_session_id) REFERENCES checkout_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_reference VARCHAR(255) NULL,
    method VARCHAR(50) NULL,
    status ENUM('pending','paid','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    created_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_provider_reference (provider, provider_reference),
    KEY idx_payments_order (order_id),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    processed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE subscriptions
    MODIFY COLUMN status ENUM('pending','active','cancelled','expired','suspended') NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS plan_id BIGINT UNSIGNED NULL AFTER product_id,
    ADD COLUMN IF NOT EXISTS order_id BIGINT UNSIGNED NULL AFTER plan_id,
    ADD COLUMN IF NOT EXISTS purchased_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS activation_deadline_at DATETIME NULL AFTER purchased_at,
    ADD COLUMN IF NOT EXISTS activated_at DATETIME NULL AFTER activation_deadline_at,
    ADD COLUMN IF NOT EXISTS starts_at DATETIME NULL AFTER activated_at,
    ADD COLUMN IF NOT EXISTS device_reset_available_at DATETIME NULL AFTER bound_at;

CREATE TABLE IF NOT EXISTS product_feature_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_feature_categories_product (product_id, sort_order),
    CONSTRAINT fk_feature_categories_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_features (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    short_description VARCHAR(500) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_features_category (category_id, sort_order),
    CONSTRAINT fk_features_category FOREIGN KEY (category_id) REFERENCES product_feature_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    media_type ENUM('image','gif','video') NOT NULL,
    url VARCHAR(2048) NOT NULL,
    alt_text VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_product_media (product_id, sort_order),
    CONSTRAINT fk_product_media_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(180) NOT NULL,
    section ENUM('installation','getting-started','configuration','features','troubleshooting','faq','changelog') NOT NULL,
    body MEDIUMTEXT NOT NULL,
    access_level ENUM('public','previous_customer','active_access') NOT NULL DEFAULT 'public',
    sort_order INT NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_document_slug (product_id, slug),
    KEY idx_documents_product (product_id, is_published, sort_order),
    CONSTRAINT fk_documents_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_changelog (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    version VARCHAR(64) NOT NULL,
    summary TEXT NOT NULL,
    published_at DATETIME NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_changelog_product_date (product_id, published_at),
    CONSTRAINT fk_changelog_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_faqs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    question VARCHAR(255) NOT NULL,
    answer TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_product_faqs (product_id, sort_order),
    CONSTRAINT fk_product_faq_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_downtimes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    freeze_access TINYINT(1) NOT NULL DEFAULT 0,
    extension_applied_at DATETIME NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_downtime_product_open (product_id, ended_at),
    CONSTRAINT fk_downtime_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_downtime_actor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_reset_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    reset_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_device_resets_user_time (user_id, reset_at),
    CONSTRAINT fk_device_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_device_resets_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_device_resets_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_number VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    assigned_to_user_id BIGINT UNSIGNED NULL,
    subject VARCHAR(180) NOT NULL,
    category ENUM('account','payment','access','device','installation','product','other') NOT NULL,
    status ENUM('open','in_progress','awaiting_user','closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_ticket_number (ticket_number),
    KEY idx_support_user_updated (user_id, updated_at),
    KEY idx_support_status_updated (status, updated_at),
    CONSTRAINT fk_support_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_support_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    is_staff_reply TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_support_messages_ticket (ticket_id, created_at),
    CONSTRAINT fk_support_messages_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_messages_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    request_ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_hash (token_hash),
    KEY idx_password_reset_user (user_id, created_at),
    KEY idx_password_reset_expiry (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_password_attempt_email (email_hash, attempted_at),
    KEY idx_password_attempt_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    discount_type ENUM('percentage','fixed') NOT NULL,
    discount_value INT UNSIGNED NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    max_uses INT UNSIGNED NULL,
    uses_per_user INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_products (
    coupon_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (coupon_id, product_id),
    CONSTRAINT fk_coupon_products_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_plans (
    coupon_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (coupon_id, plan_id),
    CONSTRAINT fk_coupon_plans_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_plans_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_content (
    content_key VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    version VARCHAR(50) NOT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (content_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (slug, name) VALUES
    ('commerce.view', 'View orders and payments'),
    ('commerce.manage', 'Manage pricing and commercial access'),
    ('support.manage', 'Manage support tickets'),
    ('content.manage', 'Manage product content')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin' AND p.slug IN ('commerce.view','commerce.manage','support.manage','content.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('commerce.view','support.manage')
WHERE r.slug = 'moderator';
