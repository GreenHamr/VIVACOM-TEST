#!/bin/php
<?php
/**
 * Пълен тест на всички IP Inventory API методи (в правилния ред)
 * Изпълнение: php run-all.php
 * Backend трябва да работи на http://127.0.0.1:8080 (или задай IPINVENTORY_API_URL)
 */
require_once __DIR__ . '/api-helper.php';

echo "=== IP Inventory API – PHP тестове ===\n\n";

$passed = 0;
$failed = 0;

// 1. POST ip-pool – добавяне на IP
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

// 3. GET serviceId (преди assign)
$r = apiRequest('GET', 'serviceId?serviceId=xxxyyy');
if (printResult('3. GET serviceId (преди assign)', $r)) $passed++; else $failed++;

// 4. POST assign-ip-serviceId
$r = apiRequest('POST', 'assign-ip-serviceId', [
    'serviceId' => 'xxxyyy',
    'ipAddresses' => [['ip' => '95.44.73.19'], ['ip' => '2a01:05a9:01a4:095c::1']],
]);
if (printResult('4. POST assign-ip-serviceId', $r)) $passed++; else $failed++;

// 5. GET serviceId (след assign)
$r = apiRequest('GET', 'serviceId?serviceId=xxxyyy');
if (printResult('5. GET serviceId (след assign)', $r)) $passed++; else $failed++;

// 6. POST serviceId-change
$r = apiRequest('POST', 'serviceId-change', ['serviceIdOld' => 'xxxyyy', 'serviceId' => 'zzzppp']);
if (printResult('6. POST serviceId-change', $r)) $passed++; else $failed++;

// 7. GET serviceId (нов serviceId)
$r = apiRequest('GET', 'serviceId?serviceId=zzzppp');
if (printResult('7. GET serviceId (zzzppp)', $r)) $passed++; else $failed++;

// 8. POST terminate-ip-serviceId
$r = apiRequest('POST', 'terminate-ip-serviceId', [
    'serviceId' => 'zzzppp',
    'ipAddresses' => [['ip' => '95.44.73.19'], ['ip' => '2a01:05a9:01a4:095c::1']],
]);
if (printResult('8. POST terminate-ip-serviceId', $r)) $passed++; else $failed++;

// 9. GET serviceId (след terminate – трябва да е празен)
$r = apiRequest('GET', 'serviceId?serviceId=zzzppp');
if (printResult('9. GET serviceId (след terminate)', $r)) $passed++; else $failed++;

echo "\n=== Резултат: $passed успешни, $failed неуспешни ===\n";
exit($failed > 0 ? 1 : 0);
