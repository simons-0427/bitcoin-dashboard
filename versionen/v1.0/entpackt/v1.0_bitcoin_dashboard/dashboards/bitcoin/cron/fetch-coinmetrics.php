<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const BITCOIN_COINMETRICS_FIELDS = [
    'AdrActCnt' => 'active_addresses',
    'TxCnt' => 'transactions_count',
    'TxTfrCnt' => 'transfer_count',
    'BlkCnt' => 'blocks_count',
    'SplyCur' => 'supply_btc',
    'FeeTotNtv' => 'fees_total_btc',
    'PriceUSD' => 'price_usd',
    'CapMrktEstUSD' => 'market_cap_estimated_usd',
    'CapMVRVCur' => 'mvrv_ratio',
    'AdrBalCnt' => 'addresses_nonzero_balance',
    'FlowInExNtv' => 'exchange_inflow_btc',
    'FlowOutExNtv' => 'exchange_outflow_btc',
    'FlowInExUSD' => 'exchange_inflow_usd',
    'FlowOutExUSD' => 'exchange_outflow_usd',
    'SplyExUSD' => 'exchange_balance_usd',
    'HashRate' => ['hashrate_eh_s', '0.000001'],
    'SplyExNtv' => 'exchange_balance_btc',
    'IssTotUSD' => 'issued_usd_daily',
    'SplyExpFut10yr' => 'projected_supply_10y_btc',
    'volume_reported_spot_usd_1d' => 'spot_volume_usd',
];

function bitcoin_coinmetrics_increment_digits(string $digits): string
{
    for ($index = strlen($digits) - 1; $index >= 0; $index--) {
        $digit = (int) $digits[$index] + 1;
        $digits[$index] = (string) ($digit % 10);
        if ($digit < 10) return $digits;
    }
    return '1' . $digits;
}

function bitcoin_coinmetrics_round(string $raw, string $multiplier = '1'): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $raw)) {
        throw new RuntimeException('Coin Metrics lieferte einen ungültigen Dezimalwert.');
    }
    if (!in_array($multiplier, ['1', '0.000001'], true)) {
        throw new RuntimeException('Nicht unterstützter Coin-Metrics-Skalierungsfaktor.');
    }
    [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $digits = $integer . str_pad(substr($fraction, 0, 12), 12, '0');
    if (strlen($fraction) > 12 && $fraction[12] >= '5') {
        $digits = bitcoin_coinmetrics_increment_digits($digits);
    }
    if ($multiplier === '0.000001') {
        // Match the backfill: round raw input to 12 decimals, then divide by 10^6.
        // Decimal strings avoid binary floating point errors in the EH/s conversion.
        $roundUp = $digits[strlen($digits) - 6] >= '5';
        $digits = substr($digits, 0, -6);
        if ($roundUp) $digits = bitcoin_coinmetrics_increment_digits($digits);
        $digits = str_pad($digits, 13, '0', STR_PAD_LEFT);
    }
    $whole = ltrim(substr($digits, 0, -12), '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 24) {
        throw new RuntimeException('Coin-Metrics-Wert überschreitet DECIMAL(36,12).');
    }
    $decimals = rtrim(substr($digits, -12), '0');
    return $decimals === '' ? $whole : $whole . '.' . $decimals;
}

function bitcoin_coinmetrics_request(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Die PHP-cURL-Erweiterung fehlt.');
    }
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $retryAfter = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'smstark-bitcoin-data/1.0',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = (int) trim(substr($header, strlen('Retry-After:')));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body !== false && $status === 200) {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
                throw new RuntimeException('Coin Metrics lieferte keine gültige Datenliste.');
            }
            return $payload;
        }
        if ($status !== 429 && $status < 500 && $status !== 0) {
            throw new RuntimeException('Coin-Metrics-API antwortete mit HTTP ' . $status . '.');
        }
        if ($attempt === 4) {
            throw new RuntimeException('Coin-Metrics-API blieb nicht erreichbar: ' . ($error ?: 'HTTP ' . $status));
        }
        sleep(min(30, $retryAfter > 0 ? $retryAfter : 2 ** $attempt));
    }
    throw new RuntimeException('Coin-Metrics-Abruf konnte nicht abgeschlossen werden.');
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__FILE__)) return;

if ($argc !== 3 || !ctype_digit($argv[1]) || (int) $argv[1] < 1 || (int) $argv[1] > 30) {
    fwrite(STDERR, "Aufruf: php fetch-coinmetrics.php <tage:1-30> /privater/pfad/ausgabe.csv\n");
    exit(1);
}

try {
    $days = (int) $argv[1];
    $utc = new DateTimeZone('UTC');
    $end = (new DateTimeImmutable('yesterday', $utc))->format('Y-m-d');
    $start = (new DateTimeImmutable($end, $utc))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $query = http_build_query([
        'assets' => 'btc',
        'metrics' => implode(',', array_keys(BITCOIN_COINMETRICS_FIELDS)),
        'frequency' => '1d',
        'start_time' => $start,
        'end_time' => $end,
        'page_size' => 100,
    ], '', '&', PHP_QUERY_RFC3986);
    $payload = bitcoin_coinmetrics_request('https://community-api.coinmetrics.io/v4/timeseries/asset-metrics?' . $query);
    if (!empty($payload['next_page_token'])) {
        throw new RuntimeException('Unerwartete Paginierung beim kurzen Tagesabruf.');
    }
    $rows = $payload['data'];
    usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
    $output = fopen('php://temp', 'w+');
    if ($output === false) {
        throw new RuntimeException('Temporäre CSV konnte nicht erstellt werden.');
    }
    fputcsv($output, ['metric_id', 'series_key', 'observation_date', 'value', 'source_slug', 'source_timestamp', 'quality_status', 'source_revision', 'raw_payload_hash'], ',', '"', '');
    $seen = [];
    $count = 0;
    foreach ($rows as $row) {
        $date = substr((string) ($row['time'] ?? ''), 0, 10);
        if (($row['asset'] ?? null) !== 'btc' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $start || $date > $end || isset($seen[$date])) {
            throw new RuntimeException('Coin Metrics lieferte fremde, doppelte oder ungültige Tage.');
        }
        $seen[$date] = true;
        foreach (BITCOIN_COINMETRICS_FIELDS as $field => $definition) {
            $raw = $row[$field] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if (!is_string($raw)) {
                throw new RuntimeException('Coin Metrics lieferte einen unerwarteten Werttyp.');
            }
            [$metricId, $multiplier] = is_array($definition) ? $definition : [$definition, '1'];
            $value = bitcoin_coinmetrics_round($raw, $multiplier);
            $hash = hash('sha256', "coinmetrics-btc-api\x1f{$date}\x1f{$field}\x1f{$raw}");
            fputcsv($output, [$metricId, 'main', $date, $value, 'coinmetrics_community_btc', '', 'provisional', 'btc-api:' . $date, $hash], ',', '"', '');
            $count++;
        }
    }
    if ($count === 0) {
        throw new RuntimeException('Coin Metrics lieferte keine abgeschlossenen Beobachtungen.');
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    if ($csv === false || file_put_contents($argv[2], $csv, LOCK_EX) === false) {
        throw new RuntimeException('Die Importdatei konnte nicht geschrieben werden.');
    }
    echo sprintf("Coin Metrics: %d Tage, %d Beobachtungen bis %s vorbereitet.\n", count($seen), $count, $end);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Coin-Metrics-Abruf fehlgeschlagen: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
