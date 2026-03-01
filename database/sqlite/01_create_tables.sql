-- IP Inventory – създаване на таблици (SQLite)
-- Изпълнява се автоматично при първо стартиране на backend при липса на таблица.

CREATE TABLE IF NOT EXISTS ip_pool (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    ip             TEXT NOT NULL UNIQUE,
    ip_type        TEXT NOT NULL CHECK (ip_type IN ('IPv4', 'IPv6')),
    status         TEXT NOT NULL DEFAULT 'free' CHECK (status IN ('free', 'reserved', 'assigned')),
    service_id     TEXT NULL,
    reserved_at    TEXT NULL,
    assigned_at    TEXT NULL,
    created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_ip_pool_service_id ON ip_pool (service_id);
CREATE INDEX IF NOT EXISTS idx_ip_pool_status_ip_type ON ip_pool (status, ip_type);
