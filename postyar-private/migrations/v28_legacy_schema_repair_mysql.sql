-- v28: legacy MySQL schema repair for upgraded installations.
-- Idempotent: safe to run when any/all objects already exist.
CREATE TABLE IF NOT EXISTS ticket_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    icon VARCHAR(50) NOT NULL DEFAULT '🌐',
    assigned_agent_id INT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_categories_agent (assigned_agent_id),
    INDEX idx_ticket_categories_sort (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ticket_categories (slug,title,icon,sort_order) VALUES
('technical','فنی و ربات‌ها 🤖','🤖',1),
('billing','مالی و فیش واریزی 💳','💳',2),
('general','سوال عمومی 🌐','🌐',3);

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    target_section VARCHAR(100) NOT NULL DEFAULT '',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_read_created (user_id,is_read,created_at,id),
    CONSTRAINT fk_notifications_user_v28 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL UNIQUE,
    keys_p256dh VARCHAR(255) NOT NULL,
    keys_auth VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_push_subscriptions_user (user_id),
    CONSTRAINT fk_push_subscriptions_user_v28 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(50) NOT NULL UNIQUE,
    template_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    body_html TEXT NOT NULL,
    variables TEXT DEFAULT '[]',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @v28_email_subject_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='email_templates' AND COLUMN_NAME='subject');
SET @v28_email_subject_sql := IF(@v28_email_subject_exists=0,'ALTER TABLE email_templates ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT '' AFTER template_name','SELECT 1');
PREPARE v28_email_subject_stmt FROM @v28_email_subject_sql;
EXECUTE v28_email_subject_stmt;
DEALLOCATE PREPARE v28_email_subject_stmt;
