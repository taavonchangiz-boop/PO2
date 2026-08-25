-- ============================================================
-- اسکیمای کامل دیتابیس پُست‌یار (شامل تمام مایگریشن‌های v2 تا v6)
-- این فایل برای نصب تازه استفاده می‌شود.
-- ============================================================

-- Wave O: singleton bootstrap guard.
CREATE TABLE IF NOT EXISTS system_bootstrap (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    initialized_at DATETIME NULL
);
INSERT OR IGNORE INTO system_bootstrap (id, initialized_at) VALUES (1, NULL);

-- جدول کاربران (شامل مدیران کل و مستاجرین)
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user', -- 'superadmin' یا 'user'
    status VARCHAR(20) DEFAULT 'active', -- 'active'، 'suspended'، 'inactive'
    business_name VARCHAR(150) NULL,
    business_type VARCHAR(150) NULL,
    phone VARCHAR(15) NULL,
    referral_code VARCHAR(20) NULL,
    referred_by INTEGER NULL,
    referral_points DECIMAL(15,2) DEFAULT 0,
    wallet_balance DECIMAL(15,2) DEFAULT 0,
    birthday VARCHAR(10) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code) WHERE referral_code IS NOT NULL;

-- جدول پلن‌های اشتراک (مدیریت توسط مدیر کل)
CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(100) NOT NULL,
    price DECIMAL(12,2) DEFAULT 0.00,
    duration_days INTEGER DEFAULT 30,
    max_channels INTEGER DEFAULT 1,
    max_posts INTEGER DEFAULT 10,
    features TEXT,
    payment_url TEXT,
    image_url TEXT,
    description TEXT,
    early_renewal_discount INTEGER DEFAULT 0,
    general_discount INTEGER DEFAULT 0,
    discount_badge_text VARCHAR(150) NULL,
    is_featured INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- جدول اشتراک‌های فعال کاربران
CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    expiry_reminder_sent INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);

-- جدول ثبت جهانی کانال‌ها (ضد تقلب)
CREATE TABLE IF NOT EXISTS channel_registry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id VARCHAR(150) NOT NULL,
    platform VARCHAR(20) NOT NULL,
    owner_user_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(platform, channel_id),
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- جدول کانال‌های اختصاصی هر مستاجر
CREATE TABLE IF NOT EXISTS channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name VARCHAR(150) NOT NULL,
    platform VARCHAR(20) NOT NULL,
    channel_id VARCHAR(150) NOT NULL,
    token TEXT NOT NULL,
    link_config TEXT,
    button_config TEXT,
    webhook_active INTEGER DEFAULT 0,
    webhook_secret VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE
);

-- جدول پست‌های تولیدشده توسط کاربران
CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    media_url TEXT,
    status VARCHAR(20) DEFAULT 'draft',
    scheduled_at DATETIME,
    target_channels TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE
);

-- جدول پیگیری پیام‌های ارسال‌شده
CREATE TABLE IF NOT EXISTS channel_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    message_id VARCHAR(100) NOT NULL,
    status VARCHAR(20) DEFAULT 'sent',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

-- جدول آمار کلیک و بازدید پست‌ها
CREATE TABLE IF NOT EXISTS post_channel_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    clicks INTEGER DEFAULT 0,
    views INTEGER DEFAULT 0,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

-- جدول لاگ جزئیات کلیک‌ها
CREATE TABLE IF NOT EXISTS clicks_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    ip VARCHAR(50),
    user_agent TEXT,
    clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

-- جدول صندوق پیام
CREATE TABLE IF NOT EXISTS inbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    sender_id VARCHAR(100) NOT NULL,
    sender_name VARCHAR(150),
    message_text TEXT,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

-- جدول پاسخ‌های خودکار
CREATE TABLE IF NOT EXISTS auto_replies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    reply_text TEXT NOT NULL,
    active INTEGER DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

-- جدول کدهای تخفیف عمومی
CREATE TABLE IF NOT EXISTS discount_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    type VARCHAR(20) DEFAULT 'percent',
    amount DECIMAL(12,2) NOT NULL,
    max_uses INTEGER DEFAULT 0,
    used INTEGER DEFAULT 0,
    expires_at DATETIME,
    active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- جدول تخفیف‌های اختصاصی
CREATE TABLE IF NOT EXISTS discount_offers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    type VARCHAR(20) DEFAULT 'percent',
    amount DECIMAL(12,2) NOT NULL,
    expires_at DATETIME,
    used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);

-- جدول پرداخت‌ها
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    quoted_amount DECIMAL(12,2) NULL,
    discount_code_id INTEGER,
    payment_method VARCHAR(50) DEFAULT 'card_to_card',
    receipt_photo TEXT,
    reference_num VARCHAR(100),
    status VARCHAR(20) DEFAULT 'pending',
    admin_notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
    FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_payments_user_reference ON payments(user_id, reference_num) WHERE reference_num IS NOT NULL AND reference_num <> '';

-- جدول وب پوش
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    endpoint TEXT NOT NULL UNIQUE,
    keys_p256dh VARCHAR(255) NOT NULL,
    keys_auth VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- جدول تنظیمات
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    key_name VARCHAR(100) NOT NULL,
    key_value TEXT,
    UNIQUE(tenant_id, key_name)
);

-- جدول محدودیت نرخ درخواست‌ها
CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    attempts INTEGER DEFAULT 1,
    last_attempt INTEGER NOT NULL
);

-- جدول تیکت‌های پشتیبانی
CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    subject VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'open',
    attachment TEXT NULL,
    assigned_to INTEGER NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---- فاز ۲: سیستم زیرمجموعه‌گیری و کیف پول ----

-- جدول زیرمجموعه‌ها
CREATE TABLE IF NOT EXISTS referrals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    referrer_id INTEGER NOT NULL,
    referred_id INTEGER NOT NULL UNIQUE,
    referral_code VARCHAR(20) NOT NULL,
    reward_type VARCHAR(20) DEFAULT 'points',
    reward_value DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    rewarded_at DATETIME NULL,
    FOREIGN KEY (referrer_id) REFERENCES users(id),
    FOREIGN KEY (referred_id) REFERENCES users(id)
);

-- جدول تراکنش‌های کیف پول
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type VARCHAR(30) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    balance_after DECIMAL(15,2) NOT NULL,
    description TEXT,
    reference_type VARCHAR(50),
    reference_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- جدول تنظیمات سیستم زیرمجموعه‌گیری
CREATE TABLE IF NOT EXISTS referral_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL
);

-- ---- فاز ۳: سیستم پیامک (SMS.ir) ----

-- جدول قالب‌های پیامک
CREATE TABLE IF NOT EXISTS sms_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key VARCHAR(50) NOT NULL UNIQUE,
    template_name VARCHAR(100) NOT NULL,
    template_id VARCHAR(50) NULL,
    parameters TEXT DEFAULT '[]',
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- جدول لاگ ارسال پیامک
CREATE TABLE IF NOT EXISTS sms_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NULL,
    phone VARCHAR(15) NOT NULL,
    user_id INTEGER NULL,
    status VARCHAR(20) DEFAULT 'pending',
    response_code VARCHAR(50) NULL,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ---- فاز ۴: سیستم ایمیل ----

-- جدول قالب‌های ایمیل
CREATE TABLE IF NOT EXISTS email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key VARCHAR(50) NOT NULL UNIQUE,
    template_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html TEXT NOT NULL,
    variables TEXT DEFAULT '[]',
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- جدول لاگ ارسال ایمیل
CREATE TABLE IF NOT EXISTS email_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NULL,
    to_address VARCHAR(255) NOT NULL,
    user_id INTEGER NULL,
    subject VARCHAR(255),
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ---- فاز ۵: ردیابی لینک و بازیابی رمز ----

-- جدول ردیابی لینک
CREATE TABLE IF NOT EXISTS link_tracking (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(20) NOT NULL UNIQUE,
    original_url TEXT NOT NULL,
    post_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    tenant_id INTEGER NOT NULL,
    total_clicks INTEGER DEFAULT 0,
    unique_clicks INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (channel_id) REFERENCES channels(id)
);

-- جدول کلیک‌های لینک
CREATE TABLE IF NOT EXISTS link_clicks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id INTEGER NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referer TEXT,
    is_unique INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (link_id) REFERENCES link_tracking(id)
);

-- جدول کدهای تایید (SMS بازیابی رمز)
CREATE TABLE IF NOT EXISTS verification_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type VARCHAR(20) NOT NULL,
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ---- تیکت‌ها: ریپلای‌ها ----
CREATE TABLE IF NOT EXISTS ticket_replies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    message TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);

-- جدول لاگ پاسخگوی خودکار
CREATE TABLE IF NOT EXISTS responder_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    channel_id INTEGER NULL,
    sender_id VARCHAR(100) DEFAULT '',
    sender_name VARCHAR(200) DEFAULT '',
    message_text TEXT DEFAULT '',
    matched_keyword VARCHAR(255) DEFAULT '',
    reply_sent INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- جدول اعلان‌های کاربر
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    title TEXT NOT NULL,
    message TEXT DEFAULT '',
    target_section VARCHAR(100) DEFAULT '',
    is_read INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---- ایندکس‌های بهینه‌سازی ----
CREATE INDEX IF NOT EXISTS idx_channels_tenant_id ON channels(tenant_id);
CREATE INDEX IF NOT EXISTS idx_channels_platform_cid ON channels(platform, channel_id);
CREATE INDEX IF NOT EXISTS idx_posts_tenant_id ON posts(tenant_id);
CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status);
CREATE INDEX IF NOT EXISTS idx_posts_scheduled ON posts(scheduled_at);
CREATE INDEX IF NOT EXISTS idx_subscriptions_user ON subscriptions(user_id, status);
CREATE INDEX IF NOT EXISTS idx_subscriptions_end_date ON subscriptions(end_date);
CREATE INDEX IF NOT EXISTS idx_inbox_tenant ON inbox(tenant_id, channel_id);
CREATE INDEX IF NOT EXISTS idx_auto_replies_tenant ON auto_replies(tenant_id, channel_id, active);
CREATE INDEX IF NOT EXISTS idx_settings_tenant_key ON settings(tenant_id, key_name);
CREATE INDEX IF NOT EXISTS idx_rate_limits_ip_action ON rate_limits(ip, action);
CREATE INDEX IF NOT EXISTS idx_clicks_log_post ON clicks_log(post_id, channel_id);
CREATE INDEX IF NOT EXISTS idx_channel_messages_post ON channel_messages(post_id, channel_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_channel_messages_post_channel ON channel_messages(post_id, channel_id);
CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status);
CREATE INDEX IF NOT EXISTS idx_payments_user ON payments(user_id);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status);
CREATE INDEX IF NOT EXISTS idx_tickets_user ON tickets(user_id);
CREATE INDEX IF NOT EXISTS idx_referrals_referrer ON referrals(referrer_id);
CREATE INDEX IF NOT EXISTS idx_referrals_referred ON referrals(referred_id);
CREATE INDEX IF NOT EXISTS idx_wallet_transactions_user ON wallet_transactions(user_id);
CREATE INDEX IF NOT EXISTS idx_sms_log_phone ON sms_log(phone);
CREATE INDEX IF NOT EXISTS idx_email_log_user ON email_log(user_id);
CREATE INDEX IF NOT EXISTS idx_link_tracking_code ON link_tracking(code);
CREATE INDEX IF NOT EXISTS idx_link_clicks_link ON link_clicks(link_id);
CREATE INDEX IF NOT EXISTS idx_verification_codes_user ON verification_codes(user_id, type);
CREATE INDEX IF NOT EXISTS idx_ticket_replies_ticket ON ticket_replies(ticket_id);
CREATE INDEX IF NOT EXISTS idx_responder_logs_tenant ON responder_logs(tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications(user_id, created_at DESC);

-- ============================================================
-- ضدتقلب و Idempotency — Wave H
-- این Claims عمداً با حذف کاربر پاک نمی‌شوند.
-- ============================================================
CREATE TABLE IF NOT EXISTS anti_abuse_claims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    claim_type VARCHAR(50) NOT NULL,
    identity_hash VARCHAR(64) NOT NULL,
    user_id INTEGER NULL,
    metadata TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(claim_type, identity_hash)
);
CREATE INDEX IF NOT EXISTS idx_anti_abuse_user ON anti_abuse_claims(user_id);

CREATE TABLE IF NOT EXISTS idempotency_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    operation VARCHAR(80) NOT NULL,
    idem_key VARCHAR(128) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'processing',
    resource_id INTEGER NULL,
    response_json TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, operation, idem_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_idempotency_created ON idempotency_keys(created_at);

-- v15 security indexes (idempotent migration also applies these to existing installs)
CREATE UNIQUE INDEX IF NOT EXISTS uq_rate_limits_ip_action ON rate_limits(ip, action);
CREATE INDEX IF NOT EXISTS idx_verification_user_type_used ON verification_codes(user_id, type, used, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_phone ON users(phone);

-- Wave K tenant-isolation / production-scale indexes
CREATE INDEX IF NOT EXISTS idx_channels_tenant_id ON channels(tenant_id, id);
CREATE INDEX IF NOT EXISTS idx_channels_tenant_platform_id ON channels(tenant_id, platform, channel_id);
CREATE INDEX IF NOT EXISTS idx_posts_tenant_status_id ON posts(tenant_id, status, id);
CREATE INDEX IF NOT EXISTS idx_posts_tenant_scheduled ON posts(tenant_id, status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_tickets_user_id_id ON tickets(user_id, id);
CREATE INDEX IF NOT EXISTS idx_payments_user_id_id ON payments(user_id, id);
CREATE INDEX IF NOT EXISTS idx_link_tracking_tenant_id_id ON link_tracking(tenant_id, id);
CREATE INDEX IF NOT EXISTS idx_auto_replies_tenant_channel_id ON auto_replies(tenant_id, channel_id, id);

-- Mobile API authentication must be installed automatically; mobile_api.sql is retained as a standalone reference.
CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    device_name VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_api_tokens_user_active ON api_tokens(user_id, revoked_at, expires_at);
CREATE INDEX IF NOT EXISTS idx_api_tokens_expires ON api_tokens(expires_at);

-- Wave R: advertising schema for fresh installs (also applied by v23 migration on upgrades).
CREATE TABLE IF NOT EXISTS ad_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NOT NULL,
    title VARCHAR(180) NOT NULL,
    image_url TEXT NOT NULL,
    destination_url TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    approved_by INTEGER NULL,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CHECK (ends_at > starts_at)
);
CREATE TABLE IF NOT EXISTS ad_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    event_type VARCHAR(20) NOT NULL,
    fingerprint_hash CHAR(64) NOT NULL,
    user_id INTEGER NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS ad_daily_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    stat_date DATE NOT NULL,
    impressions INTEGER NOT NULL DEFAULT 0,
    unique_impressions INTEGER NOT NULL DEFAULT 0,
    clicks INTEGER NOT NULL DEFAULT 0,
    unique_clicks INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
    UNIQUE(campaign_id, stat_date)
);
CREATE INDEX IF NOT EXISTS idx_ads_active_window ON ad_campaigns(status, starts_at, ends_at, id);
CREATE INDEX IF NOT EXISTS idx_ads_owner_status ON ad_campaigns(owner_user_id, status, id);
CREATE INDEX IF NOT EXISTS idx_ad_events_campaign_type_time ON ad_events(campaign_id, event_type, occurred_at, id);
CREATE INDEX IF NOT EXISTS idx_ad_events_fingerprint ON ad_events(campaign_id, event_type, fingerprint_hash, occurred_at);
CREATE UNIQUE INDEX IF NOT EXISTS uq_ad_event_fingerprint ON ad_events(campaign_id, event_type, fingerprint_hash);
CREATE INDEX IF NOT EXISTS idx_ad_daily_campaign_date ON ad_daily_stats(campaign_id, stat_date);
