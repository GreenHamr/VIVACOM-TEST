-- IP Inventory – създаване на таблици (PostgreSQL)
-- Изпълнение: psql -U <user> -d <database> -f 01_create_tables.sql

-- Таблица за IP pool и статус (free / reserved / assigned)
CREATE TABLE IF NOT EXISTS ip_pool (
    id             SERIAL PRIMARY KEY,
    ip             VARCHAR(45) NOT NULL,
    ip_type        VARCHAR(4)  NOT NULL CHECK (ip_type IN ('IPv4', 'IPv6')),
    status         VARCHAR(10) NOT NULL DEFAULT 'free'
        CHECK (status IN ('free', 'reserved', 'assigned')),
    service_id     VARCHAR(255) NULL,
    reserved_at    TIMESTAMP NULL,
    assigned_at    TIMESTAMP NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_ip_pool_ip UNIQUE (ip)
);

-- Индекси за търсене по service_id и по (status, ip_type)
CREATE INDEX IF NOT EXISTS idx_ip_pool_service_id ON ip_pool (service_id);
CREATE INDEX IF NOT EXISTS idx_ip_pool_status_ip_type ON ip_pool (status, ip_type);

-- Коментари (опционално)
COMMENT ON TABLE ip_pool IS 'IP inventory pool: addresses with status free/reserved/assigned';
COMMENT ON COLUMN ip_pool.reserved_at IS 'Used by auto-release job to expire unassigned reservations';
