#!/bin/php
<?php
/**
 * Test: POST /ip-inventory/reserve-ip – reserve IP for serviceId
 */
require_once __DIR__ . '/api-helper.php';

$body = [
    'serviceId' => 'xxxyyy',
    'ipType' => 'Both',  // IPv4, IPv6 or Both
];

$result = apiRequest('POST', 'reserve-ip', $body);
exit(printResult('POST /ip-inventory/reserve-ip', $result) ? 0 : 1);
