<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bitcoin/core-revisions.php';
require_once dirname(__DIR__) . '/includes/bitcoin/core-calculations.php';
require_once dirname(__DIR__) . '/includes/bitcoin/bootstrap.php';

$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
$apiRow = ['metric_id'=>'transfer_count', 'series_key'=>'main', 'observation_date'=>'2009-01-09',
    'value'=>'0', 'source_slug'=>'coinmetrics_community_btc', 'source_revision'=>'btc-api:2009-01-09'];
$csvPrevious = ['source_id'=>'7', 'value'=>'0.000000000000',
    'source_revision'=>'btc-csv:2009-01-09', 'raw_payload_hash'=>str_repeat('a',64)];
$apiHash = str_repeat('b',64);
verify(bitcoin_import_same_observation($csvPrevious,$apiRow,7,$apiHash),'reported CSV/API zero conflict is unchanged');
$changed = $apiRow; $changed['value']='1';
verify(!bitcoin_import_same_observation($csvPrevious,$changed,7,$apiHash),'changed numeric value remains a conflict');
verify(!bitcoin_import_same_observation($csvPrevious,$apiRow,8,$apiHash),'changed provider remains a conflict');
$sameChannel = $csvPrevious; $sameChannel['source_revision']='btc-api:2009-01-09';
verify(!bitcoin_import_same_observation($sameChannel,$apiRow,7,$apiHash),'changed hash within the same channel stays strict');
$changed = $apiRow; $changed['source_slug']='derived_bitcoin_metrics';
verify(!bitcoin_import_same_observation($csvPrevious,$changed,7,$apiHash),'derived sources do not receive the transport exception');
$changed = $apiRow; $changed['metric_id']='difficulty';
verify(!bitcoin_import_same_observation($csvPrevious,$changed,7,$apiHash),'different metric methods are excluded');
$changed = $apiRow; $changed['series_key']='archive';
verify(!bitcoin_import_same_observation($csvPrevious,$changed,7,$apiHash),'archive series do not receive the transport exception');
$changed = $apiRow; $changed['source_revision']='btc-api:2009-01-10';
verify(!bitcoin_import_same_observation($csvPrevious,$changed,7,$apiHash),'revision date must match observation date');
$changed = $csvPrevious; $changed['source_revision']='btc-csv-v2:2009-01-09';
verify(!bitcoin_import_same_observation($changed,$apiRow,7,$apiHash),'unknown method versions remain conflicts');
$precise = $csvPrevious; $precise['value']='123456789123456789.123456789011';
$changed = $apiRow; $changed['value']='123456789123456789.123456789012';
verify(!bitcoin_import_same_observation($precise,$changed,7,$apiHash),'last decimal differences in large values are not lost through float');
$changed['value']=$precise['value'];
verify(bitcoin_import_same_observation($precise,$changed,7,$apiHash),'identical large decimal is accepted exactly');
$decimal = $csvPrevious; $decimal['value']='1.200000000000';
$changed=$apiRow; $changed['value']='1.2';
verify(bitcoin_import_same_observation($decimal,$changed,7,$apiHash),'DECIMAL trailing zeros do not change the value');
$reverse = $csvPrevious; $reverse['source_revision']='btc-api:2009-01-09';
$changed=$apiRow; $changed['source_revision']='btc-csv:2009-01-09';
verify(bitcoin_import_same_observation($reverse,$changed,7,$apiHash),'reverse CSV/API overlap is idempotent');
$other = $apiRow; $other['source_slug']='cftc_cot_legacy';
verify(bitcoin_import_same_observation($csvPrevious,$other,7,$csvPrevious['raw_payload_hash']),'identical stored source/value/hash behavior is retained');
verify(!bitcoin_import_same_observation($csvPrevious,$apiRow,8,$csvPrevious['raw_payload_hash']),'matching hash cannot bypass changed provider');

foreach ([0=>210000, 1=>209999, 209999=>1, 210000=>210000, 839999=>1, 840000=>210000] as $height=>$expected) {
    verify(bitcoin_core_blocks_until_halving($height)===$expected, 'halving boundary ' . $height);
}
verify(bitcoin_core_calendar_years('2024-02-29',-3)==='2021-02-28', 'leap day uses last valid calendar day');
verify(bitcoin_core_calendar_years('2026-10-21',-3)==='2023-10-21', 'three exact calendar years');
if (isset($argv[1])) {
    $tag = bitcoin_core_revision_preflight($argv[1], bitcoin_active_metric_ids());
    verify((bool) preg_match('/^before_core_[a-f0-9]{12}$/D',$tag), 'full catalog preflight and stable archive name');
}
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE bitcoin_metrics(metric_id TEXT PRIMARY KEY,native_frequency TEXT,expected_start TEXT)');
$pdo->exec('CREATE TABLE bitcoin_metric_series(metric_id TEXT,series_key TEXT,display_name TEXT,display_order INTEGER,native_frequency TEXT,calendar_type TEXT,expected_start TEXT,PRIMARY KEY(metric_id,series_key))');
$pdo->exec('CREATE TABLE bitcoin_observations(metric_id TEXT,series_key TEXT,observation_date TEXT,value TEXT,source_id INTEGER,raw_payload_hash TEXT,source_revision TEXT,source_timestamp TEXT,fetched_at TEXT,PRIMARY KEY(metric_id,series_key,observation_date),FOREIGN KEY(metric_id,series_key) REFERENCES bitcoin_metric_series(metric_id,series_key))');
$pdo->exec("INSERT INTO bitcoin_metrics VALUES('blocks_until_halving','daily','2009-01-09')");
$pdo->exec("INSERT INTO bitcoin_metric_series VALUES('blocks_until_halving','main','Main',0,'daily','calendar_daily','2009-01-09')");
$pdo->exec("INSERT INTO bitcoin_observations VALUES('blocks_until_halving','main','2026-10-21','100001',1,'old-hash','old-method','2026-10-21T00:00:00Z','2026-10-22T00:00:00Z')");
$old=$pdo->query('SELECT * FROM bitcoin_observations')->fetch(PDO::FETCH_ASSOC);
$definitions=[];
$pdo->beginTransaction();
bitcoin_core_archive_previous($pdo,'blocks_until_halving','2026-10-21','before_core_012345abcdef',$definitions);
$archived=$pdo->query('SELECT * FROM bitcoin_observations')->fetch(PDO::FETCH_ASSOC);
verify($archived['series_key']==='before_core_012345abcdef','old row moved to archive');
unset($archived['series_key']);$original=$old;unset($original['series_key']);
verify($archived===$original,'old value, source, hash, method and source/fetch timestamps retained');
$pdo->exec("INSERT INTO bitcoin_observations VALUES('blocks_until_halving','main','2026-10-21','100000',1,'new-hash','v2','2026-10-21T00:00:00Z','2026-10-22T00:00:00Z')");
verify((int)$pdo->query('SELECT COUNT(*) FROM bitcoin_observations')->fetchColumn()===2,'new canonical value and old version coexist');
$pdo->rollBack();
verify($pdo->query('SELECT * FROM bitcoin_observations')->fetch(PDO::FETCH_ASSOC)===$old,'failure restores original main');
$definitions=[];$pdo->beginTransaction();
bitcoin_core_archive_previous($pdo,'blocks_until_halving','2026-10-21','before_core_012345abcdef',$definitions);
$pdo->exec("INSERT INTO bitcoin_observations VALUES('blocks_until_halving','main','2026-10-21','100000',1,'new-hash','v2',NULL,NULL)");
$pdo->commit();
$pdo->beginTransaction();$definitions=[];$rejected=false;
try { bitcoin_core_archive_previous($pdo,'blocks_until_halving','2026-10-21','before_core_012345abcdef',$definitions); }
catch(Throwable) { $rejected=true; }
$pdo->rollBack();
verify($rejected,'archive collision rejected');
verify((int)$pdo->query('SELECT COUNT(*) FROM bitcoin_observations')->fetchColumn()===2,'archive conflict does not lose either version');
echo "Core-revision checks passed: {$checks}. Isolated SQLite only.\n";
