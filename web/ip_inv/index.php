<?php
/**
 * IP Inventory REST API – PHP implementation (PostgreSQL only).
 * Same interface as the C++ backend: ip-pool, reserve-ip, assign-ip-serviceId, terminate-ip-serviceId, serviceId-change, GET serviceId.
 */

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
if (!extension_loaded('pdo') || !in_array('pgsql', PDO::getAvailableDrivers())) {
    json_response(500, '500', 'PDO PostgreSQL driver not available. Install php-pgsql.');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ip_validation.php';

try {
    $pdo = ip_inv_get_pdo($config);
    ip_inv_init_schema($pdo);
} catch (PDOException $e) {
    json_response(500, '500', 'Database connection failed: ' . $e->getMessage());
    exit;
}

// Path from rewrite __path param, or from REQUEST_URI relative to this script's directory
if (isset($_GET['__path']) && $_GET['__path'] !== '') {
    $path = trim($_GET['__path'], '/');
} else {
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    if ($path === false) $path = '';
    $path = rawurldecode($path);
    if ($basePath !== '' && strpos($path, $basePath) === 0) {
        $path = substr($path, strlen($basePath));
    }
    $path = trim($path, '/');
}
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($path === 'ip-inventory/ip-pool' && $method === 'POST') {
    handle_post_ip_pool($pdo);
} elseif ($path === 'ip-inventory/reserve-ip' && $method === 'POST') {
    handle_post_reserve_ip($pdo);
} elseif ($path === 'ip-inventory/assign-ip-serviceId' && $method === 'POST') {
    handle_post_assign_ip($pdo);
} elseif ($path === 'ip-inventory/terminate-ip-serviceId' && $method === 'POST') {
    handle_post_terminate_ip($pdo);
} elseif ($path === 'ip-inventory/serviceId-change' && $method === 'POST') {
    handle_post_service_id_change($pdo);
} elseif ($path === 'ip-inventory/serviceId' && $method === 'GET') {
    handle_get_service_id($pdo);
} else {
    json_response(404, '404', 'Not Found');
}

function json_response($statusCode, $code, $message, $extra = array()) {
    http_response_code($statusCode);
    $out = array('statusCode' => (string)$code, 'statusMessage' => $message);
    echo json_encode(array_merge($out, $extra));
}

function get_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function handle_post_ip_pool($pdo) {
    $body = get_json_body();
    if (!isset($body['ipAddresses']) || !is_array($body['ipAddresses'])) {
        json_response(400, '400', 'Missing or invalid ipAddresses array');
        return;
    }
    $entries = array();
    foreach ($body['ipAddresses'] as $item) {
        if (!isset($item['ip']) || !isset($item['ipType'])) {
            json_response(400, '400', 'Each item must have ip and ipType');
            return;
        }
        $ip = trim($item['ip']);
        $ipType = trim($item['ipType']);
        if ($ipType !== 'IPv4' && $ipType !== 'IPv6') {
            json_response(400, '400', 'ipType must be IPv4 or IPv6');
            return;
        }
        if (!ip_inv_is_valid_ip_with_type($ip, $ipType)) {
            json_response(400, '400', 'Invalid IP address for type ' . $ipType);
            return;
        }
        $entries[] = array('ip' => $ip, 'ipType' => $ipType);
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO ip_pool (ip, ip_type, status) VALUES (:ip, :ip_type, 'free') ON CONFLICT (ip) DO NOTHING");
        foreach ($entries as $e) {
            $stmt->execute(array(':ip' => $e['ip'], ':ip_type' => $e['ipType']));
        }
        json_response(200, '0', 'Successful operation. OK');
    } catch (PDOException $ex) {
        json_response(500, '500', $ex->getMessage());
    }
}

function handle_post_reserve_ip($pdo) {
    $body = get_json_body();
    if (!isset($body['serviceId']) || !isset($body['ipType'])) {
        json_response(400, '400', 'Missing serviceId or ipType');
        return;
    }
    $serviceId = $body['serviceId'];
    $ipType = trim($body['ipType']);
    if ($ipType !== 'IPv4' && $ipType !== 'IPv6' && $ipType !== 'Both') {
        json_response(400, '400', 'ipType must be IPv4, IPv6 or Both');
        return;
    }
    $types = $ipType === 'Both' ? array('IPv4', 'IPv6') : array($ipType);
    $reserved = array();
    $now = date('Y-m-d H:i:s');
    try {
        $pdo->beginTransaction();
        foreach ($types as $t) {
            $sel = $pdo->prepare("SELECT id, ip, ip_type FROM ip_pool WHERE status = 'free' AND ip_type = :t ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $sel->execute(array(':t' => $t));
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $reserved[] = array('ip' => $row['ip'], 'ipType' => $row['ip_type']);
                $upd = $pdo->prepare("UPDATE ip_pool SET status = 'reserved', service_id = :sid, reserved_at = :ts WHERE id = :id");
                $upd->execute(array(':sid' => $serviceId, ':ts' => $now, ':id' => $row['id']));
            }
        }
        $pdo->commit();
        http_response_code(200);
        echo json_encode(array('ipAddresses' => $reserved));
    } catch (PDOException $ex) {
        $pdo->rollBack();
        json_response(500, '500', $ex->getMessage());
    }
}

function handle_post_assign_ip($pdo) {
    $body = get_json_body();
    if (!isset($body['serviceId']) || !isset($body['ipAddresses'])) {
        json_response(400, '400', 'Missing serviceId or ipAddresses');
        return;
    }
    $serviceId = $body['serviceId'];
    $ips = array();
    foreach ($body['ipAddresses'] as $item) {
        if (isset($item['ip'])) $ips[] = trim($item['ip']);
    }
    $now = date('Y-m-d H:i:s');
    try {
        $upd = $pdo->prepare("UPDATE ip_pool SET status = 'assigned', assigned_at = :ts WHERE ip = :ip AND service_id = :sid AND status = 'reserved'");
        foreach ($ips as $ip) {
            $upd->execute(array(':ts' => $now, ':ip' => $ip, ':sid' => $serviceId));
            if ($upd->rowCount() === 0) {
                json_response(400, '400', 'Assign failed: IP not reserved for this serviceId or invalid');
                return;
            }
        }
        json_response(200, '0', 'Successful operation. OK');
    } catch (PDOException $ex) {
        json_response(500, '500', $ex->getMessage());
    }
}

function handle_post_terminate_ip($pdo) {
    $body = get_json_body();
    if (!isset($body['serviceId']) || !isset($body['ipAddresses'])) {
        json_response(400, '400', 'Missing serviceId or ipAddresses');
        return;
    }
    $serviceId = $body['serviceId'];
    $ips = array();
    foreach ($body['ipAddresses'] as $item) {
        if (isset($item['ip'])) $ips[] = trim($item['ip']);
    }
    try {
        $upd = $pdo->prepare("UPDATE ip_pool SET status = 'free', service_id = NULL, reserved_at = NULL, assigned_at = NULL WHERE ip = :ip AND service_id = :sid AND status = 'assigned'");
        foreach ($ips as $ip) {
            $upd->execute(array(':ip' => $ip, ':sid' => $serviceId));
            if ($upd->rowCount() === 0) {
                json_response(400, '400', 'Terminate failed: IP not assigned to this serviceId');
                return;
            }
        }
        json_response(200, '0', 'Successful operation. OK');
    } catch (PDOException $ex) {
        json_response(500, '500', $ex->getMessage());
    }
}

function handle_post_service_id_change($pdo) {
    $body = get_json_body();
    if (!isset($body['serviceIdOld']) || !isset($body['serviceId'])) {
        json_response(400, '400', 'Missing serviceIdOld or serviceId');
        return;
    }
    $oldId = $body['serviceIdOld'];
    $newId = $body['serviceId'];
    try {
        $upd = $pdo->prepare("UPDATE ip_pool SET service_id = :newid WHERE service_id = :oldid");
        $upd->execute(array(':newid' => $newId, ':oldid' => $oldId));
        json_response(200, '0', 'Successful operation. OK');
    } catch (PDOException $ex) {
        json_response(500, '500', $ex->getMessage());
    }
}

function handle_get_service_id($pdo) {
    $serviceId = isset($_GET['serviceId']) ? trim($_GET['serviceId']) : '';
    if ($serviceId === '') {
        json_response(400, '400', 'Missing serviceId query parameter');
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT ip, ip_type FROM ip_pool WHERE service_id = :sid AND status IN ('reserved', 'assigned')");
        $stmt->execute(array(':sid' => $serviceId));
        $list = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $list[] = array('ip' => $row['ip'], 'ipType' => $row['ip_type']);
        }
        http_response_code(200);
        echo json_encode(array('ipAddresses' => $list));
    } catch (PDOException $ex) {
        json_response(500, '500', $ex->getMessage());
    }
}
