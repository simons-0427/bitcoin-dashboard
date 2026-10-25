<?php
declare(strict_types=1);

function bitcoin_web_root(): string { return dirname(__DIR__, 2); }
function bitcoin_web_directory(): string { return bitcoin_web_root() . '/var/web-cron'; }
function bitcoin_web_config(): array
{
    $path = bitcoin_web_root() . '/config/bitcoin-web-cron.json';
    $config = json_decode((string) @file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($config['token_hash'] ?? ''))) {
        throw new RuntimeException('Web-Cron-Konfiguration fehlt oder ist ungültig.');
    }
    foreach (['php_bin', 'shell_bin', 'nohup_bin'] as $key) {
        if (!is_string($config[$key] ?? null) || str_contains($config[$key], "\0")
            || !preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $config[$key])) {
            throw new RuntimeException('Web-Cron-Laufzeit fehlt.');
        }
    }
    if (($config['timezone'] ?? '') !== 'Europe/Berlin' || ($config['hour'] ?? null) !== 15) {
        throw new RuntimeException('Ungültiger Web-Cron-Zeitplan.');
    }
    return $config;
}

function bitcoin_web_state(): array
{
    $path = bitcoin_web_directory() . '/state.json';
    if (!is_file($path)) return [];
    $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state)) throw new RuntimeException('Ungültiger Web-Cron-Status.');
    if (isset($state['run_id']) && (!is_string($state['run_id']) || !preg_match('/^[a-f0-9]{32}$/D', $state['run_id']))) {
        throw new RuntimeException('Ungültige Web-Cron-Laufkennung.');
    }
    return $state;
}

function bitcoin_web_save(array $state): void
{
    $directory = bitcoin_web_directory();
    $temporary = tempnam($directory, '.state-');
    if ($temporary === false) throw new RuntimeException('Statusdatei konnte nicht erstellt werden.');
    try {
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false
            || !rename($temporary, $directory . '/state.json')) {
            throw new RuntimeException('Statusdatei konnte nicht gespeichert werden.');
        }
        @chmod($directory . '/state.json', 0600);
    } finally { if (is_file($temporary)) unlink($temporary); }
}

function bitcoin_web_lock(string $name)
{
    $handle = fopen(bitcoin_web_directory() . '/' . $name . '.lock', 'c+');
    if ($handle === false) throw new RuntimeException('Web-Cron-Sperre ist nicht verfügbar.');
    return $handle;
}

function bitcoin_web_running(): bool
{
    $handle = bitcoin_web_lock('worker');
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) return true;
        flock($handle, LOCK_UN);
        return false;
    } finally { fclose($handle); }
}

function bitcoin_web_due(array $state, ?DateTimeImmutable $now = null): bool
{
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Berlin'));
    if (($state['retry_requested'] ?? false) === true || empty($state['run_id'])) return true;
    return ($state['attempt_day'] ?? '') !== $now->format('Y-m-d') && (int) $now->format('G') >= 15;
}

function bitcoin_web_environment(array $config): array
{
    $environment = [];
    foreach (['PATH','SystemRoot','WINDIR','TEMP','TMP','TMPDIR','LANG','LC_ALL'] as $key) {
        $value = getenv($key);
        if (is_string($value)) $environment[$key] = $value;
    }
    $environment['BITCOIN_PHP_BIN'] = $config['php_bin'];
    return $environment;
}

function bitcoin_web_shell_quote(string $value): string
{
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function bitcoin_web_launch(array $config, string $runId): void
{
    if (!function_exists('proc_open')) throw new RuntimeException('Prozessstart ist im Web-PHP nicht verfügbar.');
    $quote = 'bitcoin_web_shell_quote';
    $command = $quote($config['nohup_bin']) . ' ' . $quote($config['php_bin']) . ' '
        . $quote(bitcoin_web_root() . '/cron/run-web-cron.php') . ' ' . $quote($runId)
        . ' </dev/null >' . $quote(bitcoin_web_directory() . '/launcher.log') . ' 2>&1 &';
    $process = proc_open([$config['shell_bin'], '-c', $command], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', bitcoin_web_directory() . '/launcher.log', 'a'],
        2 => ['file', bitcoin_web_directory() . '/launcher.log', 'a'],
    ], $pipes, bitcoin_web_root(), bitcoin_web_environment($config), ['bypass_shell' => true]);
    if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Worker konnte nicht gestartet werden.');
}

function bitcoin_web_result(array $state, string $status, bool $success, int $http): array
{
    return ['http' => $http, 'body' => ['success' => $success, 'status' => $status,
        'run_id' => $state['run_id'] ?? null, 'started_at' => $state['started_at'] ?? null,
        'finished_at' => $state['finished_at'] ?? null, 'exit_code' => $state['exit_code'] ?? null]];
}

function bitcoin_web_handle(array $config, bool $start): array
{
    $lock = bitcoin_web_lock('launch');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return bitcoin_web_result([], 'busy', true, 202);
        $state = bitcoin_web_state();
        if (bitcoin_web_running()) {
            $tooLong = time() - (int) ($state['queued_timestamp'] ?? time()) > 900;
            return bitcoin_web_result($state, $tooLong ? 'running_too_long' : 'running', !$tooLong, $tooLong ? 500 : 202);
        }
        // The worker may have completed between the first read and the lock probe.
        $state = bitcoin_web_state();
        if (in_array($state['status'] ?? '', ['queued', 'running'], true)) {
            if (($state['status'] ?? '') === 'queued' && time() - (int) ($state['queued_timestamp'] ?? 0) < 120) {
                return bitcoin_web_result($state, 'queued', true, 202);
            }
            $state['status'] = 'failed';
            $state['failure'] = 'worker_not_running';
            $state['finished_at'] = gmdate('c');
            bitcoin_web_save($state);
        }
        if ($start && bitcoin_web_due($state)) {
            if (!function_exists('proc_open')) return bitcoin_web_result($state, 'process_start_unavailable', false, 503);
            $previous = $state;
            $state = ['run_id' => bin2hex(random_bytes(16)), 'status' => 'queued',
                'attempt_day' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
                'queued_timestamp' => time(), 'queued_at' => gmdate('c')];
            if (!empty($previous['run_id'])) {
                file_put_contents(bitcoin_web_directory() . '/history-' . $previous['run_id'] . '.json', json_encode($previous, JSON_THROW_ON_ERROR), LOCK_EX);
            }
            bitcoin_web_save($state);
            try { bitcoin_web_launch($config, $state['run_id']); }
            catch (Throwable) {
                $state['status'] = 'failed'; $state['failure'] = 'launch_failed'; $state['finished_at'] = gmdate('c');
                bitcoin_web_save($state);
                return bitcoin_web_result($state, 'launch_failed', false, 503);
            }
            return bitcoin_web_result($state, 'queued', true, 202);
        }
        if (($state['status'] ?? '') === 'failed') return bitcoin_web_result($state, $state['failure'] ?? 'failed', false, 500);
        if (($state['status'] ?? '') === 'succeeded') return bitcoin_web_result($state, 'succeeded', true, 200);
        return bitcoin_web_result($state, $start ? 'waiting' : 'not_started', $start, $start ? 200 : 503);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
