#!/bin/php
<?php
/**
 * Тест: POST /ip-inventory/serviceId-change – прехвърляне от стар към нов serviceId
 */
require_once __DIR__ . '/api-helper.php';

$body = [
    'serviceIdOld' => 'xxxyyy',
    'serviceId' => 'zzzppp',
];

$result = apiRequest('POST', 'serviceId-change', $body);
exit(printResult('POST /ip-inventory/serviceId-change', $result) ? 0 : 1);
