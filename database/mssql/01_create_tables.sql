-- IP Inventory – създаване на таблици (Microsoft SQL Server)
-- Изпълнение: sqlcmd или SSMS

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'ip_pool')
BEGIN
    CREATE TABLE ip_pool (
        id             INT IDENTITY(1,1) PRIMARY KEY,
        ip             VARCHAR(45) NOT NULL,
        ip_type        VARCHAR(4)  NOT NULL,
        status         VARCHAR(10) NOT NULL DEFAULT 'free',
        service_id     VARCHAR(255) NULL,
        reserved_at    DATETIME2 NULL,
        assigned_at    DATETIME2 NULL,
        created_at     DATETIME2 NOT NULL DEFAULT SYSDATETIME(),

        CONSTRAINT uq_ip_pool_ip UNIQUE (ip),
        CONSTRAINT chk_ip_pool_ip_type CHECK (ip_type IN ('IPv4', 'IPv6')),
        CONSTRAINT chk_ip_pool_status CHECK (status IN ('free', 'reserved', 'assigned'))
    );

    CREATE INDEX idx_ip_pool_service_id ON ip_pool (service_id);
    CREATE INDEX idx_ip_pool_status_ip_type ON ip_pool (status, ip_type);
END
GO
