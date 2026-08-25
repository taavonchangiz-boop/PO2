-- Wave W: multi-user concurrency hardening.
-- Runtime Bootstrap applies the same changes idempotently for existing installs.
ALTER TABLE jobs ADD COLUMN lease_token VARCHAR(64) NULL;
CREATE INDEX idx_jobs_worker_lease ON jobs(worker_id, lease_token, status);
CREATE INDEX idx_rate_limits_last_attempt ON rate_limits(last_attempt);
