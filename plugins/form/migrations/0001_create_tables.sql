-- Form plugin schema.
-- The `IF NOT EXISTS` guards let this migration re-run safely on databases
-- that were manually partially migrated from the old Form plugin bootstrap
-- (which created these same tables via self-migration prior to doc28).

CREATE TABLE IF NOT EXISTS plugin_form_forms (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    fields TEXT,
    notify_email VARCHAR(255),
    success_message TEXT,
    created_at VARCHAR(32),
    updated_at VARCHAR(32)
);

CREATE TABLE IF NOT EXISTS plugin_form_submissions (
    id VARCHAR(36) PRIMARY KEY,
    form_id VARCHAR(36) NOT NULL,
    data TEXT,
    ip_address VARCHAR(64),
    user_agent VARCHAR(500),
    created_at VARCHAR(32)
);
