#!/bin/php
<?php
/**
 * Helper functions for calling the IP Inventory API
 */

function apiRequest($method, $path, $body = null) {
    $config = require __DIR__ . '/config.php';
    $base = rtrim($config['base_url'], '/');
    $apiPath = isset($config['api_path']) ? trim($config['api_path']) : 'ip-inventory';
    if ($apiPath === '') $apiPath = 'ip-inventory';

    // If base URL points to index.php, use query __path= (works without mod_rewrite)
    $useQueryPath = (strpos($base, 'index.php') !== false);
    $pathTrimmed = ltrim($path, '/');
    if ($useQueryPath) {
        $queryPath = $apiPath . '/' . (strpos($pathTrimmed, '?') !== false ? substr($pathTrimmed, 0, strpos($pathTrimmed, '?')) : $pathTrimmed);
        $url = $base . (strpos($base, '?') !== false ? '&' : '?') . '__path=' . rawurlencode($queryPath);
        if (strpos($pathTrimmed, '?') !== false) {
            $url .= '&' . substr($pathTrimmed, strpos($pathTrimmed, '?') + 1);
        }
    } else {
        $url = $base . '/' . $apiPath . '/' . $pathTrimmed;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT => 10,
    ));

    if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return array('error' => $error, 'http_code' => 0, 'body' => null);
    }

    $decoded = json_decode($response, true);
    $body = ($decoded !== null) ? $decoded : $response;
    return array('http_code' => $httpCode, 'body' => $body, 'raw' => $response);
}

function printResult($name, $result) {
    $ok = ($result['http_code'] >= 200 && $result['http_code'] < 300) && !isset($result['error']);
    $status = $ok ? 'OK' : 'FAIL';
    echo "[$status] $name (HTTP {$result['http_code']})\n";
    if (isset($result['error'])) {
        echo "  Error: {$result['error']}\n";
    } elseif (is_array($result['body'])) {
        echo "  " . json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "  {$result['raw']}\n";
    }
    return $ok;
}
