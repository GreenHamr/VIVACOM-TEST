#!/bin/php
<?php
/**
 * Full test of all IP Inventory API methods (in the correct order)
 * Run: php run-all.php
 * Backend must be running at http://127.0.0.1:8888 (or set IPINVENTORY_API_URL)
 */
require_once __DIR__ . '/api-helper.php';

echo "=== IP Inventory API – PHP tests ===\n\n";

$passed = 0;
$failed = 0;

// 1. POST ip-pool – add IPs
$r = apiRequest('POST', 'ip-pool', [
    'ipAddresses' => [
        ['ip' => '95.44.73.19', 'ipType' => 'IPv4'],
        ['ip' => '2a01:05a9:01a4:095c::1', 'ipType' => 'IPv6'],
        ['ip' => '95.44.73.18', 'ipType' => 'IPv4'],
    ],
]);
if (printResult('1. POST ip-pool', $r)) $passed++; else $failed++;

// 2. POST reserve-ip
$r = apiRequest('POST', 'reserve-ip', ['serviceId' => 'xxxyyy', 'ipType' => 'Both']);
if (printResult('2. POST reserve-ip', $r)) $passed++; else $failed++;

// 3. GET serviceId (before assign)
$r = apiRequest('GET', 'serviceId?serviceId=xxxyyy');
if (printResult('3. GET serviceId (before assign)', $r)) $passed++; else $failed++;

// 4. POST assign-ip-serviceId
$r = apiRequest('POST', 'assign-ip-serviceId', [
    'serviceId' => 'xxxyyy',
    'ipAddresses' => [['ip' => '95.44.73.19'], ['ip' => '2a01:05a9:01a4:095c::1']],
]);
if (printResult('4. POST assign-ip-serviceId', $r)) $passed++; else $failed++;

// 5. GET serviceId (after assign)
$r = apiRequest('GET', 'serviceId?serviceId=xxxyyy');
if (printResult('5. GET serviceId (after assign)', $r)) $passed++; else $failed++;

// 6. POST serviceId-change
$r = apiRequest('POST', 'serviceId-change', ['serviceIdOld' => 'xxxyyy', 'serviceId' => 'zzzppp']);
if (printResult('6. POST serviceId-change', $r)) $passed++; else $failed++;

// 7. GET serviceId (new serviceId)
$r = apiRequest('GET', 'serviceId?serviceId=zzzppp');
if (printResult('7. GET serviceId (zzzppp)', $r)) $passed++; else $failed++;

// 8. POST terminate-ip-serviceId
$r = apiRequest('POST', 'terminate-ip-serviceId', [
    'serviceId' => 'zzzppp',
    'ipAddresses' => [['ip' => '95.44.73.19'], ['ip' => '2a01:05a9:01a4:095c::1']],
]);
if (printResult('8. POST terminate-ip-serviceId', $r)) $passed++; else $failed++;

// 9. GET serviceId (after terminate – should be empty)
$r = apiRequest('GET', 'serviceId?serviceId=zzzppp');
if (printResult('9. GET serviceId (after terminate)', $r)) $passed++; else $failed++;

echo "\n=== Result: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
