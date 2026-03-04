<?php
/**
 * Web tests for all IP Inventory API methods (PHP 5.6).
 * Runs the same scenario as tests/php/run-all.php and shows results in the browser.
 */
require_once dirname(__DIR__) . '/tests/php/api-helper.php';

function testResultHtml($name, $result) {
    $ok = ($result['http_code'] >= 200 && $result['http_code'] < 300) && !isset($result['error']);
    $statusClass = $ok ? 'pass' : 'fail';
    $statusText = $ok ? 'OK' : 'FAIL';
    $detail = '';
    if (isset($result['error'])) {
        $detail = htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8');
    } elseif (is_array($result['body'])) {
        $detail = htmlspecialchars(json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
    } else {
        $detail = htmlspecialchars($result['raw'], ENT_QUOTES, 'UTF-8');
    }
    $detail = nl2br($detail);
    return array('ok' => $ok, 'html' => '<div class="result ' . $statusClass . '"><strong>' . $statusText . '</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' (HTTP ' . (int)$result['http_code'] . ')<pre>' . $detail . '</pre></div>');
}

$results = array();
$passed = 0;
$failed = 0;

// 1. POST ip-pool
$r = apiRequest('POST', 'ip-pool', array(
    'ipAddresses' => array(
        array('ip' => '95.44.73.19', 'ipType' => 'IPv4'),
        array('ip' => '2a01:05a9:01a4:095c::1', 'ipType' => 'IPv6'),
        array('ip' => '95.44.73.18', 'ipType' => 'IPv4'),
    ),
));
$res = testResultHtml('1. POST ip-pool', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 2. POST reserve-ip
$r = apiRequest('POST', 'reserve-ip', array('serviceId' => 'xxxyyy', 'ipType' => 'Both'));
$res = testResultHtml('2. POST reserve-ip', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 3. GET serviceId (before assign)
$r = apiRequest('GET', 'serviceId?serviceId=xxxyyy');
$res = testResultHtml('3. GET serviceId (before assign)', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 4. POST assign-ip-serviceId
$r = apiRequest('POST', 'assign-ip-serviceId', array(
    'serviceId' => 'xxxyyy',
    'ipAddresses' => array(array('ip' => '95.44.73.19'), array('ip' => '2a01:05a9:01a4:095c::1')),
));
$res = testResultHtml('4. POST assign-ip-serviceId', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 5. GET serviceId (after assign)
$r = apiRequest('GET', 'serviceId?serviceId=xxxyyy');
$res = testResultHtml('5. GET serviceId (after assign)', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 6. POST serviceId-change
$r = apiRequest('POST', 'serviceId-change', array('serviceIdOld' => 'xxxyyy', 'serviceId' => 'zzzppp'));
$res = testResultHtml('6. POST serviceId-change', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 7. GET serviceId (new serviceId)
$r = apiRequest('GET', 'serviceId?serviceId=zzzppp');
$res = testResultHtml('7. GET serviceId (zzzppp)', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 8. POST terminate-ip-serviceId
$r = apiRequest('POST', 'terminate-ip-serviceId', array(
    'serviceId' => 'zzzppp',
    'ipAddresses' => array(array('ip' => '95.44.73.19'), array('ip' => '2a01:05a9:01a4:095c::1')),
));
$res = testResultHtml('8. POST terminate-ip-serviceId', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

// 9. GET serviceId (after terminate)
$r = apiRequest('GET', 'serviceId?serviceId=zzzppp');
$res = testResultHtml('9. GET serviceId (after terminate)', $r);
$results[] = $res; if ($res['ok']) $passed++; else $failed++;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Inventory API – Web tests</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 1.5rem auto; padding: 0 1rem; }
        h1 { font-size: 1.25rem; margin-bottom: 0.5rem; }
        .summary { margin-bottom: 1rem; padding: 0.5rem; border-radius: 6px; font-weight: 600; }
        .summary.all-pass { background: #d4edda; color: #155724; }
        .summary.has-fail { background: #f8d7da; color: #721c24; }
        .result { margin-bottom: 0.75rem; padding: 0.6rem; border-radius: 6px; border-left: 4px solid #ccc; }
        .result.pass { background: #e8f5e9; border-left-color: #2e7d32; }
        .result.fail { background: #ffebee; border-left-color: #c62828; }
        .result pre { margin: 0.25rem 0 0; font-size: 0.8rem; white-space: pre-wrap; word-break: break-all; }
        a { color: #1565c0; }
    </style>
</head>
<body>
    <h1>IP Inventory API – Web tests</h1>
    <p>Same scenario as <code>tests/php/run-all.php</code>. API URL from <code>config.conf</code> (api_url, api_path).</p>
    <div class="summary <?php echo $failed > 0 ? 'has-fail' : 'all-pass'; ?>">
        Result: <?php echo $passed; ?> passed, <?php echo $failed; ?> failed
    </div>
    <?php foreach ($results as $res) { echo $res['html']; } ?>
    <p><a href="index.php">Add IPs to pool</a></p>
</body>
</html>
