<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/includes/bitcoin/bootstrap.php';
require_once dirname(__DIR__) . '/includes/bitcoin/core-series.php';
require_once dirname(__DIR__) . '/includes/bitcoin/core-revisions.php';

const BITCOIN_IMPORT_COLUMNS = [
    'metric_id', 'series_key', 'observation_date', 'value', 'source_slug',
    'source_timestamp', 'quality_status', 'source_revision', 'raw_payload_hash',
];

function bitcoin_import_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function bitcoin_import_decimal(string $value): bool
{
    if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value)) {
        return false;
    }
    $parts = explode('.', ltrim($value, '-'), 2);
    return strlen($parts[0]) <= 24 && strlen($parts[1] ?? '') <= 12;
}

function bitcoin_import_timestamp(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    try {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('Ungültiger UTC-Zeitstempel.');
        }
        return $date->format('Y-m-d H:i:s');
    } catch (ValueError) {
        throw new RuntimeException('Ungültiger UTC-Zeitstempel.');
    }
}

$arguments = array_slice($argv, 1);
$trigger = 'initial_backfill';
$promoteCoreSeries = false;
$preserveCoreRevisions = false;
$revisionArchive = null;
$archiveDefinitions = [];
while (isset($arguments[0]) && str_starts_with($arguments[0], '--')) {
    $option = array_shift($arguments);
    if ($option === '--promote-core-series') { $promoteCoreSeries = true; }
    elseif ($option === '--preserve-core-revisions') { $preserveCoreRevisions = true; }
    elseif (str_starts_with($option, '--trigger=')) { $trigger = substr($option, strlen('--trigger=')); }
    else { bitcoin_import_fail('Unbekannte Importoption: ' . $option); }
}
if (count($arguments) !== 1 || !in_array($trigger, ['initial_backfill', 'cron', 'manual', 'gap_repair'], true) || !is_file($arguments[0]) || !is_readable($arguments[0])) {
    bitcoin_import_fail('Aufruf: php import-observations.php [--trigger=cron] [--promote-core-series|--preserve-core-revisions] /pfad/zur/geprueften-datei.csv');
}

if ($promoteCoreSeries && $preserveCoreRevisions) bitcoin_import_fail('Die beiden Umstellungsmodi dürfen nicht kombiniert werden.');
if ($preserveCoreRevisions) {
    try { $revisionArchive = bitcoin_core_revision_preflight($arguments[0], bitcoin_active_metric_ids()); }
    catch (Throwable $exception) { bitcoin_import_fail('Kernaktualisierung abgebrochen: ' . $exception->getMessage()); }
}

if ($promoteCoreSeries) {
    try { bitcoin_core_preflight($arguments[0]); }
    catch (Throwable $exception) { bitcoin_import_fail('Umstellung abgebrochen: ' . $exception->getMessage()); }
}

$handle = fopen($arguments[0], 'rb');
if ($handle === false) {
    bitcoin_import_fail('Die CSV-Datei konnte nicht geöffnet werden.');
}
$header = fgetcsv($handle, 0, ',', '"', '');
if (!is_array($header)) {
    bitcoin_import_fail('Die CSV-Datei ist leer.');
}
$header[0] = ltrim($header[0], "\xEF\xBB\xBF");
if ($header !== BITCOIN_IMPORT_COLUMNS) {
    bitcoin_import_fail('Die CSV-Spalten entsprechen nicht dem dokumentierten Importformat.');
}

$pdo = null;
$runId = null;
$inserted = 0;
$unchanged = 0;
$revisionUpdated = 0;
$archived = 0;
$coreSourceGuards = [];
$line = 1;
$touchedMetrics = [];
$sourceIds = [];
$today = gmdate('Y-m-d');

try {
    $pdo = bitcoin_database();
    $pdo->beginTransaction();
    if ($promoteCoreSeries || $preserveCoreRevisions) $archived = bitcoin_core_promote($pdo);
    $startRun = $pdo->prepare("INSERT INTO bitcoin_sync_runs (trigger_type, started_at, status) VALUES (:trigger, UTC_TIMESTAMP(), 'running')");
    $startRun->execute(['trigger' => $trigger]);
    $runId = (int) $pdo->lastInsertId();
    $sourceQuery = $pdo->prepare('SELECT id, license_note, attribution_text, is_active FROM bitcoin_data_sources WHERE slug = :slug');
    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO bitcoin_observations
          (metric_id, series_key, source_id, observation_date, value, source_timestamp,
           fetched_at, quality_status, source_revision, raw_payload_hash)
        VALUES
          (:metric, :series, :source, :date, :value, :source_timestamp,
           UTC_TIMESTAMP(), :quality, :revision, :hash)
        SQL);
    $existing = $pdo->prepare(<<<'SQL'
        SELECT source_id, value, raw_payload_hash, source_revision FROM bitcoin_observations
        WHERE metric_id = :metric AND series_key = :series AND observation_date = :date
        SQL);
    $updateObmMeanRevision = $pdo->prepare(<<<'SQL'
        UPDATE bitcoin_observations
        SET value = :value, source_timestamp = :source_timestamp, fetched_at = UTC_TIMESTAMP(),
            quality_status = :quality, source_revision = :revision, raw_payload_hash = :hash
        WHERE metric_id = :metric AND series_key = :series AND source_id = :source
          AND observation_date = :date
        SQL);

    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $line++;
        if (count($cells) === 1 && $cells[0] === null) {
            continue;
        }
        if (count($cells) !== count(BITCOIN_IMPORT_COLUMNS)) {
            throw new RuntimeException("Zeile {$line}: falsche Spaltenzahl.");
        }
        $row = array_combine(BITCOIN_IMPORT_COLUMNS, $cells);
        if (!preg_match('/^[a-z0-9_]{1,96}$/', $row['metric_id']) || !preg_match('/^[a-z0-9_]{1,96}$/', $row['series_key'])) {
            throw new RuntimeException("Zeile {$line}: ungültige Kennzahl oder Teilreihe.");
        }
        if (bitcoin_date($row['observation_date']) === null || $row['observation_date'] > $today) {
            throw new RuntimeException("Zeile {$line}: ungültiges oder zukünftiges Datum.");
        }
        if (!bitcoin_import_decimal($row['value'])) {
            throw new RuntimeException("Zeile {$line}: ungültiger Dezimalwert für DECIMAL(36,12).");
        }
        if (!in_array($row['quality_status'], ['valid', 'provisional', 'revised', 'flagged'], true)) {
            throw new RuntimeException("Zeile {$line}: ungültiger Qualitätsstatus.");
        }
        if ($row['source_revision'] !== '' && strlen($row['source_revision']) > 128) {
            throw new RuntimeException("Zeile {$line}: Revisionskennung zu lang.");
        }
        $hash = $row['raw_payload_hash'] !== '' ? strtolower($row['raw_payload_hash']) : hash('sha256', implode("\x1f", $cells));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException("Zeile {$line}: ungültiger SHA-256-Hash.");
        }
        $timestamp = bitcoin_import_timestamp($row['source_timestamp']);
        $slug = $row['source_slug'];
        if (!preg_match('/^[a-z0-9_-]{1,96}$/', $slug)) {
            throw new RuntimeException("Zeile {$line}: ungültiger Quellen-Slug.");
        }
        if (!array_key_exists($slug, $sourceIds)) {
            $sourceQuery->execute(['slug' => $slug]);
            $source = $sourceQuery->fetch();
            if ($source === false || (int) $source['is_active'] !== 1 || trim((string) $source['license_note']) === '' || trim((string) $source['attribution_text']) === '') {
                throw new RuntimeException("Zeile {$line}: Quelle ist nicht freigegeben oder nicht vollständig dokumentiert.");
            }
            $sourceIds[$slug] = (int) $source['id'];
        }
        $guardKey = $row['metric_id'] . '/' . $row['series_key'] . '/' . $slug;
        bitcoin_core_validate_row($row);
        if (!isset($coreSourceGuards[$guardKey])) {
            bitcoin_core_guard($pdo, $row['metric_id'], $row['series_key'], $slug);
            $coreSourceGuards[$guardKey] = true;
        }
        $values = [
            'metric' => $row['metric_id'], 'series' => $row['series_key'],
            'source' => $sourceIds[$slug], 'date' => $row['observation_date'],
            'value' => $row['value'], 'source_timestamp' => $timestamp,
            'quality' => $row['quality_status'],
            'revision' => $row['source_revision'] ?: null, 'hash' => $hash,
        ];
        try {
            $insert->execute($values);
            $inserted++;
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing->execute(['metric' => $row['metric_id'], 'series' => $row['series_key'], 'date' => $row['observation_date']]);
            $previous = $existing->fetch();
            if ($previous !== false && bitcoin_import_same_observation($previous, $row, $sourceIds[$slug], $hash)) {
                $unchanged++;
            } elseif ($preserveCoreRevisions && $previous !== false) {
                bitcoin_core_archive_previous($pdo, $row['metric_id'], $row['observation_date'], $revisionArchive, $archiveDefinitions);
                $insert->execute($values);
                $revisionUpdated++;
                $archived++;
            } elseif ($previous !== false
                && $row['metric_id'] === 'mean_transaction_size_btc'
                && $row['series_key'] === 'main'
                && $slug === 'derived_bitcoin_metrics'
                && (string) $previous['source_revision'] === 'obm-derived-metrics-v1:obm-output-volume-divided-by-non-coinbase-transactions'
                && (string) $row['source_revision'] === 'obm-derived-metrics-v2:obm-output-volume-divided-by-coinmetrics-non-coinbase-tx-count'
                && (int) $previous['source_id'] === $sourceIds[$slug]) {
                $updateObmMeanRevision->execute($values);
                if ($updateObmMeanRevision->rowCount() !== 1) {
                    throw new RuntimeException("Zeile {$line}: die freigegebene OBM-Formelrevision konnte nicht aktualisiert werden.");
                }
                $revisionUpdated++;
            } else {
                throw new RuntimeException("Zeile {$line}: bestehender Wert oder Quelle weicht ab. Revision muss separat geprüft werden.");
            }
        }
        $touchedMetrics[$row['metric_id']] = $sourceIds[$slug];
    }

    if ($inserted + $unchanged + $revisionUpdated === 0) {
        throw new RuntimeException('Die CSV-Datei enthält keine Beobachtungen.');
    }
    $update = $pdo->prepare(<<<'SQL'
        UPDATE bitcoin_metrics AS m
        SET m.source_id = COALESCE(m.source_id, :source),
            m.observation_count = (SELECT COUNT(*) FROM bitcoin_observations AS o WHERE o.metric_id = m.metric_id),
            m.coverage_start = (SELECT MIN(o.observation_date) FROM bitcoin_observations AS o WHERE o.metric_id = m.metric_id),
            m.coverage_end = (SELECT MAX(o.observation_date) FROM bitcoin_observations AS o WHERE o.metric_id = m.metric_id),
            m.availability_status = CASE WHEN m.availability_status = 'planned' THEN 'partial' ELSE m.availability_status END
        WHERE m.metric_id = :metric
        SQL);
    foreach ($touchedMetrics as $metricId => $sourceId) {
        $update->execute(['source' => $sourceId, 'metric' => $metricId]);
    }
    $metadata = $pdo->prepare('SELECT native_frequency, expected_start, calendar_type, coverage_end FROM bitcoin_metrics WHERE metric_id = :metric');
    $seriesKeys = $pdo->prepare(<<<'SQL'
        SELECT DISTINCT o.series_key, s.native_frequency, s.expected_start, s.calendar_type
        FROM bitcoin_observations AS o
        JOIN bitcoin_metric_series AS s
          ON s.metric_id = o.metric_id AND s.series_key = o.series_key
        WHERE o.metric_id = :metric
        SQL);
    $summary = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*) AS observation_count, MIN(observation_date) AS coverage_start,
               MAX(observation_date) AS coverage_end,
               SUM(quality_status = 'flagged') AS flagged_count
        FROM bitcoin_observations WHERE metric_id = :metric AND series_key = :series
        SQL);
    $quality = $pdo->prepare(<<<'SQL'
        INSERT INTO bitcoin_data_quality
          (metric_id, series_key, expected_start, expected_end, observation_count,
           duplicate_count, missing_period_count, flagged_count, coverage_start,
           coverage_end, notes, checked_at)
        VALUES
          (:metric, :series, :expected_start, :expected_end, :count,
           0, :missing, :flagged, :coverage_start, :coverage_end, :notes, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
          expected_start = VALUES(expected_start), expected_end = VALUES(expected_end),
          observation_count = VALUES(observation_count), missing_period_count = VALUES(missing_period_count),
          flagged_count = VALUES(flagged_count), coverage_start = VALUES(coverage_start),
          coverage_end = VALUES(coverage_end), notes = VALUES(notes), checked_at = VALUES(checked_at)
        SQL);
    $sync = $pdo->prepare(<<<'SQL'
        INSERT INTO bitcoin_sync_state
          (metric_id, latest_observation_date, last_checked_at, last_successful_sync_at, consecutive_failures)
        VALUES (:metric, :latest, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
        ON DUPLICATE KEY UPDATE
          latest_observation_date = VALUES(latest_observation_date),
          last_checked_at = VALUES(last_checked_at),
          last_successful_sync_at = VALUES(last_successful_sync_at),
          consecutive_failures = 0, last_error_at = NULL, last_error_code = NULL, last_error_message = NULL
        SQL);
    $expectedEnd = (new DateTimeImmutable('yesterday', new DateTimeZone('UTC')))->format('Y-m-d');
    foreach (array_keys($touchedMetrics) as $metricId) {
        $metadata->execute(['metric' => $metricId]);
        $meta = $metadata->fetch();
        $seriesKeys->execute(['metric' => $metricId]);
        foreach ($seriesKeys->fetchAll() as $seriesRow) {
            $seriesKey = $seriesRow['series_key'];
            $summary->execute(['metric' => $metricId, 'series' => $seriesKey]);
            $stats = $summary->fetch();
            $expectedStart = $seriesRow['expected_start'] ?: ($meta['expected_start'] ?: $stats['coverage_start']);
            $calendarType = $seriesRow['calendar_type'] ?: $meta['calendar_type'];
            $seriesExpectedEnd = $stats['coverage_end'] && $stats['coverage_end'] > $expectedEnd
                ? $stats['coverage_end']
                : $expectedEnd;
            $missing = 0;
            $notes = ($seriesRow['native_frequency'] ?: $meta['native_frequency']) === 'event'
                ? 'Ereignisreihe; nur tatsächlich gemeldete Ereignisse, keine künstlichen Füllwerte.'
                : 'Native Frequenz; keine künstlichen Füllwerte.';
            if ($calendarType === 'calendar_daily' && $expectedStart !== null && $expectedStart <= $seriesExpectedEnd) {
                $days = (new DateTimeImmutable($expectedStart))->diff(new DateTimeImmutable($seriesExpectedEnd))->days + 1;
                $missing = max(0, $days - (int) $stats['observation_count']);
                $notes = 'UTC-Kalendertage; fehlende Tage werden nicht aufgefüllt.';
            }
            $quality->execute([
                'metric' => $metricId, 'series' => $seriesKey,
                'expected_start' => $expectedStart, 'expected_end' => $seriesExpectedEnd,
                'count' => (int) $stats['observation_count'], 'missing' => $missing,
                'flagged' => (int) $stats['flagged_count'],
                'coverage_start' => $stats['coverage_start'], 'coverage_end' => $stats['coverage_end'],
                'notes' => $notes,
            ]);
        }
        $sync->execute(['metric' => $metricId, 'latest' => $meta['coverage_end']]);
    }
    $finish = $pdo->prepare("UPDATE bitcoin_sync_runs SET completed_at = UTC_TIMESTAMP(), status = 'success', metrics_checked = :checked, metrics_succeeded = :succeeded, rows_inserted = :inserted WHERE id = :id");
    $finish->execute(['checked' => count($touchedMetrics), 'succeeded' => count($touchedMetrics), 'inserted' => $inserted, 'id' => $runId]);
    $pdo->commit();
    echo sprintf("Import abgeschlossen: %d neu, %d revidiert, %d unverändert, %d Kennzahlen, %d alte Beobachtungen als Archiv erhalten.\n", $inserted, $revisionUpdated, $unchanged, count($touchedMetrics), $archived);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bitcoin_import_fail('Import abgebrochen, keine Teilübernahme: ' . $exception->getMessage());
} finally {
    fclose($handle);
}
