-- Wave R: tenant advertising, approval workflow, immutable event telemetry (MySQL).
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
    INDEX idx_ad_events_fingerprint(campaign_id, event_type, fingerprint_hash, occurred_at),
    UNIQUE KEY uq_ad_event_fingerprint(campaign_id, event_type, fingerprint_hash)
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
