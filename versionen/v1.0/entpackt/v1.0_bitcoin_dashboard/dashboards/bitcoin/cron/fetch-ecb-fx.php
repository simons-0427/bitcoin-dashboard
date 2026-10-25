<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

function bitcoin_ecb_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function bitcoin_ecb_decimal(string $raw): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $raw)) {
        throw new RuntimeException('ECB lieferte einen ungültigen Dezimalwert.');
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $fraction = substr($fraction, 0, 12);
    $value = ltrim($whole, '0');
    $value = $value === '' ? '0' : $value;
    if (strlen($value) > 24) {
        throw new RuntimeException('ECB-Kurs überschreitet DECIMAL(36,12).');
    }
    $fraction = rtrim($fraction, '0');
    return $fraction === '' ? $value : $value . '.' . $fraction;
}

function bitcoin_ecb_fetch(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Die PHP-cURL-Erweiterung fehlt.');
    }
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Accept: text/csv'],
            CURLOPT_USERAGENT => 'smstark-bitcoin-data/1.0',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body !== false && $status === 200) {
            return $body;
        }
        if ($status !== 429 && $status < 500 && $status !== 0) {
            throw new RuntimeException('ECB-Datenportal antwortete mit HTTP ' . $status . '.');
        }
        if ($attempt === 4) {
            throw new RuntimeException('ECB-Datenportal blieb nicht erreichbar: ' . ($error ?: 'HTTP ' . $status));
        }
        sleep(min(16, 2 ** $attempt));
    }
    throw new RuntimeException('ECB-Abruf konnte nicht abgeschlossen werden.');
}

if ($argc !== 3 || !ctype_digit($argv[1]) || (int) $argv[1] < 1 || (int) $argv[1] > 30) {
    bitcoin_ecb_fail('Aufruf: php fetch-ecb-fx.php <tage:1-30> /privater/pfad/ausgabe.csv');
}

try {
    $days = (int) $argv[1];
    $utc = new DateTimeZone('UTC');
    $end = (new DateTimeImmutable('yesterday', $utc))->format('Y-m-d');
    $start = (new DateTimeImmutable($end, $utc))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $query = http_build_query([
        'startPeriod' => $start,
        'endPeriod' => $end,
        'format' => 'csvdata',
        'detail' => 'dataonly',
    ], '', '&', PHP_QUERY_RFC3986);
    $body = bitcoin_ecb_fetch('https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?' . $query);
    $lines = preg_split('/\r?\n/', trim($body));
    $header = str_getcsv((string) array_shift($lines), ',', '"', '');
    $dateIndex = array_search('TIME_PERIOD', $header, true);
    $valueIndex = array_search('OBS_VALUE', $header, true);
    $keyIndex = array_search('KEY', $header, true);
    if ($dateIndex === false || $valueIndex === false || $keyIndex === false) {
        throw new RuntimeException('ECB-Antwort hat nicht das erwartete CSV-Format.');
    }

    $output = fopen('php://temp', 'w+');
    if ($output === false) {
        throw new RuntimeException('Temporäre CSV konnte nicht erstellt werden.');
    }
    fputcsv($output, ['metric_id', 'series_key', 'observation_date', 'value', 'source_slug', 'source_timestamp', 'quality_status', 'source_revision', 'raw_payload_hash'], ',', '"', '');
    $seen = [];
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $cells = str_getcsv($line, ',', '"', '');
        $date = (string) ($cells[$dateIndex] ?? '');
        $raw = (string) ($cells[$valueIndex] ?? '');
        if (($cells[$keyIndex] ?? null) !== 'EXR.D.USD.EUR.SP00.A' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $start || $date > $end || isset($seen[$date])) {
            throw new RuntimeException('ECB-Antwort enthält eine fremde, doppelte oder ungültige Beobachtung.');
        }
        if ($raw === '') {
            continue;
        }
        $seen[$date] = true;
        $value = bitcoin_ecb_decimal($raw);
        $hash = hash('sha256', "ecb_reference_rates-api\x1f{$date}\x1fEXR.D.USD.EUR.SP00.A\x1f{$raw}");
        fputcsv($output, ['fx_usd_per_eur', 'main', $date, $value, 'ecb_reference_rates', '', 'provisional', 'ecb-api:' . $date, $hash], ',', '"', '');
    }
    if ($seen === []) {
        throw new RuntimeException('ECB-Antwort enthält keine Beobachtungen im angeforderten Zeitraum.');
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    if ($csv === false || file_put_contents($argv[2], $csv, LOCK_EX) === false) {
        throw new RuntimeException('Die Importdatei konnte nicht geschrieben werden.');
    }
    $dates = array_keys($seen);
    sort($dates, SORT_STRING);
    echo sprintf("ECB-Referenzkurs: %d Geschäftstage von %s bis %s vorbereitet.\n", count($dates), $dates[0], $dates[array_key_last($dates)]);
} catch (Throwable $exception) {
    bitcoin_ecb_fail('ECB-Abruf fehlgeschlagen: ' . $exception->getMessage());
}
