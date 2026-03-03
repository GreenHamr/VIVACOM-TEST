<?php
/**
 * Configuration for IP Inventory web GUI (PHP 5.6).
 * All values from config.conf in project root only (no environment variables).
 */
$configPath = dirname(__DIR__) . '/config.conf';
$baseUrl = 'http://127.0.0.1:8888';
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
            if ($key === 'api_url' && $val !== '') $baseUrl = $val;
        }
    }
}
return array(
    'base_url' => $baseUrl,
);
