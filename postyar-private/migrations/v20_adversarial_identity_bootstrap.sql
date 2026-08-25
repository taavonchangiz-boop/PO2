-- Wave O: adversarial anti-abuse + first-admin bootstrap integrity.
-- The runtime migration in Bootstrap.php creates and initializes system_bootstrap.
-- This SQL is intentionally idempotent and contains no destructive data merge.

-- SQLite reference schema:
CREATE TABLE IF NOT EXISTS system_bootstrap (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    initialized_at DATETIME NULL
);
INSERT OR IGNORE INTO system_bootstrap (id, initialized_at)
SELECT 1, MIN(created_at) FROM users WHERE role = 'superadmin';
INSERT OR IGNORE INTO system_bootstrap (id, initialized_at)
SELECT 1, MIN(created_at) FROM users;

CREATE INDEX IF NOT EXISTS idx_system_bootstrap_initialized ON system_bootstrap(initialized_at);
