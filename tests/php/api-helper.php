#!/bin/php
<?php
/**
 * Помощни функции за извикване на IP Inventory API
 */

function apiRequest(string $method, string $path, ?array $body = null): array {
    $config = require __DIR__ . '/config.php';
    $url = rtrim($config['base_url'], '/') . '/ip-inventory/' . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);

    if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => $error, 'http_code' => 0, 'body' => null];
    }

    $decoded = json_decode($response, true);
    return ['http_code' => $httpCode, 'body' => $decoded ?? $response, 'raw' => $response];
}

function printResult(string $name, array $result): bool {
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
