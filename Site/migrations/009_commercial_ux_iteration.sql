ALTER TABLE products
    ADD COLUMN IF NOT EXISTS short_description VARCHAR(500) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS operating_systems VARCHAR(500) NULL AFTER compatibility,
    ADD COLUMN IF NOT EXISTS requirements TEXT NULL AFTER operating_systems,
    ADD COLUMN IF NOT EXISTS included_items_json TEXT NULL AFTER requirements,
    ADD COLUMN IF NOT EXISTS is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public;

UPDATE products p
INNER JOIN product_games pg ON pg.product_id = p.id
INNER JOIN games g ON g.id = pg.game_id
SET p.short_description = COALESCE(NULLIF(p.short_description, ''), NULLIF(p.description, ''), CONCAT('Premium configurable game enhancement for ', g.name, '.'));

ALTER TABLE product_feature_categories
    ADD COLUMN IF NOT EXISTS description VARCHAR(500) NULL AFTER name;

ALTER TABLE product_features
    ADD COLUMN IF NOT EXISTS is_highlighted TINYINT(1) NOT NULL DEFAULT 0 AFTER short_description;

ALTER TABLE product_media
    ADD COLUMN IF NOT EXISTS thumbnail_url VARCHAR(2048) NULL AFTER url,
    ADD COLUMN IF NOT EXISTS poster_url VARCHAR(2048) NULL AFTER thumbnail_url,
    ADD COLUMN IF NOT EXISTS title VARCHAR(180) NULL AFTER poster_url,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at;

ALTER TABLE product_faqs
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS terms_version VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS terms_accepted_at DATETIME NULL;

ALTER TABLE support_tickets
    MODIFY COLUMN user_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS guest_email VARCHAR(254) NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS product_id BIGINT UNSIGNED NULL AFTER assigned_to_user_id,
    ADD COLUMN IF NOT EXISTS order_id BIGINT UNSIGNED NULL AFTER product_id;

ALTER TABLE support_messages
    MODIFY COLUMN author_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS author_email VARCHAR(254) NULL AFTER author_user_id;

CREATE TABLE IF NOT EXISTS platform_service_status (
    service_key VARCHAR(50) NOT NULL,
    name VARCHAR(120) NOT NULL,
    status ENUM('operational','updating','maintenance','unavailable','discontinued') NOT NULL DEFAULT 'unavailable',
    incident VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (service_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_service_status (service_key,name,status,incident,updated_at) VALUES
    ('platform','Platform','unavailable','Status has not been reported yet.',UTC_TIMESTAMP()),
    ('authentication','Authentication','unavailable','Status has not been reported yet.',UTC_TIMESTAMP()),
    ('launcher','Launcher','unavailable','Status has not been reported yet.',UTC_TIMESTAMP()),
    ('downloads','Downloads','unavailable','Status has not been reported yet.',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO platform_content (content_key,title,body,version,is_published,updated_at) VALUES
    ('terms','Terms','Content pending legal review. Replace this text from the platform content configuration before commercial launch.','draft-1',1,UTC_TIMESTAMP()),
    ('terms-of-sale','Terms of Sale','Content pending legal review. Replace this text from the platform content configuration before commercial launch.','draft-1',1,UTC_TIMESTAMP()),
    ('privacy','Privacy Policy','Content pending legal review. Replace this text from the platform content configuration before commercial launch.','draft-1',1,UTC_TIMESTAMP()),
    ('refund-policy','Refund Policy','Content pending legal review. Replace this text from the platform content configuration before commercial launch.','draft-1',1,UTC_TIMESTAMP()),
    ('cookies','Cookie Policy','Content pending legal review. Replace this text from the platform content configuration before commercial launch.','draft-1',1,UTC_TIMESTAMP()),
    ('legal-notice','Legal Notice','Content pending legal review. Replace this text from the platform content configuration before commercial launch.','draft-1',1,UTC_TIMESTAMP()),
    ('disclaimer','Disclaimer','Pericles is an independent third-party product and is not affiliated with or endorsed by the publishers of the games listed on this website.','1',1,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE title=VALUES(title);
