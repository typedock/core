-- Form plugin-owned antispam log. Keeping this table in the plugin migration
-- makes the form package self-contained.

CREATE TABLE IF NOT EXISTS plugin_form_antispam_log (
    id VARCHAR(36) PRIMARY KEY,
    ip_address VARCHAR(64) NOT NULL,
    scope VARCHAR(64) NOT NULL DEFAULT 'default',
    created_at VARCHAR(32) NOT NULL
);
