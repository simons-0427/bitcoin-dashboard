<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bitcoin/core-series.php';
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
function rejects(callable $action, string $message): void
{
    $failed = false;
    try { $action(); } catch (Throwable) { $failed = true; }
    check($failed, $message);
}
function databaseFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE bitcoin_data_sources (id INTEGER PRIMARY KEY, slug TEXT UNIQUE)');
    $pdo->exec('CREATE TABLE bitcoin_metrics (metric_id TEXT PRIMARY KEY, source_id INTEGER)');
    $pdo->exec('CREATE TABLE bitcoin_metric_series (metric_id TEXT, series_key TEXT, PRIMARY KEY(metric_id,series_key))');
    $pdo->exec('CREATE TABLE bitcoin_observations (metric_id TEXT, series_key TEXT, source_id INTEGER, observation_date TEXT, value TEXT, raw_payload_hash TEXT, source_revision TEXT, PRIMARY KEY(metric_id,series_key,observation_date), FOREIGN KEY(metric_id,series_key) REFERENCES bitcoin_metric_series(metric_id,series_key))');
    $pdo->exec('CREATE TABLE bitcoin_data_quality (metric_id TEXT, series_key TEXT)');
    $pdo->exec("INSERT INTO bitcoin_data_sources VALUES (1,'alternative_me_fng'),(2,'open_bitcoin_metrics_obm'),(3,'openbitcoin_node_api'),(4,'derived_bitcoin_metrics'),(5,'unexpected_source')");
    $pdo->exec("INSERT INTO bitcoin_metrics VALUES ('fear_greed_index',1),('difficulty',2)");
    $pdo->exec("INSERT INTO bitcoin_metric_series VALUES ('fear_greed_index','main'),('fear_greed_index','openbitcoin_legacy'),('difficulty','main'),('difficulty','coinmetrics_daily_mean_v1')");
    $pdo->exec("INSERT INTO bitcoin_observations VALUES ('fear_greed_index','main',3,'2026-09-28','51','fear-hash','legacy-fear'),('difficulty','main',4,'2026-09-28','123456789.123456789012','difficulty-hash','legacy-difficulty')");
    $pdo->exec("INSERT INTO bitcoin_data_quality VALUES ('fear_greed_index','main'),('difficulty','main')");
    return $pdo;
}
function snapshot(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM bitcoin_observations ORDER BY metric_id,series_key,observation_date')->fetchAll(PDO::FETCH_ASSOC);
}

check(bitcoin_core_decimal('0.9999999999995') === '1', 'decimal carry is exact');
check(bitcoin_core_decimal('123456789123456789123456.1234567890124') === '123456789123456789123456.123456789012', 'large decimal is not converted through float');
rejects(fn () => bitcoin_core_decimal('999999999999999999999999.9999999999995'), 'decimal overflow rejected');
rejects(fn () => bitcoin_core_decimal('-1'), 'negative Difficulty rejected');

$alt = json_encode(['metadata' => ['error' => null], 'data' => [
    ['timestamp' => '1704240000', 'value' => '0'], ['timestamp' => '1704067200', 'value' => '100'],
]], JSON_THROW_ON_ERROR);
$converted = bitcoin_core_convert('fear_greed_index', $alt, '2024-01-01', '2024-01-03');
check(count($converted['rows']) === 2 && $converted['rows'][0][2] === '2024-01-01', 'reverse API order sorted by UTC day');
check($converted['summary']['missingDates'] === ['2024-01-02'], 'missing day preserved, zero is a valid index');
check(count(bitcoin_core_convert('fear_greed_index', $alt, '2024-01-01', '2024-01-01')['rows']) === 1, 'end cutoff excludes later days');
$duplicate = json_decode($alt, true); $duplicate['data'][] = $duplicate['data'][0];
rejects(fn () => bitcoin_core_convert('fear_greed_index', json_encode($duplicate), '2024-01-01', '2024-01-03'), 'duplicate UTC days rejected');
rejects(fn () => bitcoin_core_convert('fear_greed_index', str_replace('"100"', '"101"', $alt), '2024-01-01', '2024-01-03'), 'index above 100 rejected');
rejects(fn () => bitcoin_core_convert('fear_greed_index', '{"metadata":{"error":"blocked"},"data":[]}', '2024-01-01', '2024-01-03'), 'provider errors rejected');

$obm = "date,series_id,value,unit,frequency,release_version\r\n2009-01-03,obm_difficulty_eod_daily,1.000000000000,difficulty,daily,OBM v0.1.0\r\n2009-01-04,obm_difficulty_eod_daily,NaN,difficulty,daily,OBM v0.1.0\r\n";
$converted = bitcoin_core_convert('difficulty', $obm, '2009-01-03', '2009-01-04');
check($converted['rows'][0][3] === '1' && count($converted['rows']) === 1, 'OBM decimal parsed and NaN omitted');
check($converted['summary']['undefinedDates'] === ['2009-01-04'], 'undefined day recorded');
rejects(fn () => bitcoin_core_convert('difficulty', str_replace(',difficulty,', ',outputs,', $obm), '2009-01-03', '2009-01-04'), 'unit mismatch rejected');
rejects(fn () => bitcoin_core_convert('difficulty', str_replace('1.000000000000', '0', $obm), '2009-01-03', '2009-01-04'), 'zero Difficulty rejected');

$pdo = databaseFixture();
$before = snapshot($pdo);
rejects(fn () => bitcoin_core_promote($pdo), 'promotion requires transaction');
$pdo->beginTransaction();
check(bitcoin_core_promote($pdo) === 2, 'both old main series archived');
$archived = snapshot($pdo);
foreach ($archived as $index => $row) {
    $original = $before[$index];
    unset($row['series_key'], $original['series_key']);
    check($row === $original, 'all legacy values and provenance remain identical');
}
$pdo->rollBack();
check(snapshot($pdo) === $before, 'transaction failure restores original series');
$pdo->beginTransaction();
bitcoin_core_promote($pdo);
$pdo->exec("INSERT INTO bitcoin_observations VALUES ('fear_greed_index','main',1,'2026-09-28','52','new-fear','alternative-me-fng-v1'),('difficulty','main',2,'2026-09-28','123456789.125','new-difficulty','obm-eod')");
$pdo->commit();
$after = snapshot($pdo);
$pdo->beginTransaction();
check(bitcoin_core_promote($pdo) === 0, 'repeated promotion does not move canonical data');
$pdo->commit();
check(snapshot($pdo) === $after, 'promotion is idempotent');
rejects(fn () => bitcoin_core_guard($pdo, 'difficulty', 'main', 'derived_bitcoin_metrics'), 'legacy estimator cannot pollute OBM main');
rejects(fn () => bitcoin_core_guard($pdo, 'fear_greed_index', 'main', 'openbitcoin_node_api'), 'legacy Fear source cannot pollute Alternative.me main');
bitcoin_core_guard($pdo, 'difficulty', 'main', 'open_bitcoin_metrics_obm');
check(true, 'canonical source accepted');
$importRow = ['metric_id' => 'fear_greed_index', 'series_key' => 'main', 'source_slug' => 'alternative_me_fng',
    'observation_date' => '2024-01-01', 'value' => '101', 'source_revision' => 'alternative-me-fng-v1'];
rejects(fn () => bitcoin_core_validate_row($importRow), 'importer also rejects out-of-range index');
$importRow['value'] = '50'; $importRow['observation_date'] = gmdate('Y-m-d');
rejects(fn () => bitcoin_core_validate_row($importRow), 'importer rejects unfinished UTC day');

$pdo = databaseFixture();
$pdo->exec("UPDATE bitcoin_observations SET source_id=5 WHERE metric_id='difficulty'");
$before = snapshot($pdo);
$pdo->beginTransaction();
rejects(fn () => bitcoin_core_promote($pdo), 'unknown legacy source rejected');
$pdo->rollBack();
check(snapshot($pdo) === $before, 'unknown source cannot cause partial history move');
$pdo = databaseFixture();
$pdo->exec("INSERT INTO bitcoin_observations SELECT metric_id,'openbitcoin_legacy',source_id,observation_date,value,raw_payload_hash,source_revision FROM bitcoin_observations WHERE metric_id='fear_greed_index'");
$before = snapshot($pdo);
$pdo->beginTransaction();
rejects(fn () => bitcoin_core_promote($pdo), 'archive key collision rejected');
$pdo->rollBack();
check(snapshot($pdo) === $before, 'archive collision does not overwrite old data');

$pdo = databaseFixture();
$pdo->exec("INSERT INTO bitcoin_observations VALUES ('fear_greed_index','main',3,'2018-04-14','42','legacy-hole-hash','legacy-fear')");
$pdo->beginTransaction();
bitcoin_core_promote($pdo);
$pdo->exec("INSERT INTO bitcoin_observations VALUES ('fear_greed_index','main',1,'2026-09-28','52','new-fear','alternative-me-fng-v1')");
check((int) $pdo->query("SELECT COUNT(*) FROM bitcoin_observations WHERE metric_id='fear_greed_index' AND series_key='main' AND observation_date='2018-04-14'")->fetchColumn() === 0, 'legacy-only source gap stays empty in canonical main');
check($pdo->query("SELECT value FROM bitcoin_observations WHERE metric_id='fear_greed_index' AND series_key='openbitcoin_legacy' AND observation_date='2018-04-14'")->fetchColumn() === '42', 'legacy-only source gap value survives in archive');
$pdo->rollBack();

if (isset($argv[1])) {
    bitcoin_core_preflight($argv[1]);
    check(true, 'full two-series backfill passes promotion preflight');
}
echo "Core-series checks passed: {$checks}. SQLite covers archive transaction behavior; no production database was used.\n";
