<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/bitcoin/core-series.php';

function bitcoin_core_download(string $url, string $accept): string
{
    if (!function_exists('curl_init')) throw new RuntimeException('Die PHP-cURL-Erweiterung fehlt.');
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $retryAfter = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept], CURLOPT_USERAGENT => 'smstark-bitcoin-data/1.0',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) $retryAfter = (int) trim(substr($header, 12));
                return strlen($header);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body !== false && $status === 200) {
            if (strlen($body) < 100 || strlen($body) > 5000000) throw new RuntimeException('Unerwartete Antwortgröße.');
            return $body;
        }
        if (!in_array($status, [0, 429], true) && $status < 500) throw new RuntimeException('Datenquelle antwortete mit HTTP ' . $status . '.');
        if ($attempt === 3) throw new RuntimeException('Datenquelle nicht erreichbar: ' . ($error ?: 'HTTP ' . $status));
        sleep(min(16, $retryAfter > 0 ? $retryAfter : 2 ** $attempt));
    }
    throw new RuntimeException('Datenabruf fehlgeschlagen.');
}

try {
    $args = array_slice($argv, 1);
    if (count($args) < 2 || count($args) > 3 || ($args[0] !== 'all' && (!ctype_digit($args[0]) || (int) $args[0] < 7 || (int) $args[0] > 180))) {
        throw new RuntimeException('Aufruf: php fetch-core-series.php <all|tage:7-180> /privater/pfad/ausgabe.csv [--end=JJJJ-MM-TT]');
    }
    $utc = new DateTimeZone('UTC');
    $yesterday = (new DateTimeImmutable('yesterday', $utc))->format('Y-m-d');
    $end = $yesterday;
    if (isset($args[2])) {
        if (!preg_match('/^--end=(\d{4}-\d{2}-\d{2})$/D', $args[2], $match) || !bitcoin_core_date($match[1]) || $match[1] > $yesterday) {
            throw new RuntimeException('Enddatum muss ein abgeschlossener UTC-Tag sein.');
        }
        $end = $match[1];
    }
    $start = $args[0] === 'all' ? '2009-01-03' : (new DateTimeImmutable($end, $utc))->modify('-' . ((int) $args[0] - 1) . ' days')->format('Y-m-d');
    $sources = [
        'fear_greed_index' => ['https://api.alternative.me/fng/?limit=0', 'application/json'],
        'difficulty' => ['https://raw.githubusercontent.com/diegorllanos/open-bitcoin-metrics/main/metrics/obm_difficulty_eod_daily/obm_difficulty_eod_daily.csv', 'text/csv'],
    ];
    $rows = [];
    $summaries = [];
    foreach ($sources as $metric => [$url, $accept]) {
        $result = bitcoin_core_convert($metric, bitcoin_core_download($url, $accept), $start, $end);
        if ($result['rows'] === []) throw new RuntimeException($metric . ': keine Daten im gewählten Fenster.');
        $rows = array_merge($rows, $result['rows']);
        $summaries[] = $result['summary'];
    }
    $destination = $args[1];
    if (!is_dir(dirname($destination))) throw new RuntimeException('Das Ausgabeverzeichnis fehlt.');
    umask(0077);
    $temporary = tempnam(dirname($destination), '.core-series-');
    if ($temporary === false) throw new RuntimeException('Temporäre Datei konnte nicht erstellt werden.');
    try {
        $handle = fopen($temporary, 'wb');
        if ($handle === false) throw new RuntimeException('Importdatei konnte nicht geöffnet werden.');
        try {
            if (fputcsv($handle, explode(',', BITCOIN_CORE_HEADER), ',', '"', '') === false) throw new RuntimeException('CSV-Schreibfehler.');
            foreach ($rows as $row) {
                if (fputcsv($handle, $row, ',', '"', '') === false) throw new RuntimeException('CSV-Schreibfehler.');
            }
        } finally { fclose($handle); }
        if (!rename($temporary, $destination)) throw new RuntimeException('Importdatei konnte nicht atomar ersetzt werden.');
    } finally { if (is_file($temporary)) unlink($temporary); }
    echo json_encode(['windowStart' => $start, 'windowEnd' => $end, 'records' => count($rows), 'metrics' => $summaries,
        'outputSha256' => hash_file('sha256', $destination), 'output' => $destination], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fear-&-Greed-/Difficulty-Abruf fehlgeschlagen: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
