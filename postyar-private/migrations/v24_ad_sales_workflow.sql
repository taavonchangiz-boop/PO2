-- Wave R.2 / Ad Sales Workflow (SQLite)
CREATE TABLE IF NOT EXISTS ad_placements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(80) NOT NULL UNIQUE,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    unit_price_per_day DECIMAL(12,2) NOT NULL DEFAULT 0,
    max_concurrent INTEGER NOT NULL DEFAULT 10,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CHECK (unit_price_per_day >= 0),
    CHECK (max_concurrent > 0)
);

CREATE TABLE IF NOT EXISTS ad_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'submitted',
    payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
    requested_starts_at DATETIME NOT NULL,
    requested_ends_at DATETIME NOT NULL,
    quoted_amount DECIMAL(12,2) NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'IRR',
    admin_notes TEXT NULL,
    user_notes TEXT NULL,
    reviewed_by INTEGER NULL,
    reviewed_at DATETIME NULL,
    paid_at DATETIME NULL,
    payment_method VARCHAR(50) NULL,
    payment_reference VARCHAR(120) NULL,
    receipt_photo TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CHECK (requested_ends_at > requested_starts_at),
    CHECK (quoted_amount IS NULL OR quoted_amount >= 0)
);

CREATE TABLE IF NOT EXISTS ad_order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    placement_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    unit_price_per_day DECIMAL(12,2) NOT NULL,
    days INTEGER NOT NULL,
    line_amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES ad_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (placement_id) REFERENCES ad_placements(id) ON DELETE RESTRICT,
    CHECK (quantity > 0), CHECK (days > 0), CHECK (unit_price_per_day >= 0), CHECK (line_amount >= 0)
);

CREATE TABLE IF NOT EXISTS ad_campaign_placements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    placement_id INTEGER NOT NULL,
    placement_code VARCHAR(80) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (placement_id) REFERENCES ad_placements(id) ON DELETE RESTRICT,
    UNIQUE(campaign_id, placement_id)
);

CREATE INDEX IF NOT EXISTS idx_ad_campaign_placements_code_campaign ON ad_campaign_placements(placement_code, campaign_id);

CREATE TABLE IF NOT EXISTS ad_creatives (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    title VARCHAR(180) NOT NULL,
    body_text TEXT NULL,
    image_url TEXT NULL,
    destination_url TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
    CHECK (image_url IS NOT NULL OR body_text IS NOT NULL)
);

ALTER TABLE ad_campaigns ADD COLUMN order_id INTEGER NULL;
ALTER TABLE ad_campaigns ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'paid';
ALTER TABLE ad_campaigns ADD COLUMN placement_code VARCHAR(80) NULL;
ALTER TABLE ad_campaigns ADD COLUMN activation_at DATETIME NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_ad_orders_owner_payment_reference ON ad_orders(owner_user_id,payment_reference) WHERE payment_reference IS NOT NULL AND payment_reference <> '';
CREATE INDEX IF NOT EXISTS idx_ad_orders_owner_status ON ad_orders(owner_user_id, status, id);
CREATE INDEX IF NOT EXISTS idx_ad_orders_payment ON ad_orders(payment_status, status, id);
CREATE INDEX IF NOT EXISTS idx_ad_order_items_order ON ad_order_items(order_id, id);
CREATE INDEX IF NOT EXISTS idx_ad_creatives_campaign_active ON ad_creatives(campaign_id, is_active, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_ad_campaigns_order ON ad_campaigns(order_id);

INSERT OR IGNORE INTO ad_placements(code,title,description,unit_price_per_day,max_concurrent,is_active)
VALUES
('global_top','جایگاه اصلی سراسری','اسلایدر اصلی داشبورد وب و اپلیکیشن',0,10,1),
('dashboard_banner','بنر داشبورد','جایگاه بنری داخل داشبورد',0,10,1),
('mobile_banner','بنر موبایل','جایگاه اختصاصی موبایل/تبلت',0,10,1);
