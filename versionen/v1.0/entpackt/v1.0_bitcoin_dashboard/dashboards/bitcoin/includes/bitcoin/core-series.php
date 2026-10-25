<?php

declare(strict_types=1);

const BITCOIN_CORE_HEADER = 'metric_id,series_key,observation_date,value,source_slug,source_timestamp,quality_status,source_revision,raw_payload_hash';
const BITCOIN_CORE_METHODS = [
    'fear_greed_index' => [
        'source' => 'alternative_me_fng', 'legacy_source' => 'openbitcoin_node_api',
        'archive' => 'openbitcoin_legacy', 'first' => '2018-02-01', 'minimum' => 3000,
    ],
    'difficulty' => [
        'source' => 'open_bitcoin_metrics_obm', 'legacy_source' => 'derived_bitcoin_metrics',
        'archive' => 'coinmetrics_daily_mean_v1', 'first' => '2009-01-03', 'minimum' => 6000,
    ],
];

function bitcoin_core_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function bitcoin_core_decimal(string $raw): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $raw)) {
        throw new RuntimeException('Ungültiger positiver Dezimalwert.');
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $digits = $whole . str_pad(substr($fraction, 0, 12), 12, '0');
    if (strlen($fraction) > 12 && $fraction[12] >= '5') {
        $carry = 1;
        for ($index = strlen($digits) - 1; $index >= 0 && $carry === 1; $index--) {
            $digit = (int) $digits[$index] + 1;
            $digits[$index] = (string) ($digit % 10);
            $carry = $digit === 10 ? 1 : 0;
        }
        if ($carry) $digits = '1' . $digits;
    }
    $whole = ltrim(substr($digits, 0, -12), '0') ?: '0';
    if (strlen($whole) > 24) throw new RuntimeException('Wert überschreitet DECIMAL(36,12).');
    $fraction = rtrim(substr($digits, -12), '0');
    return $fraction === '' ? $whole : $whole . '.' . $fraction;
}

function bitcoin_core_convert(string $metric, string $body, string $start, string $end): array
{
    if (!isset(BITCOIN_CORE_METHODS[$metric]) || !bitcoin_core_date($start) || !bitcoin_core_date($end) || $start > $end) {
        throw new RuntimeException('Ungültige Kennzahl oder Zeitraum.');
    }
    $points = [];
    $undefined = [];
    $sourceDates = [];
    if ($metric === 'fear_greed_index') {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload['metadata']) || !array_key_exists('error', $payload['metadata'])
            || $payload['metadata']['error'] !== null || !is_array($payload['data'] ?? null) || $payload['data'] === []) {
            throw new RuntimeException('Alternative.me meldet einen Fehler oder keine Daten.');
        }
        foreach ($payload['data'] as $record) {
            $timestamp = $record['timestamp'] ?? null;
            $raw = $record['value'] ?? null;
            if (!is_string($timestamp) || !preg_match('/^[0-9]{10}$/D', $timestamp)
                || !is_string($raw) || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $raw) || (int) $raw > 100) {
                throw new RuntimeException('Alternative.me lieferte einen ungültigen Zeitstempel oder Indexwert.');
            }
            $date = gmdate('Y-m-d', (int) $timestamp);
            if ($date < '2018-02-01' || $date > gmdate('Y-m-d') || isset($sourceDates[$date])) {
                throw new RuntimeException('Alternative.me: Datum außerhalb der Historie oder doppelter UTC-Tag.');
            }
            $sourceDates[$date] = true;
            $points[$date] = [$metric, 'main', $date, $raw, 'alternative_me_fng', gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp),
                'provisional', 'alternative-me-fng-v1', hash('sha256', "alternative-me-fng\x1f{$timestamp}\x1f{$raw}")];
        }
    } else {
        $lines = preg_split('/\r?\n/', rtrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $body), "\r\n"));
        if (array_shift($lines) !== 'date,series_id,value,unit,frequency,release_version') {
            throw new RuntimeException('OBM-Difficulty: ungültiger CSV-Kopf.');
        }
        $previous = '';
        $release = '';
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== 6) throw new RuntimeException('OBM-Difficulty: falsche Spaltenzahl.');
            [$date, $series, $raw, $unit, $frequency, $version] = $cells;
            if (!bitcoin_core_date($date) || $date < '2009-01-03' || $date > gmdate('Y-m-d')
                || ($previous !== '' && $date <= $previous) || $series !== 'obm_difficulty_eod_daily'
                || $unit !== 'difficulty' || $frequency !== 'daily' || !preg_match('/^OBM v[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
                || ($release !== '' && $release !== $version)) {
                throw new RuntimeException('OBM-Difficulty: Datum, Einheit, Serie oder Release weicht ab.');
            }
            $previous = $date;
            $release = $version;
            $sourceDates[$date] = true;
            if ($raw === 'NaN') { $undefined[] = $date; continue; }
            $value = bitcoin_core_decimal($raw);
            if ($value === '0') throw new RuntimeException('OBM-Difficulty muss positiv sein.');
            $points[$date] = [$metric, 'main', $date, $value, 'open_bitcoin_metrics_obm', '', 'provisional',
                strtolower(str_replace(' ', '-', $version)) . ':obm_difficulty_eod_daily',
                hash('sha256', "open-bitcoin-metrics-obm\x1f" . $line)];
        }
    }
    ksort($points);
    $sourceDateList = array_keys($sourceDates);
    sort($sourceDateList);
    $rows = array_values(array_filter($points, static fn (array $row): bool => $row[2] >= $start && $row[2] <= $end));
    $missing = [];
    if ($rows !== []) {
        $cursor = new DateTimeImmutable($rows[0][2], new DateTimeZone('UTC'));
        while ($cursor->format('Y-m-d') <= $rows[count($rows) - 1][2]) {
            $date = $cursor->format('Y-m-d');
            if (!isset($points[$date])) $missing[] = $date;
            $cursor = $cursor->modify('+1 day');
        }
    }
    return ['rows' => $rows, 'summary' => [
        'metric' => $metric, 'count' => count($rows), 'first' => $rows[0][2] ?? null,
        'last' => $rows[count($rows) - 1][2] ?? null,
        'sourceFirst' => $sourceDateList[0] ?? null, 'sourceLast' => $sourceDateList[count($sourceDateList) - 1] ?? null,
        'missingDates' => $missing, 'undefinedDates' => $undefined, 'sourceSha256' => hash('sha256', $body),
    ]];
}

function bitcoin_core_preflight(string $filename): void
{
    $handle = fopen($filename, 'rb');
    if ($handle === false) throw new RuntimeException('Importdatei fehlt.');
    $stats = [];
    $keys = [];
    try {
        if (fgetcsv($handle, 0, ',', '"', '') !== explode(',', BITCOIN_CORE_HEADER)) throw new RuntimeException('Importkopf weicht ab.');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) !== 9 || !isset(BITCOIN_CORE_METHODS[$row[0]]) || $row[1] !== 'main'
                || $row[4] !== BITCOIN_CORE_METHODS[$row[0]]['source'] || !bitcoin_core_date($row[2])) {
                throw new RuntimeException('Die Umstellung akzeptiert ausschließlich die beiden neuen Hauptreihen.');
            }
            $key = $row[0] . '/' . $row[2];
            if (isset($keys[$key])) throw new RuntimeException('Doppelter Importtag: ' . $key);
            $keys[$key] = true;
            $stats[$row[0]]['count'] = ($stats[$row[0]]['count'] ?? 0) + 1;
            $stats[$row[0]]['first'] = min($stats[$row[0]]['first'] ?? $row[2], $row[2]);
        }
    } finally { fclose($handle); }
    foreach (BITCOIN_CORE_METHODS as $metric => $method) {
        if (($stats[$metric]['count'] ?? 0) < $method['minimum'] || ($stats[$metric]['first'] ?? '') !== $method['first']) {
            throw new RuntimeException('Die Umstellung benötigt den vollständigen Backfill beider Reihen; Tagesfenster sind nicht zulässig.');
        }
    }
}

function bitcoin_core_validate_row(array $row): void
{
    $method = BITCOIN_CORE_METHODS[$row['metric_id']] ?? null;
    if ($method === null || $row['series_key'] !== 'main' || $row['source_slug'] !== $method['source']) return;
    if ($row['observation_date'] >= gmdate('Y-m-d')) throw new RuntimeException('Neue Hauptreihen akzeptieren nur abgeschlossene UTC-Tage.');
    if ($row['metric_id'] === 'fear_greed_index') {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $row['value']) || (int) $row['value'] > 100
            || $row['source_revision'] !== 'alternative-me-fng-v1') {
            throw new RuntimeException('Ungültiger Alternative.me-Index oder Methodenkennung.');
        }
    } elseif (bitcoin_core_decimal($row['value']) === '0'
        || !preg_match('/^obm-v[0-9]+\.[0-9]+\.[0-9]+:obm_difficulty_eod_daily$/D', $row['source_revision'])) {
        throw new RuntimeException('Ungültige OBM-Difficulty oder Methodenkennung.');
    }
}

function bitcoin_core_promote(PDO $pdo): int
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Historienumstellung benötigt eine Transaktion.');
    $metadata = $pdo->prepare('SELECT s.slug FROM bitcoin_metrics m JOIN bitcoin_data_sources s ON s.id = m.source_id WHERE m.metric_id = :metric');
    $oldSources = $pdo->prepare("SELECT DISTINCT s.slug FROM bitcoin_observations o LEFT JOIN bitcoin_data_sources s ON s.id = o.source_id WHERE o.metric_id = :metric AND o.series_key = 'main'");
    $move = $pdo->prepare("UPDATE bitcoin_observations SET series_key = :archive WHERE metric_id = :metric AND series_key = 'main' AND source_id = (SELECT id FROM bitcoin_data_sources WHERE slug = :source)");
    $clearQuality = $pdo->prepare('DELETE FROM bitcoin_data_quality WHERE metric_id = :metric');
    $moved = 0;
    foreach (BITCOIN_CORE_METHODS as $metric => $method) {
        $metadata->execute(['metric' => $metric]);
        if ($metadata->fetchColumn() !== $method['source']) throw new RuntimeException('Zuerst Migration 045 für ' . $metric . ' ausführen.');
        $oldSources->execute(['metric' => $metric]);
        foreach ($oldSources->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            if (!in_array($slug, [$method['source'], $method['legacy_source']], true)) {
                throw new RuntimeException('Unbekannte bisherige Quelle für ' . $metric . '; keine automatische Umstellung.');
            }
        }
        $move->execute(['archive' => $method['archive'], 'metric' => $metric, 'source' => $method['legacy_source']]);
        $moved += $move->rowCount();
        $clearQuality->execute(['metric' => $metric]);
    }
    return $moved;
}

function bitcoin_core_guard(PDO $pdo, string $metric, string $series, string $source): void
{
    if ($series !== 'main' || !isset(BITCOIN_CORE_METHODS[$metric])) return;
    $query = $pdo->prepare('SELECT s.slug FROM bitcoin_metrics m JOIN bitcoin_data_sources s ON s.id = m.source_id WHERE m.metric_id = :metric');
    $query->execute(['metric' => $metric]);
    $canonical = BITCOIN_CORE_METHODS[$metric]['source'];
    if ($query->fetchColumn() === $canonical && $source !== $canonical) {
        throw new RuntimeException('Die neue Hauptreihe ' . $metric . ' akzeptiert ausschließlich ' . $canonical . '.');
    }
}
