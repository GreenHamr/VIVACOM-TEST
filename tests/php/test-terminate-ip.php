#!/bin/php
<?php
/**
 * Test: POST /ip-inventory/terminate-ip-serviceId – terminate assignment
 */
require_once __DIR__ . '/api-helper.php';

$body = [
    'serviceId' => 'xxxyyy',
    'ipAddresses' => [
        ['ip' => '95.44.73.19'],
        ['ip' => '2a01:05a9:01a4:095c::1'],
    ],
];

$result = apiRequest('POST', 'terminate-ip-serviceId', $body);
exit(printResult('POST /ip-inventory/terminate-ip-serviceId', $result) ? 0 : 1);
