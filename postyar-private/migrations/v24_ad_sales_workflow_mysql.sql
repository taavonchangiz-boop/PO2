-- Wave R.2 / Ad Sales Workflow (MySQL)
CREATE TABLE IF NOT EXISTS ad_placements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    unit_price_per_day DECIMAL(12,2) NOT NULL DEFAULT 0,
    max_concurrent INT NOT NULL DEFAULT 10,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_placements_active(is_active, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'submitted',
    payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
    requested_starts_at DATETIME NOT NULL,
    requested_ends_at DATETIME NOT NULL,
    quoted_amount DECIMAL(12,2) NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'IRR',
    admin_notes TEXT NULL,
    user_notes TEXT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    paid_at DATETIME NULL,
    payment_method VARCHAR(50) NULL,
    payment_reference VARCHAR(120) NULL,
    receipt_photo TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_orders_owner_status(owner_user_id,status,id),
    INDEX idx_ad_orders_payment(payment_status,status,id),
    CONSTRAINT fk_ad_orders_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_orders_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE UNIQUE INDEX uq_ad_orders_owner_payment_reference ON ad_orders(owner_user_id,payment_reference);

CREATE TABLE IF NOT EXISTS ad_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price_per_day DECIMAL(12,2) NOT NULL,
    days INT NOT NULL,
    line_amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_order_items_order(order_id,id),
    CONSTRAINT fk_ad_order_items_order FOREIGN KEY(order_id) REFERENCES ad_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_order_items_placement FOREIGN KEY(placement_id) REFERENCES ad_placements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_campaign_placements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    placement_code VARCHAR(80) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ad_campaign_placement(campaign_id,placement_id),
    INDEX idx_ad_campaign_placements_code_campaign(placement_code,campaign_id),
    CONSTRAINT fk_ad_campaign_placements_campaign FOREIGN KEY(campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_campaign_placements_placement FOREIGN KEY(placement_id) REFERENCES ad_placements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_creatives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    body_text TEXT NULL,
    image_url TEXT NULL,
    destination_url TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_creatives_campaign_active(campaign_id,is_active,sort_order,id),
    CONSTRAINT fk_ad_creatives_campaign FOREIGN KEY(campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ad_campaigns ADD COLUMN order_id BIGINT UNSIGNED NULL;
ALTER TABLE ad_campaigns ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'paid';
ALTER TABLE ad_campaigns ADD COLUMN placement_code VARCHAR(80) NULL;
ALTER TABLE ad_campaigns ADD COLUMN activation_at DATETIME NULL;
CREATE INDEX idx_ad_campaigns_order ON ad_campaigns(order_id);

INSERT IGNORE INTO ad_placements(code,title,description,unit_price_per_day,max_concurrent,is_active) VALUES
('global_top','جایگاه اصلی سراسری','اسلایدر اصلی داشبورد وب و اپلیکیشن',0,10,1),
('dashboard_banner','بنر داشبورد','جایگاه بنری داخل داشبورد',0,10,1),
('mobile_banner','بنر موبایل','جایگاه اختصاصی موبایل/تبلت',0,10,1);
