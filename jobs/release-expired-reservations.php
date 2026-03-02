<?php
/**
 * Job: releases reserved IPs if assign-ip-serviceId is not called within a given time.
 * Run: php release-expired-reservations.php [minutes]
 * Or from cron: php /path/to/jobs/release-expired-reservations.php 30
 *
 * Config: same config.conf as backend (default: parent directory).
 * Supports db_type=sqlite and db_type=postgresql.
 * Requires: PHP 5.6+ with PDO and pdo_sqlite and/or pdo_pgsql.
 */

$minutes = 30;
if (isset($argv[1]) && is_numeric($argv[1]) && (int)$argv[1] > 0) {
    $minutes = (int)$argv[1];
}
$envMinutes = getenv('RELEASE_OLDER_THAN_MINUTES');
if ($envMinutes !== false && $envMinutes !== '' && is_numeric($envMinutes) && (int)$envMinutes > 0) {
    $minutes = (int)$envMinutes;
}

$configPath = getenv('IPINVENTORY_CONFIG');
if ($configPath === false || $configPath === '') {
    $configPath = dirname(__DIR__) . '/config.conf';
}
if (!is_readable($configPath)) {
    fwrite(STDERR, "Error: Config not found or not readable: " . $configPath . "\n");
    exit(1);
}

$config = parseConfig($configPath);
$dbType = isset($config['db_type']) ? strtolower(trim($config['db_type'])) : 'sqlite';
if ($dbType === '') {
    $dbType = 'sqlite';
}

$drivers = extension_loaded('pdo') ? PDO::getAvailableDrivers() : array();
if ($dbType === 'sqlite') {
    if (!in_array('sqlite', $drivers)) {
        fwrite(STDERR, "Error: PDO SQLite driver is not loaded. Install or enable it, e.g.:\n");
        fwrite(STDERR, "  Debian/Ubuntu: sudo apt install php-sqlite3\n");
        fwrite(STDERR, "  Or in php.ini enable: extension=pdo_sqlite\n");
        exit(1);
    }
} elseif ($dbType === 'postgresql') {
    if (!in_array('pgsql', $drivers)) {
        fwrite(STDERR, "Error: PDO PostgreSQL driver is not loaded. Install or enable it, e.g.:\n");
        fwrite(STDERR, "  Debian/Ubuntu: sudo apt install php-pgsql\n");
        fwrite(STDERR, "  Or in php.ini enable: extension=pdo_pgsql\n");
        exit(1);
    }
}

try {
    if ($dbType === 'sqlite') {
        $released = releaseExpiredSqlite($config, $minutes);
    } elseif ($dbType === 'postgresql') {
        $released = releaseExpiredPostgresql($config, $minutes);
    } else {
        fwrite(STDERR, "Error: Unsupported db_type for this job: " . $dbType . " (only sqlite, postgresql)\n");
        exit(1);
    }
    echo "Released " . $released . " expired reservation(s).\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

function parseConfig($path) {
    $out = array();
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $out;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($key !== '') $out[$key] = $val;
    }
    return $out;
}

function releaseExpiredSqlite($config, $minutes) {
    $path = isset($config['db_path']) ? $config['db_path'] : 'ip_inventory.db';
    if (dirname($path) === '.' || dirname($path) === '') {
        $path = dirname(__DIR__) . '/' . $path;
    }
    if (!is_readable($path)) {
        throw new Exception("SQLite DB not found: " . $path);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $sql = "UPDATE ip_pool SET status = 'free', service_id = NULL, reserved_at = NULL WHERE status = 'reserved' AND reserved_at < datetime('now', '-" . (int)$minutes . " minutes')";
    $n = $pdo->exec($sql);
    return $n !== false ? $n : 0;
}

function releaseExpiredPostgresql($config, $minutes) {
    $host = isset($config['db_host']) ? $config['db_host'] : 'localhost';
    $port = isset($config['db_port']) ? $config['db_port'] : '5432';
    $dbname = isset($config['db_name']) ? $config['db_name'] : 'postgres';
    $user = isset($config['db_user']) ? $config['db_user'] : '';
    $password = isset($config['db_password']) ? $config['db_password'] : '';
    $dsn = "pgsql:host=" . $host . ";port=" . $port . ";dbname=" . $dbname;
    $pdo = new PDO($dsn, $user, $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $sql = "UPDATE ip_pool SET status = 'free', service_id = NULL, reserved_at = NULL WHERE status = 'reserved' AND reserved_at < NOW() - INTERVAL '" . (int)$minutes . " minutes'";
    $n = $pdo->exec($sql);
    return $n !== false ? $n : 0;
}
