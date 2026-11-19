CREATE DATABASE IF NOT EXISTS veloura_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE veloura_db;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(20) NULL,
    email_verified_at DATETIME NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'customer',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    store_credit DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY email_verification_token_hash_unique (token_hash),
    CONSTRAINT email_verification_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    image VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY categories_name_unique (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    image VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY products_category_name_unique (category_id, name),
    KEY products_status_created_index (status, created_at),
    CONSTRAINT products_category_fk
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS carts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY carts_user_unique (user_id),
    CONSTRAINT carts_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cart_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY cart_items_product_unique (cart_id, product_id),
    CONSTRAINT cart_items_cart_fk
        FOREIGN KEY (cart_id) REFERENCES carts (id)
        ON DELETE CASCADE,
    CONSTRAINT cart_items_product_fk
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    discount_type VARCHAR(20) NOT NULL,
    discount_value DECIMAL(10, 2) NOT NULL,
    min_order_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    max_uses INT UNSIGNED NULL DEFAULT 1,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    assigned_email VARCHAR(254) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY coupons_code_unique (code),
    KEY coupons_assigned_email_index (assigned_email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    coupon_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY newsletter_subscribers_email_unique (email),
    CONSTRAINT newsletter_subscribers_coupon_fk
        FOREIGN KEY (coupon_id) REFERENCES coupons (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    coupon_id BIGINT UNSIGNED NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    discount_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    shipping_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    final_amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    payment_status VARCHAR(30) NOT NULL,
    order_status VARCHAR(30) NOT NULL,
    shipping_name VARCHAR(150) NOT NULL,
    shipping_phone VARCHAR(20) NOT NULL,
    shipping_address TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY orders_user_created_index (user_id, created_at),
    CONSTRAINT orders_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT orders_coupon_fk
        FOREIGN KEY (coupon_id) REFERENCES coupons (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    redeemed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY coupon_redemption_user_unique (coupon_id, user_id),
    UNIQUE KEY coupon_redemption_order_unique (order_id),
    CONSTRAINT coupon_redemptions_coupon_fk
        FOREIGN KEY (coupon_id) REFERENCES coupons (id),
    CONSTRAINT coupon_redemptions_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT coupon_redemptions_order_fk
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(150) NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (id),
    KEY order_items_order_index (order_id),
    CONSTRAINT order_items_order_fk
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE,
    CONSTRAINT order_items_product_fk
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credit_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    transaction_type VARCHAR(20) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY credit_transactions_user_index (user_id, created_at),
    CONSTRAINT credit_transactions_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT credit_transactions_order_fk
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY contact_messages_created_index (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY admin_audit_logs_created_index (created_at),
    KEY admin_audit_logs_entity_index (entity_type, entity_id),
    CONSTRAINT admin_audit_logs_user_fk
        FOREIGN KEY (admin_user_id) REFERENCES users (id)
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

INSERT IGNORE INTO categories (name) VALUES
    ('Skincare'),
    ('Makeup'),
    ('Fragrance');

INSERT IGNORE INTO products
    (category_id, name, description, price, stock, image)
SELECT c.id, seed.name, seed.description, seed.price, seed.stock, seed.image
FROM (
    SELECT 'Skincare' AS category_name, 'Vitamin C Serum' AS name,
        'A lightweight serum for your daily skincare routine.' AS description,
        450.00 AS price, 25 AS stock, 'vitamin-c.jpg' AS image
    UNION ALL SELECT 'Skincare', 'Hydrating Serum',
        'A gentle serum to complement your skincare routine.',
        390.00, 20, 'serum.jpg'
    UNION ALL SELECT 'Skincare', 'Daily Cleanser',
        'A gentle cleanser for everyday use.',
        280.00, 30, 'cleanser.jpg'
    UNION ALL SELECT 'Skincare', 'Refreshing Face Mist',
        'A refreshing mist for a simple daily routine.',
        320.00, 18, 'mist.jpg'
    UNION ALL SELECT 'Makeup', 'Velvet Lipstick',
        'A classic lipstick for everyday or evening looks.',
        350.00, 24, 'lipstick.jpg'
    UNION ALL SELECT 'Makeup', 'Soft Blush',
        'A buildable blush for a soft touch of color.',
        300.00, 16, 'blush.jpg'
    UNION ALL SELECT 'Makeup', 'Lengthening Mascara',
        'A mascara to complete your everyday makeup look.',
        330.00, 22, 'mascara.jpg'
    UNION ALL SELECT 'Fragrance', 'Signature Perfume',
        'A signature fragrance for your collection.',
        850.00, 12, 'perfume.jpg'
) AS seed
JOIN categories c ON c.name = seed.category_name
WHERE NOT EXISTS (
    SELECT 1
    FROM products p
    WHERE p.category_id = c.id AND p.name = seed.name
);
