ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS description TEXT NULL;

ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL;

ALTER TABLE coupons
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id INT NOT NULL AUTO_INCREMENT,
    admin_user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY admin_audit_logs_created_index (created_at),
    KEY admin_audit_logs_entity_index (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS store_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

INSERT IGNORE INTO store_settings (setting_key, setting_value) VALUES
    ('shipping_fee', '50'),
    ('free_shipping_threshold', '1000'),
    ('low_stock_threshold', '5');
