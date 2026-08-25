-- ============================================================
-- مایگریشن API توکن‌ها برای اپلیکیشن موبایل پُست‌یار
-- جدول توکن‌های API برای احراز هویت Token-Based اندروید
-- نسخه MySQL/MariaDB — مناسب برای اجرا در phpMyAdmin
-- ============================================================

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    device_name VARCHAR(100) DEFAULT 'android',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    INDEX idx_api_tokens_hash (token_hash),
    INDEX idx_api_tokens_user (user_id),
    INDEX idx_api_tokens_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
