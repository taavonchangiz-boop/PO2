-- Wave Q: durable job queue foundation + operational observability.
-- No job type is executable until an explicit allow-listed worker handler exists.
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type VARCHAR(80) NOT NULL,
    payload_json TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL,
    worker_id VARCHAR(150) NULL,
    lease_until DATETIME NULL,
    result_json TEXT NULL,
    last_error TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_jobs_claim ON jobs(status, available_at, id);
CREATE INDEX IF NOT EXISTS idx_jobs_lease ON jobs(status, lease_until, id);
