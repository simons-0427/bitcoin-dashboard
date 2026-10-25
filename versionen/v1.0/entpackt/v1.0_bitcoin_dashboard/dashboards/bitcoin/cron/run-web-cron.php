<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/bitcoin/web-cron.php';

if ($argc !== 2 || !preg_match('/^[a-f0-9]{32}$/D', $argv[1])) { fwrite(STDERR, "Interner Worker-Aufruf ungültig.\n"); exit(1); }
$lock = null;
$state = [];
try {
    $config = bitcoin_web_config();
    $lock = bitcoin_web_lock('worker');
    if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
    $state = bitcoin_web_state();
    if (($state['run_id'] ?? '') !== $argv[1] || ($state['status'] ?? '') !== 'queued') exit(1);
    $state['status'] = 'running'; $state['started_at'] = gmdate('c');
    bitcoin_web_save($state);
    $log = bitcoin_web_directory() . '/run-' . $argv[1] . '.log';
    $handle = fopen($log, 'x');
    if ($handle === false) throw new RuntimeException('Worker-Protokoll existiert bereits.');
    fclose($handle); @chmod($log, 0600);
    $process = proc_open([$config['shell_bin'], bitcoin_web_root() . '/cron/run-daily.sh'], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $log, 'a'], 2 => ['file', $log, 'a'],
    ], $pipes, bitcoin_web_root(), bitcoin_web_environment($config), ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Tageslauf konnte nicht gestartet werden.');
    $state['exit_code'] = proc_close($process);
    $state['status'] = $state['exit_code'] === 0 ? 'succeeded' : 'failed';
    if ($state['exit_code'] !== 0) $state['failure'] = 'daily_run_failed';
    $state['finished_at'] = gmdate('c');
    bitcoin_web_save($state);
} catch (Throwable $error) {
    if (($state['run_id'] ?? '') === ($argv[1] ?? '')) {
        $state['status'] = 'failed'; $state['failure'] = 'worker_failed'; $state['finished_at'] = gmdate('c');
        try { bitcoin_web_save($state); } catch (Throwable) {}
    }
    fwrite(STDERR, "Bitcoin-Web-Worker fehlgeschlagen.\n");
    exit(1);
} finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
