<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/includes/bitcoin/bootstrap.php';

const BITCOIN_CFTC_DATASET_ID = '6dca-aqww';
const BITCOIN_CFTC_IMPORT_HEADER = [
    'metric_id', 'series_key', 'observation_date', 'value', 'source_slug',
    'source_timestamp', 'quality_status', 'source_revision', 'raw_payload_hash',
];
const BITCOIN_CFTC_MARKETS = [
    'BITCOIN-USD - CBOE FUTURES EXCHANGE' => ['multiplier_tenths' => 10, 'contract_btc' => '1'],
    'BITCOIN - CHICAGO MERCANTILE EXCHANGE' => ['multiplier_tenths' => 50, 'contract_btc' => '5'],
    'MICRO BITCOIN - CHICAGO MERCANTILE EXCHANGE' => ['multiplier_tenths' => 1, 'contract_btc' => '0.1'],
];

function bitcoin_cftc_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function bitcoin_cftc_request(string $url): array
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
            CURLOPT_USERAGENT => 'smstark-bitcoin-dashboard/1.0',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = max(0, (int) trim(substr($header, strlen('Retry-After:'))));
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
            if (!is_array($payload) || $payload === []) {
                throw new RuntimeException('CFTC lieferte keine gültige Datensatzliste.');
            }
            return $payload;
        }
        if ($status !== 429 && $status < 500 && $body !== false) {
            throw new RuntimeException("CFTC antwortete mit HTTP {$status}.");
        }
        if ($attempt === 4) {
            throw new RuntimeException('CFTC-Abruf fehlgeschlagen: ' . ($error !== '' ? $error : "HTTP {$status}"));
        }
        sleep(min(30, max(1, $retryAfter > 0 ? $retryAfter : (2 ** $attempt))));
    }
    throw new RuntimeException('CFTC-Abruf abgebrochen.');
}

function bitcoin_cftc_decimal_scaled(string $value): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value)) {
        throw new RuntimeException('Ungültiger Coin-Metrics-USD-Preis.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $digits = $whole . str_pad(substr($fraction, 0, 12), 12, '0');
    if (strlen($fraction) > 12 && $fraction[12] >= '5') {
        $digits = bitcoin_cftc_increment($digits);
    }
    $digits = ltrim($digits, '0');
    return $digits === '' ? '0' : $digits;
}

function bitcoin_cftc_increment(string $digits): string
{
    $carry = 1;
    for ($index = strlen($digits) - 1; $index >= 0 && $carry === 1; $index--) {
        $next = (int) $digits[$index] + 1;
        $digits[$index] = (string) ($next % 10);
        $carry = $next === 10 ? 1 : 0;
    }
    return $carry === 1 ? '1' . $digits : $digits;
}

function bitcoin_cftc_multiply_small(string $digits, int $factor): string
{
    if ($factor < 0) {
        throw new RuntimeException('Negativer Open-Interest-Multiplikator.');
    }
    $carry = 0;
    $result = '';
    for ($index = strlen($digits) - 1; $index >= 0; $index--) {
        $product = ((int) $digits[$index] * $factor) + $carry;
        $result = (string) ($product % 10) . $result;
        $carry = intdiv($product, 10);
    }
    while ($carry > 0) {
        $result = (string) ($carry % 10) . $result;
        $carry = intdiv($carry, 10);
    }
    $result = ltrim($result, '0');
    return $result === '' ? '0' : $result;
}

function bitcoin_cftc_scaled_product(string $price, int $btcTenths): string
{
    $product = bitcoin_cftc_multiply_small(bitcoin_cftc_decimal_scaled($price), $btcTenths);
    $last = (int) substr($product, -1);
    $quotient = strlen($product) > 1 ? substr($product, 0, -1) : '0';
    if ($last >= 5) {
        $quotient = bitcoin_cftc_increment($quotient);
    }
    $whole = ltrim(substr($quotient, 0, -12), '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 24) {
        throw new RuntimeException('Futures-Nominalwert liegt außerhalb von DECIMAL(36,12).');
    }
    $fraction = rtrim(substr(str_pad($quotient, 13, '0', STR_PAD_LEFT), -12), '0');
    return $fraction === '' ? $whole : $whole . '.' . $fraction;
}

function bitcoin_cftc_canonical_decimal(string $value): string
{
    if (!str_contains($value, '.')) {
        return $value;
    }
    return rtrim(rtrim($value, '0'), '.');
}

function bitcoin_cftc_source_payload(string $date, array $positions, string $price, string $priceRevision, string $priceHash): string
{
    return json_encode([
        'version' => 'cftc-legacy-v1',
        'report_date' => $date,
        'positions' => $positions,
        'price_usd' => $price,
        'price_source_revision' => $priceRevision,
        'price_raw_payload_hash' => $priceHash,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function bitcoin_cftc_recent_start(?string $latest): ?string
{
    if ($latest === null || $latest === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $latest, new DateTimeZone('UTC'));
    if ($date === false || $date->format('Y-m-d') !== $latest || $latest > gmdate('Y-m-d')) {
        throw new RuntimeException('Ungültiger letzter CFTC-Berichtstag in der Datenbank.');
    }
    return $date->modify('-56 days')->format('Y-m-d');
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__FILE__)) return;

$temporaryFile = null;
$handle = null;
try {
    $arguments = array_slice($argv, 1);
    $recent = ($arguments[0] ?? '') === '--recent';
    if ($recent) array_shift($arguments);
    if (count($arguments) !== 1 || $arguments[0] === '') {
        throw new RuntimeException('Aufruf: php fetch-cftc-futures-open-interest.php [--recent] /pfad/zur/ausgabe.csv');
    }
    $markets = BITCOIN_CFTC_MARKETS;
    $marketList = implode(',', array_map(static fn (string $name): string => "'" . str_replace("'", "''", $name) . "'", array_keys($markets)));
    $where = 'market_and_exchange_names IN (' . $marketList . ')';
    $pdo = null;
    $windowStart = null;
    if ($recent) {
        $pdo = bitcoin_database();
        $latest = $pdo->query("SELECT MAX(observation_date) FROM bitcoin_observations WHERE metric_id = 'futures_open_interest_usd' AND series_key = 'main'")->fetchColumn();
        $windowStart = bitcoin_cftc_recent_start(is_string($latest) ? $latest : null);
        if ($windowStart !== null) $where .= " AND report_date_as_yyyy_mm_dd >= '" . $windowStart . "T00:00:00'";
    }
    $query = http_build_query([
        '$select' => 'report_date_as_yyyy_mm_dd,market_and_exchange_names,open_interest_all',
        '$where' => $where,
        '$order' => 'report_date_as_yyyy_mm_dd ASC',
        '$limit' => '10000',
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://publicreporting.cftc.gov/resource/' . BITCOIN_CFTC_DATASET_ID . '.json?' . $query;
    $sourceRows = bitcoin_cftc_request($url);
    $groups = [];
    $seen = [];
    foreach ($sourceRows as $row) {
        $market = (string) ($row['market_and_exchange_names'] ?? '');
        if (!isset($markets[$market])) {
            throw new RuntimeException('Unerwarteter CFTC-Markt: ' . $market);
        }
        $date = substr((string) ($row['report_date_as_yyyy_mm_dd'] ?? ''), 0, 10);
        $openInterest = (string) ($row['open_interest_all'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^(?:0|[1-9][0-9]*)$/', $openInterest)) {
            throw new RuntimeException('CFTC lieferte ein ungültiges Datum oder Open-Interest-Feld.');
        }
        if ($date > gmdate('Y-m-d') || ($windowStart !== null && $date < $windowStart)) {
            throw new RuntimeException('CFTC-Bericht liegt außerhalb des angeforderten Zeitraums.');
        }
        if (isset($seen[$date][$market])) {
            throw new RuntimeException("Doppelter CFTC-Marktbericht für {$date}: {$market}");
        }
        $seen[$date][$market] = true;
        $groups[$date]['btc_tenths'] = ($groups[$date]['btc_tenths'] ?? 0)
            + ((int) $openInterest * $markets[$market]['multiplier_tenths']);
        $groups[$date]['positions'][] = [
            'market' => $market,
            'open_interest_all' => $openInterest,
            'contract_btc' => $markets[$market]['contract_btc'],
        ];
    }
    if ($groups === []) {
        throw new RuntimeException('CFTC lieferte keine passenden Bitcoin-Futures-Berichte.');
    }
    ksort($groups, SORT_STRING);
    $firstDate = array_key_first($groups);
    $lastDate = array_key_last($groups);
    $pdo ??= bitcoin_database();
    $priceQuery = $pdo->prepare(<<<'SQL'
        SELECT observation_date, value, source_revision, raw_payload_hash
        FROM bitcoin_observations
        WHERE metric_id = 'price_usd' AND series_key = 'main'
          AND observation_date BETWEEN :start_date AND :end_date
        SQL);
    $priceQuery->execute(['start_date' => $firstDate, 'end_date' => $lastDate]);
    $prices = [];
    foreach ($priceQuery->fetchAll() as $priceRow) {
        $prices[$priceRow['observation_date']] = [
            'value' => bitcoin_cftc_canonical_decimal((string) $priceRow['value']),
            'revision' => (string) $priceRow['source_revision'],
            'hash' => strtolower((string) $priceRow['raw_payload_hash']),
        ];
    }
    $outputFile = $arguments[0];
    $outputDirectory = dirname($outputFile);
    if (!is_dir($outputDirectory) || !is_writable($outputDirectory)) {
        throw new RuntimeException('Das Zielverzeichnis für den CFTC-Import fehlt oder ist nicht beschreibbar.');
    }
    $temporaryFile = tempnam($outputDirectory, '.cftc-');
    if ($temporaryFile === false) {
        throw new RuntimeException('Temporäre CFTC-Importdatei konnte nicht erstellt werden.');
    }
    $handle = fopen($temporaryFile, 'wb');
    if ($handle === false) {
        @unlink($temporaryFile);
        throw new RuntimeException('Temporäre CFTC-Importdatei konnte nicht geöffnet werden.');
    }
    fputcsv($handle, BITCOIN_CFTC_IMPORT_HEADER, ',', '"', '');
    $count = 0;
    foreach ($groups as $date => $group) {
        if (!isset($prices[$date])) {
            throw new RuntimeException('Für CFTC-Berichtstag ' . $date . ' fehlt der Coin-Metrics-USD-Preis.');
        }
        $price = $prices[$date];
        usort($group['positions'], static fn (array $left, array $right): int => strcmp($left['market'], $right['market']));
        $value = bitcoin_cftc_scaled_product($price['value'], (int) $group['btc_tenths']);
        $payload = bitcoin_cftc_source_payload($date, $group['positions'], $price['value'], $price['revision'], $price['hash']);
        $hash = hash('sha256', $payload);
        $revision = 'cftc-legacy-v1:' . $date . ':cm:' . substr($price['hash'], 0, 12);
        fputcsv($handle, [
            'futures_open_interest_usd', 'main', $date, $value, 'cftc_cot_legacy',
            '', 'provisional', $revision, $hash,
        ], ',', '"', '');
        $count++;
    }
    fclose($handle);
    chmod($temporaryFile, 0600);
    if (!rename($temporaryFile, $outputFile)) {
        @unlink($temporaryFile);
        throw new RuntimeException('CFTC-Importdatei konnte nicht atomar bereitgestellt werden.');
    }
    echo sprintf("CFTC-Import erstellt: %d Wochen von %s bis %s.\n", $count, $firstDate, $lastDate);
} catch (Throwable $exception) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    if (is_string($temporaryFile) && is_file($temporaryFile)) {
        @unlink($temporaryFile);
    }
    bitcoin_cftc_fail('CFTC-Abruf abgebrochen: ' . $exception->getMessage());
}
