CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(80) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL,
    worker_id VARCHAR(150) NULL,
    lease_until DATETIME NULL,
    result_json LONGTEXT NULL,
    last_error TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs_claim(status, available_at, id),
    INDEX idx_jobs_lease(status, lease_until, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
