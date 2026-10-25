<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bitcoin/bootstrap.php';
bitcoin_get_only();

try {
    $pdo = bitcoin_database();
    $sql = <<<'SQL'
        SELECT m.metric_id, m.category, m.display_name, m.unit, m.description,
               m.native_frequency, m.availability_status, m.coverage_start,
               m.coverage_end, m.observation_count, m.methodology_note,
               s.slug AS source_slug, s.display_name AS source_name,
               s.base_url AS source_url, s.attribution_text,
               o.value AS latest_value, o.observation_date AS latest_date,
               o.quality_status AS latest_quality,
               p.value AS previous_value, p.observation_date AS previous_date,
               c1y.value AS comparison_1y_value, c1y.observation_date AS comparison_1y_date,
               c3y.value AS comparison_3y_value, c3y.observation_date AS comparison_3y_date,
               c10y.value AS comparison_10y_value, c10y.observation_date AS comparison_10y_date,
               cmax.value AS comparison_max_value, cmax.observation_date AS comparison_max_date
        FROM bitcoin_metrics AS m
        LEFT JOIN bitcoin_data_sources AS s ON s.id = m.source_id
        LEFT JOIN bitcoin_observations AS o
          ON o.metric_id = m.metric_id AND o.series_key = 'main'
         AND o.observation_date = (
             SELECT MAX(i.observation_date)
             FROM bitcoin_observations AS i
             WHERE i.metric_id = m.metric_id AND i.series_key = 'main'
         )
        LEFT JOIN bitcoin_observations AS p
          ON p.metric_id = m.metric_id AND p.series_key = 'main'
         AND p.observation_date = (
             SELECT MAX(i.observation_date)
             FROM bitcoin_observations AS i
             WHERE i.metric_id = m.metric_id AND i.series_key = 'main'
               AND i.observation_date < o.observation_date
         )
        LEFT JOIN bitcoin_observations AS c1y
          ON c1y.metric_id = m.metric_id AND c1y.series_key = 'main'
         AND c1y.observation_date = (
             SELECT MIN(i.observation_date)
             FROM bitcoin_observations AS i
             WHERE i.metric_id = m.metric_id AND i.series_key = 'main'
               AND i.observation_date >= DATE_SUB(o.observation_date, INTERVAL 1 YEAR)
               AND i.observation_date <= o.observation_date
         )
        LEFT JOIN bitcoin_observations AS c3y
          ON c3y.metric_id = m.metric_id AND c3y.series_key = 'main'
         AND c3y.observation_date = (
             SELECT MIN(i.observation_date)
             FROM bitcoin_observations AS i
             WHERE i.metric_id = m.metric_id AND i.series_key = 'main'
               AND i.observation_date >= DATE_SUB(o.observation_date, INTERVAL 3 YEAR)
               AND i.observation_date <= o.observation_date
         )
        LEFT JOIN bitcoin_observations AS c10y
          ON c10y.metric_id = m.metric_id AND c10y.series_key = 'main'
         AND c10y.observation_date = (
             SELECT MIN(i.observation_date)
             FROM bitcoin_observations AS i
             WHERE i.metric_id = m.metric_id AND i.series_key = 'main'
               AND i.observation_date >= DATE_SUB(o.observation_date, INTERVAL 10 YEAR)
               AND i.observation_date <= o.observation_date
         )
        LEFT JOIN bitcoin_observations AS cmax
          ON cmax.metric_id = m.metric_id AND cmax.series_key = 'main'
         AND cmax.observation_date = (
             SELECT MIN(i.observation_date)
             FROM bitcoin_observations AS i
             WHERE i.metric_id = m.metric_id AND i.series_key = 'main'
               AND i.observation_date <= o.observation_date
         )
        ORDER BY m.display_order, m.display_name
        SQL;
    $rows = $pdo->query($sql)->fetchAll();
    $activeIds = bitcoin_active_metric_ids();
    if ($activeIds !== null) $rows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['metric_id'], $activeIds, true)));
    $sumMetricIds = ['hodl_waves', 'realized_cap_hodl_waves', 'mining_pool_share', 'mining_pool_hashrate_share_pct'];
    $sumQuery = $pdo->prepare(<<<'SQL'
        SELECT observation_date, SUM(value) AS combined_value
        FROM bitcoin_observations
        WHERE metric_id = :metric
        GROUP BY observation_date
        ORDER BY observation_date DESC
        LIMIT 2
        SQL);
    foreach ($rows as &$row) {
        if (!in_array($row['metric_id'], $sumMetricIds, true) || $row['latest_value'] !== null) {
            continue;
        }
        $sumQuery->execute(['metric' => $row['metric_id']]);
        $points = $sumQuery->fetchAll();
        if ($points === []) {
            continue;
        }
        $row['latest_value'] = $points[0]['combined_value'];
        $row['latest_date'] = $points[0]['observation_date'];
        $row['latest_quality'] = 'provisional';
        if (isset($points[1])) {
            $row['previous_value'] = $points[1]['combined_value'];
            $row['previous_date'] = $points[1]['observation_date'];
        }
    }
    unset($row);
    $mainCoverage = $pdo->prepare("SELECT COUNT(*) AS observation_count, MIN(observation_date) AS coverage_start, MAX(observation_date) AS coverage_end FROM bitcoin_observations WHERE metric_id = :metric AND series_key = 'main'");
    foreach ($rows as &$row) {
        if ($activeIds === null && !in_array($row['metric_id'], ['fear_greed_index', 'difficulty'], true)) continue;
        $mainCoverage->execute(['metric' => $row['metric_id']]);
        $coverage = $mainCoverage->fetch();
        $row['observation_count'] = $coverage['observation_count'];
        $row['coverage_start'] = $coverage['coverage_start'];
        $row['coverage_end'] = $coverage['coverage_end'];
    }
    unset($row);
    $metrics = array_map(static fn (array $row): array => [
        'id' => $row['metric_id'],
        'category' => $row['category'],
        'label' => $row['display_name'],
        'unit' => $row['unit'],
        'description' => $row['description'],
        'frequency' => $row['native_frequency'],
        'status' => $row['availability_status'],
        'coverageStart' => $row['coverage_start'],
        'coverageEnd' => $row['coverage_end'],
        'observationCount' => (int) $row['observation_count'],
        'methodology' => $row['methodology_note'],
        'sourceName' => $row['source_name'],
        'sourceSlug' => $row['source_slug'],
        'sourceUrl' => $row['source_url'],
        'attribution' => $row['attribution_text'],
        'latestValue' => $row['latest_value'],
        'latestDate' => $row['latest_date'],
        'latestQuality' => $row['latest_quality'],
        'previousValue' => $row['previous_value'],
        'previousDate' => $row['previous_date'],
        'comparisons' => [
            '1y' => $row['comparison_1y_date'] === null ? null : ['date' => $row['comparison_1y_date'], 'value' => $row['comparison_1y_value']],
            '3y' => $row['comparison_3y_date'] === null ? null : ['date' => $row['comparison_3y_date'], 'value' => $row['comparison_3y_value']],
            '10y' => $row['comparison_10y_date'] === null ? null : ['date' => $row['comparison_10y_date'], 'value' => $row['comparison_10y_value']],
            'max' => $row['comparison_max_date'] === null ? null : ['date' => $row['comparison_max_date'], 'value' => $row['comparison_max_value']],
        ],
    ], $rows);
    bitcoin_json(['success' => true, 'metrics' => $metrics, 'generatedAt' => gmdate(DATE_ATOM)], 200, 300);
} catch (Throwable $exception) {
    error_log('[bitcoin/metrics] ' . $exception->getMessage());
    bitcoin_error('DATABASE_UNAVAILABLE', 'Die gespeicherten Bitcoin-Daten konnten nicht geladen werden.', 503);
}
