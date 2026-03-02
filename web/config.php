<?php
/**
 * Конфигурация за web GUI на IP Inventory (PHP 5.6)
 */
$url = getenv('IPINVENTORY_API_URL');
return array(
    'base_url' => $url ? $url : 'http://127.0.0.1:8080',
);
