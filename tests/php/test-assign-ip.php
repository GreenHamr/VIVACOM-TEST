#!/bin/php
<?php
/**
 * Test: POST /ip-inventory/assign-ip-serviceId – assign IP to serviceId
 */
require_once __DIR__ . '/api-helper.php';

$body = [
    'serviceId' => 'xxxyyy',
    'ipAddresses' => [
        ['ip' => '95.44.73.19'],
        ['ip' => '2a01:05a9:01a4:095c::1'],
    ],
];

$result = apiRequest('POST', 'assign-ip-serviceId', $body);
exit(printResult('POST /ip-inventory/assign-ip-serviceId', $result) ? 0 : 1);
