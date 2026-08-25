-- Wave W: MySQL concurrency hardening.
-- Bootstrap applies this idempotently because MySQL ALTER TABLE IF NOT EXISTS
-- support varies across supported server versions.
ALTER TABLE jobs ADD COLUMN lease_token VARCHAR(64) NULL;
CREATE INDEX idx_jobs_worker_lease ON jobs(worker_id, lease_token, status);
CREATE INDEX idx_rate_limits_last_attempt ON rate_limits(last_attempt);
