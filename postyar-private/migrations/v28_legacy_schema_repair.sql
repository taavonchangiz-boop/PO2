-- v28: legacy SQLite schema repair for upgraded installations.
CREATE TABLE IF NOT EXISTS ticket_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    icon VARCHAR(50) NOT NULL DEFAULT '🌐',
    assigned_agent_id INTEGER NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO ticket_categories (slug,title,icon,sort_order) VALUES
('technical','فنی و ربات‌ها 🤖','🤖',1),
('billing','مالی و فیش واریزی 💳','💳',2),
('general','سوال عمومی 🌐','🌐',3);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    target_section VARCHAR(100) NOT NULL DEFAULT '',
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read_created ON notifications(user_id,is_read,created_at,id);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    endpoint VARCHAR(500) NOT NULL UNIQUE,
    keys_p256dh VARCHAR(255) NOT NULL,
    keys_auth VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key VARCHAR(50) NOT NULL UNIQUE,
    template_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    body_html TEXT NOT NULL,
    variables TEXT DEFAULT '[]',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
