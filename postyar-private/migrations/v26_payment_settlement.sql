-- Wave T: provider-neutral, exactly-once settlement ledger (SQLite)
CREATE TABLE IF NOT EXISTS payment_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id VARCHAR(64) NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    order_type VARCHAR(30) NOT NULL DEFAULT 'subscription',
    plan_id INTEGER NULL,
    ad_order_id INTEGER NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'IRR',
    provider VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    idempotency_key VARCHAR(128) NOT NULL,
    quote_json TEXT NULL,
    provider_reference VARCHAR(190) NULL,
    provider_payload_hash CHAR(64) NULL,
    paid_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    FOREIGN KEY (ad_order_id) REFERENCES ad_orders(id) ON DELETE SET NULL,
    UNIQUE(user_id, idempotency_key),
    CHECK (amount >= 0)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_orders_provider_reference ON payment_orders(provider, provider_reference) WHERE provider_reference IS NOT NULL AND provider_reference <> '';
CREATE INDEX IF NOT EXISTS idx_payment_orders_user_status ON payment_orders(user_id,status,id);
CREATE INDEX IF NOT EXISTS idx_payment_orders_provider_status ON payment_orders(provider,status,id);
CREATE INDEX IF NOT EXISTS idx_payment_orders_expiry ON payment_orders(status,expires_at);

CREATE TABLE IF NOT EXISTS payment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_order_id INTEGER NOT NULL,
    provider VARCHAR(80) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    provider_reference VARCHAR(190) NULL,
    amount DECIMAL(12,2) NULL,
    payload_hash CHAR(64) NULL,
    outcome VARCHAR(30) NOT NULL,
    error_code VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id) ON DELETE CASCADE,
    UNIQUE(provider,event_key)
);
CREATE INDEX IF NOT EXISTS idx_payment_events_order ON payment_events(payment_order_id,id);
