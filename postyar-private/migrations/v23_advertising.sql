-- Wave R: tenant advertising, approval workflow, immutable event telemetry.
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
