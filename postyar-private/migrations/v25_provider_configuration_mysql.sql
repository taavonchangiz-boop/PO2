-- Wave R.3 / provider configuration boundary (MySQL)
CREATE TABLE IF NOT EXISTS provider_audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider_type VARCHAR(30) NOT NULL,
 provider_code VARCHAR(80) NOT NULL,
 action VARCHAR(40) NOT NULL,
 actor_user_id BIGINT NULL,
 success TINYINT NOT NULL DEFAULT 0,
 error_code VARCHAR(80) NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_provider_audit_code_time(provider_code,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'payment_gateway_active','manual');
INSERT IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'sms_provider_active','smsir');
INSERT IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_timeout','15');
INSERT IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_auth','1');
INSERT IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_reply_to','');
INSERT IGNORE INTO settings(tenant_id,key_name,key_value) VALUES(0,'smtp_reply_name','');
