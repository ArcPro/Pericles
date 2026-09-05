ALTER TABLE support_tickets
    MODIFY COLUMN category ENUM('account','payment','access','device','installation','product','other') NOT NULL;
