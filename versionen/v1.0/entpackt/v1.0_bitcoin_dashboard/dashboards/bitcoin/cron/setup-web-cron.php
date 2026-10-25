<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/bitcoin/web-cron.php';

try {
    umask(0077);
    $arguments = array_slice($argv, 1);
    $binaries = ['php-bin' => PHP_BINARY, 'shell-bin' => '/bin/sh',
        'nohup-bin' => is_executable('/usr/bin/nohup') ? '/usr/bin/nohup' : '/bin/nohup'];
    $modes = [];
    foreach ($arguments as $argument) {
        if (preg_match('/^--(php-bin|shell-bin|nohup-bin)=(.+)$/D', $argument, $match)) $binaries[$match[1]] = $match[2];
        else $modes[] = $argument;
    }
    $arguments = $modes;
    $path = bitcoin_web_root() . '/config/bitcoin-web-cron.json';
    if (!is_file(bitcoin_web_root() . '/config/.htaccess') || !is_file(bitcoin_web_root() . '/var/.htaccess')) {
        throw new RuntimeException('Zuerst den vorhandenen HTTP-Schutz für config und var prüfen.');
    }
    if (!is_dir(bitcoin_web_directory()) && !mkdir(bitcoin_web_directory(), 0700)) throw new RuntimeException('Privater Web-Cron-Ordner konnte nicht erstellt werden.');
    if ($arguments === ['--retry']) {
        bitcoin_web_config();
        $lock = bitcoin_web_lock('launch');
        if (!flock($lock, LOCK_EX | LOCK_NB) || bitcoin_web_running()) throw new RuntimeException('Ein Lauf ist noch aktiv.');
        $state = bitcoin_web_state();
        if (in_array($state['status'] ?? '', ['queued','running'], true)) throw new RuntimeException('Ein Lauf wartet noch auf Abschluss.');
        $state['retry_requested'] = true;
        bitcoin_web_save($state);
        flock($lock, LOCK_UN); fclose($lock);
        echo "Ein erneuter Lauf ist für den nächsten POST freigegeben; der Token bleibt erhalten.\n";
        exit;
    }
    if ($arguments !== [] && $arguments !== ['--rotate']) throw new RuntimeException('Aufruf: php setup-web-cron.php [--rotate|--retry]');
    if (is_file($path) && $arguments !== ['--rotate']) throw new RuntimeException('Bereits eingerichtet. Für einen neuen Token ausdrücklich --rotate verwenden.');
    foreach ($binaries as $binary) {
        if (!is_file($binary) || !is_executable($binary)) throw new RuntimeException('Shell, nohup oder PHP-CLI nicht verfügbar.');
    }
    $token = bin2hex(random_bytes(32));
    $config = ['token_hash' => hash('sha256', $token), 'php_bin' => $binaries['php-bin'],
        'shell_bin' => $binaries['shell-bin'], 'nohup_bin' => $binaries['nohup-bin'], 'timezone' => 'Europe/Berlin', 'hour' => 15];
    $handle = fopen($path, $arguments === ['--rotate'] ? 'wb' : 'xb');
    if ($handle === false) throw new RuntimeException('Web-Cron-Konfiguration konnte nicht erstellt werden.');
    try {
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('Web-Cron-Konfiguration unvollständig.');
    } finally { fclose($handle); }
    @chmod($path, 0600);
    echo "URL: https://smstark-projekt.de/dashboards/bitcoin/api/bitcoin/cron.php\n";
    echo "Methode: POST\nHeader: X-Bitcoin-Cron-Token\nToken: {$token}\n";
    echo "Terminplan: alle 5 Minuten, Europe/Berlin. Erster Test sofort; danach einmal täglich ab 15 Uhr.\n";
    echo "Den Token nur in cron-job.org eintragen und nicht in den Chat kopieren.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
