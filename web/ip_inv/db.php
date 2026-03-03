<?php
/**
 * PostgreSQL connection and schema init for IP Inventory (ip_inv under document root).
 */

function ip_inv_get_pdo($config) {
    $dsn = 'pgsql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';dbname=' . $config['db_name'];
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    return $pdo;
}

function ip_inv_init_schema($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS ip_pool (
        id SERIAL PRIMARY KEY,
        ip VARCHAR(45) NOT NULL UNIQUE,
        ip_type VARCHAR(4) NOT NULL CHECK (ip_type IN ('IPv4', 'IPv6')),
        status VARCHAR(10) NOT NULL DEFAULT 'free' CHECK (status IN ('free', 'reserved', 'assigned')),
        service_id VARCHAR(255) NULL,
        reserved_at TIMESTAMP NULL,
        assigned_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ip_pool_service_id ON ip_pool (service_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ip_pool_status_ip_type ON ip_pool (status, ip_type)");
}
