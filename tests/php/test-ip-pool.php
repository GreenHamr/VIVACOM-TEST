#!/bin/php
<?php
/**
 * Test: POST /ip-inventory/ip-pool – add IP addresses to pool
 */
require_once __DIR__ . '/api-helper.php';

$body = [
    'ipAddresses' => [
        ['ip' => '95.44.73.19', 'ipType' => 'IPv4'],
        ['ip' => '2a01:05a9:01a4:095c::1', 'ipType' => 'IPv6'],
        ['ip' => '95.44.73.18', 'ipType' => 'IPv4'],
    ],
];

$result = apiRequest('POST', 'ip-pool', $body);
exit(printResult('POST /ip-inventory/ip-pool', $result) ? 0 : 1);
