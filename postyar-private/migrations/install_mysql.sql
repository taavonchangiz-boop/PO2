-- Wave O: singleton bootstrap guard.
CREATE TABLE IF NOT EXISTS system_bootstrap (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    initialized_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO system_bootstrap (id, initialized_at) VALUES (1, NULL);

-- ============================================================
-- اسکیمای کامل دیتابیس پُست‌یار — MySQL
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    status VARCHAR(20) DEFAULT 'active',
    business_name VARCHAR(150) NULL,
    business_type VARCHAR(150) NULL,
    phone VARCHAR(15) NULL,
    referral_code VARCHAR(20) NULL UNIQUE,
    referred_by INT NULL,
    referral_points DECIMAL(15,2) DEFAULT 0,
    wallet_balance DECIMAL(15,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    price DECIMAL(12,2) DEFAULT 0.00,
    duration_days INT DEFAULT 30,
    max_channels INT DEFAULT 1,
    max_posts INT DEFAULT 10,
    features TEXT,
    payment_url TEXT NULL,
    image_url TEXT NULL,
    description TEXT NULL,
    early_renewal_discount INT DEFAULT 0,
    general_discount INT DEFAULT 0,
    discount_badge_text VARCHAR(150) NULL,
    is_featured INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    expiry_reminder_sent INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS channel_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id VARCHAR(150) NOT NULL,
    platform VARCHAR(20) NOT NULL,
    owner_user_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_channel (platform, channel_id),
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    platform VARCHAR(20) NOT NULL,
    channel_id VARCHAR(150) NOT NULL,
    token TEXT NOT NULL,
    link_config TEXT,
    button_config TEXT,
    webhook_active INT DEFAULT 0,
    webhook_secret VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    media_url TEXT,
    status VARCHAR(20) DEFAULT 'draft',
    scheduled_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS channel_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    channel_id INT NOT NULL,
    message_id VARCHAR(100) NOT NULL,
    status VARCHAR(20) DEFAULT 'sent',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_channel_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    channel_id INT NOT NULL,
    clicks INT DEFAULT 0,
    views INT DEFAULT 0,
    UNIQUE KEY uq_post_channel (post_id, channel_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clicks_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    channel_id INT NOT NULL,
    ip VARCHAR(50),
    user_agent TEXT,
    clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    channel_id INT NOT NULL,
    sender_id VARCHAR(100) NOT NULL,
    sender_name VARCHAR(150),
    message_text TEXT,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auto_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    channel_id INT NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    reply_text TEXT NOT NULL,
    active INT DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS discount_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    type VARCHAR(20) DEFAULT 'percent',
    amount DECIMAL(12,2) NOT NULL,
    max_uses INT DEFAULT 0,
    used INT DEFAULT 0,
    expires_at DATETIME,
    active INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS discount_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    type VARCHAR(20) DEFAULT 'percent',
    amount DECIMAL(12,2) NOT NULL,
    expires_at DATETIME,
    used INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    quoted_amount DECIMAL(12,2) NULL,
    discount_code_id INT NULL,
    payment_method VARCHAR(50) DEFAULT 'card_to_card',
    receipt_photo TEXT,
    reference_num VARCHAR(100),
    status VARCHAR(20) DEFAULT 'pending',
    admin_notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
    FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_payments_user_reference (user_id, reference_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL UNIQUE,
    keys_p256dh VARCHAR(255) NOT NULL,
    keys_auth VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    target_section VARCHAR(100) NOT NULL DEFAULT '',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_read(user_id, is_read),
    INDEX idx_notifications_user_created(user_id, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    key_name VARCHAR(100) NOT NULL,
    key_value TEXT,
    UNIQUE KEY uq_tenant_key (tenant_id, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    attempts INT DEFAULT 1,
    last_attempt INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'open',
    attachment TEXT NULL,
    assigned_to INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referrer_id INT NOT NULL,
    referred_id INT NOT NULL UNIQUE,
    referral_code VARCHAR(20) NOT NULL,
    reward_type VARCHAR(20) DEFAULT 'points',
    reward_value DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    rewarded_at DATETIME NULL,
    FOREIGN KEY (referrer_id) REFERENCES users(id),
    FOREIGN KEY (referred_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(30) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    balance_after DECIMAL(15,2) NOT NULL,
    description TEXT,
    reference_type VARCHAR(50),
    reference_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referral_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(50) NOT NULL UNIQUE,
    template_name VARCHAR(100) NOT NULL,
    template_id VARCHAR(50) NULL,
    parameters TEXT DEFAULT '[]',
    is_active INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NULL,
    phone VARCHAR(15) NOT NULL,
    user_id INT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    response_code VARCHAR(50) NULL,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(50) NOT NULL UNIQUE,
    template_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html TEXT NOT NULL,
    variables TEXT DEFAULT '[]',
    is_active INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NULL,
    to_address VARCHAR(255) NOT NULL,
    user_id INT NULL,
    subject VARCHAR(255),
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS link_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    original_url TEXT NOT NULL,
    post_id INT NOT NULL,
    channel_id INT NOT NULL,
    tenant_id INT NOT NULL,
    total_clicks INT DEFAULT 0,
    unique_clicks INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (channel_id) REFERENCES channels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS link_clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referer TEXT,
    is_unique INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (link_id) REFERENCES link_tracking(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(20) NOT NULL,
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    used INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ایندکس‌ها
CREATE INDEX idx_channels_tenant_id ON channels(tenant_id);
CREATE INDEX idx_channels_platform_cid ON channels(platform, channel_id);
CREATE INDEX idx_posts_tenant_id ON posts(tenant_id);
CREATE INDEX idx_posts_status ON posts(status);
CREATE INDEX idx_posts_scheduled ON posts(scheduled_at);
CREATE INDEX idx_subscriptions_user ON subscriptions(user_id, status);
CREATE INDEX idx_subscriptions_end_date ON subscriptions(end_date);
CREATE INDEX idx_inbox_tenant ON inbox(tenant_id, channel_id);
CREATE INDEX idx_auto_replies_tenant ON auto_replies(tenant_id, channel_id, active);
CREATE INDEX idx_settings_tenant_key ON settings(tenant_id, key_name);
CREATE INDEX idx_rate_limits_ip_action ON rate_limits(ip, action);
CREATE INDEX idx_clicks_log_post ON clicks_log(post_id, channel_id);
CREATE INDEX idx_channel_messages_post ON channel_messages(post_id, channel_id);
CREATE INDEX idx_payments_status ON payments(status);
CREATE INDEX idx_payments_user ON payments(user_id);
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_user ON tickets(user_id);
CREATE INDEX idx_referrals_referrer ON referrals(referrer_id);
CREATE INDEX idx_referrals_referred ON referrals(referred_id);
CREATE INDEX idx_wallet_transactions_user ON wallet_transactions(user_id);
CREATE INDEX idx_sms_log_phone ON sms_log(phone);
CREATE INDEX idx_email_log_user ON email_log(user_id);
CREATE INDEX idx_link_tracking_code ON link_tracking(code);
CREATE INDEX idx_link_clicks_link ON link_clicks(link_id);
CREATE INDEX idx_verification_codes_user ON verification_codes(user_id, type);

-- ============================================================
-- ضدتقلب و Idempotency — Wave H
-- Claims عمداً با حذف کاربر پاک نمی‌شوند.
-- ============================================================
CREATE TABLE IF NOT EXISTS anti_abuse_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_type VARCHAR(50) NOT NULL,
    identity_hash CHAR(64) NOT NULL,
    user_id INT NULL,
    metadata TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_anti_abuse_claim (claim_type, identity_hash),
    INDEX idx_anti_abuse_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS idempotency_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    operation VARCHAR(80) NOT NULL,
    idem_key VARCHAR(128) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'processing',
    resource_id INT NULL,
    response_json LONGTEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_idempotency (user_id, operation, idem_key),
    INDEX idx_idempotency_created (created_at),
    CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v15 security indexes (existing installations receive them through Bootstrap migration)
CREATE UNIQUE INDEX uq_rate_limits_ip_action ON rate_limits(ip, action);
CREATE INDEX idx_verification_user_type_used ON verification_codes(user_id, type, used, created_at);
CREATE UNIQUE INDEX uq_users_phone ON users(phone);

CREATE UNIQUE INDEX uq_channel_messages_post_channel ON channel_messages(post_id, channel_id);

-- Wave R: advertising schema for fresh MySQL installs.
CREATE TABLE IF NOT EXISTS ad_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    image_url TEXT NOT NULL,
    destination_url TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    approved_by INT NULL,
    CONSTRAINT fk_ads_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ads_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ads_active_window(status, starts_at, ends_at, id),
    INDEX idx_ads_owner_status(owner_user_id, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ad_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(20) NOT NULL,
    fingerprint_hash CHAR(64) NOT NULL,
    user_id INT NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ad_events_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ad_events_campaign_type_time(campaign_id, event_type, occurred_at, id),
    INDEX idx_ad_events_fingerprint(campaign_id, event_type, fingerprint_hash, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ad_daily_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    stat_date DATE NOT NULL,
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unique_impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unique_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ad_daily_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
    UNIQUE KEY uq_ad_daily_campaign_date(campaign_id, stat_date),
    INDEX idx_ad_daily_campaign_date(campaign_id, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
