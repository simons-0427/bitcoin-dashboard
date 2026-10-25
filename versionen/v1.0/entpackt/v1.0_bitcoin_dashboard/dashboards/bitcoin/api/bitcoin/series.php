<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bitcoin/bootstrap.php';
bitcoin_get_only();

$metricParam = $_GET['metric'] ?? '';
$metricId = is_string($metricParam) ? strtolower(trim($metricParam)) : '';
if (!preg_match('/^[a-z0-9_]{1,96}$/', $metricId)) {
    bitcoin_error('INVALID_METRIC', 'Die Kennzahl-ID ist ungültig.', 422);
}

try {
    $activeIds = bitcoin_active_metric_ids();
    if ($activeIds !== null && !in_array($metricId, $activeIds, true)) {
        bitcoin_error('METRIC_ARCHIVED', 'Diese Kennzahl gehört zum archivierten Datenbestand.', 404);
    }
    $pdo = bitcoin_database();
    $metricQuery = $pdo->prepare('SELECT metric_id, display_name, unit, native_frequency, coverage_start, coverage_end FROM bitcoin_metrics WHERE metric_id = :metric');
    $metricQuery->execute(['metric' => $metricId]);
    $metric = $metricQuery->fetch();
    if ($metric === false) {
        bitcoin_error('METRIC_NOT_FOUND', 'Die Kennzahl ist nicht im Katalog.', 404);
    }

    $startParam = $_GET['start'] ?? ($metric['coverage_start'] ?: '2009-01-03');
    $endParam = $_GET['end'] ?? ($metric['coverage_end'] ?: gmdate('Y-m-d'));
    $startText = is_string($startParam) ? trim($startParam) : '';
    $endText = is_string($endParam) ? trim($endParam) : '';
    $start = bitcoin_date($startText);
    $end = bitcoin_date($endText);
    if ($start === null || $end === null || $start > $end) {
        bitcoin_error('INVALID_RANGE', 'Start und Ende müssen gültige UTC-Daten im Format JJJJ-MM-TT sein.', 422);
    }
    if ($start->diff($end)->days > 8000) {
        bitcoin_error('RANGE_TOO_LARGE', 'Der angeforderte Zeitraum ist zu groß.', 422);
    }

    $seriesQuery = $pdo->prepare('SELECT series_key, display_name FROM bitcoin_metric_series WHERE metric_id = :metric ORDER BY display_order, series_key');
    $seriesQuery->execute(['metric' => $metricId]);
    $series = $seriesQuery->fetchAll();
    $mainOnly = $activeIds !== null || in_array($metricId, ['fear_greed_index', 'difficulty'], true);
    if ($mainOnly) $series = array_values(array_filter($series, static fn (array $row): bool => $row['series_key'] === 'main'));

    $pointLimit = $metricId === 'realized_cap_hodl_waves' ? 200000 : 50000;
    $sql = <<<'SQL'
        SELECT o.series_key, o.observation_date, o.value, o.quality_status,
               o.source_timestamp, s.slug AS source_slug,
               s.display_name AS source_name, s.base_url AS source_url,
               s.attribution_text
        FROM bitcoin_observations AS o
        LEFT JOIN bitcoin_data_sources AS s ON s.id = o.source_id
        WHERE o.metric_id = :metric AND o.observation_date BETWEEN :start AND :end
        SQL;
    if ($mainOnly) $sql .= " AND o.series_key = 'main'";
    $query = $pdo->prepare($sql . ' ORDER BY o.observation_date, o.series_key LIMIT ' . ($pointLimit + 1));
    $query->execute(['metric' => $metricId, 'start' => $startText, 'end' => $endText]);
    $rows = $query->fetchAll();
    if (count($rows) > $pointLimit) {
        bitcoin_error('TOO_MANY_POINTS', 'Bitte einen kürzeren Zeitraum wählen.', 422);
    }
    $points = array_map(static fn (array $row): array => [
        'series' => $row['series_key'],
        'date' => $row['observation_date'],
        'value' => $row['value'],
        'quality' => $row['quality_status'],
        'sourceTimestamp' => $row['source_timestamp'],
        'sourceName' => $row['source_name'],
        'sourceSlug' => $row['source_slug'],
        'sourceUrl' => $row['source_url'],
        'attribution' => $row['attribution_text'],
    ], $rows);
    bitcoin_json([
        'success' => true,
        'metric' => ['id' => $metric['metric_id'], 'label' => $metric['display_name'], 'unit' => $metric['unit'], 'frequency' => $metric['native_frequency']],
        'range' => ['start' => $startText, 'end' => $endText],
        'series' => $series,
        'points' => $points,
        'generatedAt' => gmdate(DATE_ATOM),
    ], 200, 300);
} catch (Throwable $exception) {
    error_log('[bitcoin/series] ' . $exception->getMessage());
    bitcoin_error('DATABASE_UNAVAILABLE', 'Die gespeicherte Bitcoin-Zeitreihe konnte nicht geladen werden.', 503);
}
