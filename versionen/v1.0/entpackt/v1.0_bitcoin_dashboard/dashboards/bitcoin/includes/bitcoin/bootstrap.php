<?php

declare(strict_types=1);

function bitcoin_json(array $payload, int $status = 200, int $maxAge = 0): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header($maxAge > 0 ? 'Cache-Control: public, max-age=' . $maxAge : 'Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    exit;
}

function bitcoin_error(string $code, string $message, int $status): never
{
    bitcoin_json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

function bitcoin_get_only(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        bitcoin_error('METHOD_NOT_ALLOWED', 'Nur GET-Anfragen sind erlaubt.', 405);
    }
}

function bitcoin_date(string $value): ?DateTimeImmutable
{
    try {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    } catch (ValueError) {
        return null;
    }
}

function bitcoin_active_metric_ids(): ?array
{
    $filename = dirname(__DIR__, 2) . '/data/active-metrics.json';
    if (!is_file($filename)) return null;
    $payload = json_decode((string) file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR);
    $ids = $payload['metricIds'] ?? null;
    if (!is_array($ids) || count($ids) !== 50 || count(array_unique($ids)) !== 50) {
        throw new RuntimeException('Der aktive Kennzahlenkatalog muss 50 eindeutige IDs enthalten.');
    }
    foreach ($ids as $id) {
        if (!is_string($id) || !preg_match('/^[a-z0-9_]{1,96}$/D', $id)) throw new RuntimeException('Ungültige aktive Kennzahl.');
    }
    return $ids;
}

function bitcoin_database(): PDO
{
    $configuredPath = getenv('BITCOIN_CONFIG');
    $configPath = is_string($configuredPath) && $configuredPath !== ''
        ? $configuredPath
        : dirname(__DIR__, 2) . '/config/bitcoin.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Bitcoin-Konfiguration fehlt.');
    }

    $config = require $configPath;
    $database = is_array($config) ? ($config['database'] ?? null) : null;
    if (!is_array($database)) {
        throw new RuntimeException('Bitcoin-Datenbankkonfiguration ist ungültig.');
    }
    foreach (['host', 'name', 'user', 'password'] as $key) {
        if (!isset($database[$key]) || !is_string($database[$key]) || $database[$key] === '') {
            throw new RuntimeException('Bitcoin-Datenbankkonfiguration ist unvollständig.');
        }
    }
    $port = filter_var($database['port'] ?? 3306, FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException('Bitcoin-Datenbankport ist ungültig.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $port, $database['name']);
    return new PDO($dsn, $database['user'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
