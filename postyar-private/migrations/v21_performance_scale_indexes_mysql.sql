-- Wave P: hot-path composite indexes for the canonical MySQL/MariaDB schema.
-- Runtime Bootstrap is the authoritative idempotent runner.

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

CREATE INDEX idx_posts_tenant_status_created ON posts(tenant_id, status, created_at, id);
CREATE INDEX idx_posts_status_scheduled_id ON posts(status, scheduled_at, id);
CREATE INDEX idx_channel_messages_post_status ON channel_messages(post_id, status, channel_id);
CREATE INDEX idx_post_channel_stats_channel_post ON post_channel_stats(channel_id, post_id);
CREATE INDEX idx_clicks_log_channel_post ON clicks_log(channel_id, post_id, clicked_at);
CREATE INDEX idx_link_tracking_tenant_created ON link_tracking(tenant_id, created_at, id);
CREATE INDEX idx_link_clicks_link_ip ON link_clicks(link_id, ip_address);
CREATE INDEX idx_wallet_transactions_user_created ON wallet_transactions(user_id, created_at, id);
CREATE INDEX idx_notifications_user_read_created ON notifications(user_id, is_read, created_at, id);
CREATE INDEX idx_subscriptions_user_status_end ON subscriptions(user_id, status, end_date, id);
CREATE INDEX idx_verification_user_type_active_expiry ON verification_codes(user_id, type, used, expires_at, id);
CREATE INDEX idx_idempotency_status_created ON idempotency_keys(status, created_at, id);
