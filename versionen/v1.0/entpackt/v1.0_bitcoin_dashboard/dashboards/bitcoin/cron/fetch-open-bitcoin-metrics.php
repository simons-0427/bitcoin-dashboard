<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const BITCOIN_OBM_SOURCE = 'open_bitcoin_metrics_obm';
const BITCOIN_OBM_CSV_HEADER = 'date,series_id,value,unit,frequency,release_version';
const BITCOIN_OBM_IMPORT_HEADER = 'metric_id,series_key,observation_date,value,source_slug,source_timestamp,quality_status,source_revision,raw_payload_hash';
const BITCOIN_OBM_BASE_URL = 'https://raw.githubusercontent.com/diegorllanos/open-bitcoin-metrics/main/metrics';
const BITCOIN_OBM_SERIES = [
    [
        'series' => 'obm_block_weight_wu_daily',
        'metric' => 'block_weight_mwu',
        'unit' => 'WU',
        'allow_undefined' => false,
        'scale_wu_to_mwu' => true,
    ],
    [
        'series' => 'obm_cdd_btcxdays_daily',
        'metric' => 'coin_days_destroyed',
        'unit' => 'BTC-days',
        'allow_undefined' => false,
        'scale_wu_to_mwu' => false,
    ],
    [
        'series' => 'obm_raw_output_value_btc_daily',
        'metric' => 'transaction_volume_btc',
        'unit' => 'BTC',
        'allow_undefined' => false,
        'scale_wu_to_mwu' => false,
    ],
    [
        'series' => 'obm_dormancy_days_daily',
        'metric' => 'average_coin_dormancy_days',
        'unit' => 'days',
        'allow_undefined' => true,
        'scale_wu_to_mwu' => false,
    ],
    [
        'series' => 'obm_liveliness_ratio_daily',
        'metric' => 'liveliness',
        'unit' => 'ratio',
        'allow_undefined' => false,
        'scale_wu_to_mwu' => false,
    ],
    [
        'series' => 'obm_utxo_eod_count_daily',
        'metric' => 'utxo_count',
        'unit' => 'outputs',
        'allow_undefined' => true,
        'scale_wu_to_mwu' => false,
    ],
];

function bitcoin_obm_decimal(string $raw): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $raw)) {
        throw new RuntimeException('Open Bitcoin Metrics lieferte einen ungültigen Dezimalwert.');
    }
    [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $digits = $integer . str_pad(substr($fraction, 0, 12), 12, '0');
    if (strlen($fraction) > 12 && $fraction[12] >= '5') {
        $carry = 1;
        for ($index = strlen($digits) - 1; $index >= 0 && $carry === 1; $index--) {
            $digit = (int) $digits[$index] + 1;
            $digits[$index] = (string) ($digit % 10);
            $carry = $digit === 10 ? 1 : 0;
        }
        if ($carry === 1) {
            $digits = '1' . $digits;
        }
    }
    $whole = ltrim(substr($digits, 0, -12), '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 24) {
        throw new RuntimeException('Open Bitcoin Metrics-Wert überschreitet DECIMAL(36,12).');
    }
    $fraction = rtrim(substr($digits, -12), '0');
    return $fraction === '' ? $whole : $whole . '.' . $fraction;
}

function bitcoin_obm_weight_mwu(string $raw): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]*)$/', $raw)) {
        throw new RuntimeException('OBM-Blockgewicht ist keine ganzzahlige WU-Angabe.');
    }
    $digits = str_pad($raw, 7, '0', STR_PAD_LEFT);
    $whole = ltrim(substr($digits, 0, -6), '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 24) {
        throw new RuntimeException('OBM-Blockgewicht überschreitet DECIMAL(36,12).');
    }
    $fraction = rtrim(substr($digits, -6), '0');
    return $fraction === '' ? $whole : $whole . '.' . $fraction;
}

function bitcoin_obm_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function bitcoin_obm_download(string $url): string
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
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Accept: text/csv'],
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
            if (strlen($body) < 100 || strlen($body) > 20000000) {
                throw new RuntimeException('Unerwartete OBM-Dateigröße (' . strlen($body) . ' Bytes).');
            }
            return $body;
        }
        if ($status !== 429 && $status < 500 && $status !== 0) {
            throw new RuntimeException('OBM-Archiv antwortete mit HTTP ' . $status . '.');
        }
        if ($attempt === 4) {
            throw new RuntimeException('OBM-Archiv blieb nicht erreichbar: ' . ($error ?: 'HTTP ' . $status));
        }
        sleep(min(60, $retryAfter > 0 ? $retryAfter : 2 ** $attempt));
    }
    throw new RuntimeException('OBM-Abruf konnte nicht abgeschlossen werden.');
}

if ($argc !== 3 || !ctype_digit($argv[1]) || (int) $argv[1] < 1 || (int) $argv[1] > 180) {
    fwrite(STDERR, "Aufruf: php fetch-open-bitcoin-metrics.php <tage:1-180> /privater/pfad/ausgabe.csv\n");
    exit(1);
}

try {
    $days = (int) $argv[1];
    $utc = new DateTimeZone('UTC');
    $end = (new DateTimeImmutable('yesterday', $utc))->format('Y-m-d');
    $start = (new DateTimeImmutable($end, $utc))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $output = fopen('php://temp', 'w+');
    if ($output === false) {
        throw new RuntimeException('Temporäre Importdatei konnte nicht erstellt werden.');
    }
    fputcsv($output, explode(',', BITCOIN_OBM_IMPORT_HEADER), ',', '"', '');

    $commonRelease = '';
    $totalRows = 0;
    $summaries = [];
    foreach (BITCOIN_OBM_SERIES as $definition) {
        $url = BITCOIN_OBM_BASE_URL . '/' . $definition['series'] . '/' . $definition['series'] . '.csv';
        $body = bitcoin_obm_download($url);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $lines = preg_split('/\r?\n/', rtrim((string) $text, "\r\n"));
        if ($lines === false || array_shift($lines) !== BITCOIN_OBM_CSV_HEADER || $lines === []) {
            throw new RuntimeException($definition['series'] . ': CSV-Kopf fehlt oder Datei enthält keine Datenzeilen.');
        }
        $previousDate = '';
        $release = '';
        $firstDate = '';
        $lastDate = '';
        $rowsWritten = 0;
        $undefinedSkipped = 0;
        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== 6) {
                throw new RuntimeException($definition['series'] . ', Zeile ' . ($index + 2) . ': falsche Spaltenzahl.');
            }
            [$date, $series, $rawValue, $unit, $frequency, $rowRelease] = $cells;
            if (
                $series !== $definition['series']
                || $unit !== $definition['unit']
                || $frequency !== 'daily'
                || !bitcoin_obm_date($date)
                || ($previousDate !== '' && $date <= $previousDate)
                || !preg_match('/^OBM v[0-9]+\.[0-9]+\.[0-9]+$/', $rowRelease)
            ) {
                throw new RuntimeException($definition['series'] . ', Zeile ' . ($index + 2) . ': Serie, Datum, Einheit, Frequenz oder Release weicht ab.');
            }
            if ($release !== '' && $release !== $rowRelease) {
                throw new RuntimeException($definition['series'] . ': mehrere Releases in einer Datei.');
            }
            if ($commonRelease !== '' && $commonRelease !== $rowRelease) {
                throw new RuntimeException('Die OBM-Dateien enthalten unterschiedliche Releases.');
            }
            $release = $rowRelease;
            $commonRelease = $rowRelease;
            if ($firstDate === '') {
                $firstDate = $date;
            }
            $lastDate = $date;
            $previousDate = $date;

            if ($rawValue === 'NaN') {
                if (!$definition['allow_undefined']) {
                    throw new RuntimeException($definition['series'] . ', Zeile ' . ($index + 2) . ': undefinierter Wert ist nicht erlaubt.');
                }
                $undefinedSkipped++;
                continue;
            }
            $value = $definition['scale_wu_to_mwu'] ? bitcoin_obm_weight_mwu($rawValue) : bitcoin_obm_decimal($rawValue);
            if ($date < $start || $date > $end) {
                continue;
            }
            $hash = hash('sha256', 'open-bitcoin-metrics-obm' . "\x1f" . $line);
            $revision = strtolower(str_replace(' ', '-', $rowRelease)) . ':' . $definition['series'];
            fputcsv($output, [
                $definition['metric'],
                'main',
                $date,
                $value,
                BITCOIN_OBM_SOURCE,
                '',
                'provisional',
                $revision,
                $hash,
            ], ',', '"', '');
            $rowsWritten++;
            $totalRows++;
        }
        $summaries[] = [
            'metric' => $definition['metric'],
            'sourceFirst' => $firstDate,
            'sourceLast' => $lastDate,
            'rowsInWindow' => $rowsWritten,
            'undefinedValuesSkipped' => $undefinedSkipped,
        ];
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    if ($csv === false || file_put_contents($argv[2], $csv, LOCK_EX) === false) {
        throw new RuntimeException('Die OBM-Importdatei konnte nicht geschrieben werden.');
    }
    echo json_encode([
        'source' => 'Open Bitcoin Metrics, CC BY 4.0',
        'release' => $commonRelease,
        'windowStart' => $start,
        'windowEnd' => $end,
        'records' => $totalRows,
        'metrics' => $summaries,
        'output' => $argv[2],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Open-Bitcoin-Metrics-Abruf fehlgeschlagen: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
