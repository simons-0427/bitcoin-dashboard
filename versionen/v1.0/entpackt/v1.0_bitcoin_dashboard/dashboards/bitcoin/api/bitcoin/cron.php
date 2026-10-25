<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bitcoin/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/bitcoin/web-cron.php';

try {
    $config = bitcoin_web_config();
    $token = $_SERVER['HTTP_X_BITCOIN_CRON_TOKEN'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)
        || !hash_equals($config['token_hash'], hash('sha256', $token))) {
        bitcoin_error('UNAUTHORIZED', 'Gültiger Bitcoin-Cron-Header erforderlich.', 401);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        bitcoin_error('METHOD_NOT_ALLOWED', 'GET liest den Status; POST startet fällige Läufe.', 405);
    }
    $result = bitcoin_web_handle($config, $method === 'POST');
    bitcoin_json($result['body'], $result['http']);
} catch (Throwable) {
    bitcoin_error('WEB_CRON_UNAVAILABLE', 'Web-Cron ist noch nicht eingerichtet oder nicht verfügbar.', 503);
}
