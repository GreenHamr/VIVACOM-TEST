<?php
/**
 * Configuration for IP Inventory PHP API (PostgreSQL only).
 * All values from config.conf in project root only (no environment variables).
 */
$configPath = dirname(dirname(__DIR__)) . '/config.conf';
$cfg = array('db_host' => 'localhost', 'db_port' => '5432', 'db_name' => 'postgres', 'db_user' => '', 'db_password' => '', 'api_path' => 'ip-inventory');
if (is_readable($configPath)) {
    $lines = @file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if ($key === 'db_host') $cfg['db_host'] = $val;
            elseif ($key === 'db_port') $cfg['db_port'] = $val;
            elseif ($key === 'db_name') $cfg['db_name'] = $val;
            elseif ($key === 'db_user') $cfg['db_user'] = $val;
            elseif ($key === 'db_password') $cfg['db_password'] = $val;
            elseif ($key === 'api_path' && $val !== '') $cfg['api_path'] = trim($val);
        }
    }
}
if ($cfg['api_path'] === '') $cfg['api_path'] = 'ip-inventory';
return $cfg;
