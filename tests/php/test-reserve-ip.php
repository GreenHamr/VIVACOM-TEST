#!/bin/php
<?php
/**
 * Тест: POST /ip-inventory/reserve-ip – резервиране на IP за serviceId
 */
require_once __DIR__ . '/api-helper.php';

$body = [
    'serviceId' => 'xxxyyy',
    'ipType' => 'Both',  // IPv4, IPv6 или Both
];

$result = apiRequest('POST', 'reserve-ip', $body);
exit(printResult('POST /ip-inventory/reserve-ip', $result) ? 0 : 1);
