<?php
/**
 * GUI for adding IP addresses to pool – uses POST /ip-inventory/ip-pool (PHP 5.6)
 */
$config = require __DIR__ . '/config.php';
$message = '';
$messageType = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $entries = array();
    $ips = isset($_POST['ip']) ? $_POST['ip'] : array();
    $types = isset($_POST['ipType']) ? $_POST['ipType'] : array();
    foreach ($ips as $i => $ip) {
        $ip = trim($ip);
        if ($ip === '') continue;
        $type = (isset($types[$i]) && in_array($types[$i], array('IPv4', 'IPv6'), true)) ? $types[$i] : 'IPv4';
        $entries[] = array('ip' => $ip, 'ipType' => $type);
    }

    if (empty($entries)) {
        $message = 'Въведете поне един IP адрес.';
        $messageType = 'error';
    } else {
        $url = rtrim($config['base_url'], '/') . '/ip-inventory/ip-pool';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS => json_encode(array('ipAddresses' => $entries)),
            CURLOPT_TIMEOUT => 10,
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $message = 'Грешка при връзка с API: ' . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8');
            $messageType = 'error';
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $message = 'Успешно добавени ' . count($entries) . ' IP адрес(а) в pool.';
            $messageType = 'success';
        } else {
            $body = json_decode($response, true);
            $msg = (is_array($body) && isset($body['statusMessage'])) ? $body['statusMessage'] : $response;
            $message = 'Грешка от API (HTTP ' . $httpCode . '): ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Inventory – Добавяне в pool</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.35rem; margin-bottom: 1rem; }
        .message { padding: 0.75rem; margin-bottom: 1rem; border-radius: 6px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
        th, td { padding: 0.5rem; text-align: left; }
        th { font-weight: 600; font-size: 0.9rem; }
        input[type="text"] { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; }
        select { padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; min-width: 100px; }
        button { padding: 0.6rem 1.2rem; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        button:hover { background: #0056b3; }
        .hint { font-size: 0.85rem; color: #666; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <h1>IP Inventory – Добавяне на IP адреси в pool</h1>

    <?php if ($message !== ''): ?>
        <div class="message <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <table>
            <thead>
                <tr>
                    <th>IP адрес</th>
                    <th>Тип</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < 10; $i++): ?>
                <tr>
                    <td><input type="text" name="ip[]" placeholder="напр. 192.168.1.1 или 2a01::1" value=""></td>
                    <td>
                        <select name="ipType[]">
                            <option value="IPv4">IPv4</option>
                            <option value="IPv6">IPv6</option>
                        </select>
                    </td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <button type="submit">Добави в pool</button>
    </form>
    <p class="hint">Празните редове се пропускат. API: <?php echo htmlspecialchars($config['base_url'], ENT_QUOTES, 'UTF-8'); ?> (задава се в config.conf: api_url)</p>
</body>
</html>
