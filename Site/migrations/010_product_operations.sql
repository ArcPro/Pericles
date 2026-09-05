ALTER TABLE support_tickets
    MODIFY COLUMN status ENUM('open','in_progress','awaiting_user','resolved','closed') NOT NULL DEFAULT 'open',
    ADD COLUMN IF NOT EXISTS priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER category,
    ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL AFTER updated_at;

ALTER TABLE support_tickets
    ADD KEY idx_support_priority_status (priority, status, updated_at),
    ADD KEY idx_support_product (product_id),
    ADD KEY idx_support_order (order_id);

ALTER TABLE support_messages
    ADD COLUMN IF NOT EXISTS is_internal_note TINYINT(1) NOT NULL DEFAULT 0 AFTER is_staff_reply;

CREATE TABLE IF NOT EXISTS support_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_support_attachment_ticket (ticket_id, created_at),
    CONSTRAINT fk_support_attachment_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_attachment_message FOREIGN KEY (message_id) REFERENCES support_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_attachment_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE product_media
    ADD COLUMN IF NOT EXISTS is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

ALTER TABLE product_documents
    ADD COLUMN IF NOT EXISTS summary VARCHAR(500) NULL AFTER title;

ALTER TABLE product_changelog
    ADD COLUMN IF NOT EXISTS title VARCHAR(180) NULL AFTER version,
    ADD COLUMN IF NOT EXISTS body MEDIUMTEXT NULL AFTER summary;

CREATE TABLE IF NOT EXISTS application_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    value_type ENUM('string','integer','boolean') NOT NULL DEFAULT 'string',
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_application_setting_actor FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO application_settings (setting_key,setting_value,value_type,is_public,updated_at) VALUES
    ('default_currency','EUR','string',0,UTC_TIMESTAMP()),
    ('support_email','support@pericles.gg','string',1,UTC_TIMESTAMP()),
    ('community_url','','string',1,UTC_TIMESTAMP()),
    ('device_reset_cooldown_days','7','integer',0,UTC_TIMESTAMP()),
    ('public_registration_enabled','1','boolean',0,UTC_TIMESTAMP()),
    ('maintenance_mode','0','boolean',1,UTC_TIMESTAMP()),
    ('email_sender_name','Pericles','string',0,UTC_TIMESTAMP()),
    ('legal_document_version','draft-1','string',0,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO permissions (slug, name) VALUES
    ('access.support_actions', 'Extend customer Access and reset Authorized Devices')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin' AND p.slug = 'access.support_actions';

INSERT INTO product_documents (product_id,slug,title,summary,section,body,access_level,sort_order,is_published,updated_at)
SELECT p.id,'installation','Installation','Install the launcher and prepare the Enhancement.','installation',
    'Download the Pericles Launcher from your account Downloads page. Install it in a user-writable folder, sign in with your Pericles account, and keep Windows fully updated. The launcher will only show Enhancements covered by your current Access.',
    'previous_customer',10,1,UTC_TIMESTAMP()
FROM products p
WHERE NOT EXISTS (SELECT 1 FROM product_documents d WHERE d.product_id=p.id AND d.slug='installation');

INSERT INTO product_documents (product_id,slug,title,summary,section,body,access_level,sort_order,is_published,updated_at)
SELECT p.id,'first-launch','First Launch','Sign in, authorize a device, and start your Access.','getting-started',
    'Open the Pericles Launcher and sign in. Complete Authorized Device verification when prompted, select your Enhancement, and review its current status before downloading. Timed Access starts on first activation and must be activated within the window shown in your account.',
    'previous_customer',20,1,UTC_TIMESTAMP()
FROM products p
WHERE NOT EXISTS (SELECT 1 FROM product_documents d WHERE d.product_id=p.id AND d.slug='first-launch');

INSERT INTO product_documents (product_id,slug,title,summary,section,body,access_level,sort_order,is_published,updated_at)
SELECT p.id,'troubleshooting','Troubleshooting','Resolve common account, download, and device issues.','troubleshooting',
    'Confirm that the Enhancement status is Operational, update the Pericles Launcher, and verify that your Access is active. If a device change prevents access, review the reset date under Authorized Devices. Contact Support with the Enhancement name, order reference, and the exact error message; never include passwords or payment card details.',
    'previous_customer',90,1,UTC_TIMESTAMP()
FROM products p
WHERE NOT EXISTS (SELECT 1 FROM product_documents d WHERE d.product_id=p.id AND d.slug='troubleshooting');
