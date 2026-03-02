#!/bin/php
<?php
/**
 * Конфигурация за PHP тестовете на IP Inventory API
 */
return [
    'base_url' => getenv('IPINVENTORY_API_URL') ?: 'http://viva.greenhamr.org',
];
