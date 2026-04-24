CREATE TABLE IF NOT EXISTS redirects (
    id VARCHAR(36) PRIMARY KEY,
    source_path VARCHAR(2000) NOT NULL,
    target_url VARCHAR(2000) NOT NULL,
    status_code INTEGER NOT NULL DEFAULT 301,
    created_at DATETIME NOT NULL
);
