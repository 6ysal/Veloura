ALTER TABLE users
    MODIFY email VARCHAR(254) NOT NULL,
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email;

UPDATE users
SET email_verified_at = UTC_TIMESTAMP()
WHERE status = 'active' AND email_verified_at IS NULL;

ALTER TABLE coupons
    ADD COLUMN IF NOT EXISTS assigned_email VARCHAR(254) NULL,
    ADD COLUMN IF NOT EXISTS starts_at DATETIME NULL;

ALTER TABLE newsletter_subscribers
    MODIFY email VARCHAR(254) NOT NULL,
    ADD COLUMN IF NOT EXISTS coupon_id INT NULL;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY email_verification_token_hash_unique (token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id INT NOT NULL AUTO_INCREMENT,
    coupon_id INT NOT NULL,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    redeemed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY coupon_redemption_user_unique (coupon_id, user_id),
    UNIQUE KEY coupon_redemption_order_unique (order_id)
) ENGINE=InnoDB;
