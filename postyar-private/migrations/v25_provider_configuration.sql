-- Wave R.3 / provider configuration boundary (SQLite)
CREATE TABLE IF NOT EXISTS provider_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_type VARCHAR(30) NOT NULL,
    provider_code VARCHAR(80) NOT NULL,
    action VARCHAR(40) NOT NULL,
    actor_user_id INTEGER NULL,
    success INTEGER NOT NULL DEFAULT 0,
    error_code VARCHAR(80) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_provider_audit_code_time ON provider_audit_log(provider_code,created_at);

INSERT OR IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'payment_gateway_active','manual');
INSERT OR IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'sms_provider_active','smsir');
INSERT OR IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_timeout','15');
INSERT OR IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_auth','1');
INSERT OR IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_reply_to','');
INSERT OR IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_reply_name','');
