#!/bin/php
<?php
/**
 * Test: GET /ip-inventory/serviceId?serviceId=xxx – check by serviceId
 */
require_once __DIR__ . '/api-helper.php';

$serviceId = $argv[1] ?? 'xxxyyy';
$result = apiRequest('GET', 'serviceId?serviceId=' . urlencode($serviceId));

exit(printResult("GET /ip-inventory/serviceId?serviceId=$serviceId", $result) ? 0 : 1);
