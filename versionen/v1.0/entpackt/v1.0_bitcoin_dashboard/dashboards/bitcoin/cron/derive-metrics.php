<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/includes/bitcoin/bootstrap.php';
require_once dirname(__DIR__) . '/includes/bitcoin/core-calculations.php';

const BITCOIN_DERIVATION_VERSION = 'coinmetrics-local-derivations-v3';
const BITCOIN_DERIVED_SOURCE = 'derived_bitcoin_metrics';

function bitcoin_derived_date(string $date, int $days): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
        ->modify(($days < 0 ? '' : '+') . $days . ' days')->format('Y-m-d');
}

function bitcoin_derived_value(float $number): ?string
{
    if (!is_finite($number)) {
        return null;
    }
    $value = rtrim(rtrim(number_format($number, 12, '.', ''), '0'), '.');
    if ($value === '' || $value === '-0') {
        $value = '0';
    }
    $whole = explode('.', ltrim($value, '-'), 2)[0];
    if (strlen($whole) > 24) {
        throw new RuntimeException('Abgeleiteter Wert überschreitet DECIMAL(36,12).');
    }
    return $value;
}

function bitcoin_derived_chain(string $previous, string ...$parts): string
{
    return hash('sha256', implode("\x1f", array_merge([$previous], $parts)));
}

function bitcoin_derived_window_hash(array $series, int $start, int $end): array
{
    $hashes = [];
    for ($index = $start; $index <= $end; $index++) {
        $hashes[] = $series[$index]['hash'];
    }
    return $hashes;
}

function bitcoin_derived_subsidy_satoshis(int $height): int
{
    $era = intdiv($height, 210000);
    return $era >= 63 ? 0 : intdiv(5000000000, 2 ** $era);
}

function bitcoin_derived_issuance_satoshis(int $firstHeight, int $lastHeight): int
{
    $total = 0;
    for ($start = $firstHeight; $start <= $lastHeight;) {
        $era = intdiv($start, 210000);
        $end = min($lastHeight, (($era + 1) * 210000) - 1);
        $total += ($end - $start + 1) * bitcoin_derived_subsidy_satoshis($start);
        $start = $end + 1;
    }
    return $total;
}

function bitcoin_derived_contiguous_starts(array $dates): array
{
    $starts = [];
    foreach ($dates as $index => $date) {
        $starts[$index] = $index === 0 || strtotime($date . ' UTC') - strtotime($dates[$index - 1] . ' UTC') !== 86400
            ? $index
            : $starts[$index - 1];
    }
    return $starts;
}

function bitcoin_derived_median(array $values): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 === 1
        ? (float) $values[$middle]
        : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

try {
    $trigger = 'cron';
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--trigger=(cron|manual)$/', $argument, $match)) {
            $trigger = $match[1];
            continue;
        }
        throw new RuntimeException('Aufruf: php derive-metrics.php [--trigger=cron|manual]');
    }

    $activeIds = bitcoin_active_metric_ids();
    $activeSet = $activeIds === null ? null : array_fill_keys($activeIds, true);
    $pdo = bitcoin_database();
    $inputIds = [
        'price_usd', 'fx_usd_per_eur', 'supply_btc', 'mvrv_ratio', 'fees_total_btc',
        'transactions_count', 'blocks_count', 'hashrate_eh_s', 'exchange_inflow_btc', 'exchange_outflow_btc',
        'coin_days_destroyed', 'transaction_volume_btc', 'block_weight_mwu', 'liveliness',
    ];
    $placeholders = implode(',', array_fill(0, count($inputIds), '?'));
    $inputQuery = $pdo->prepare("SELECT metric_id, observation_date, value, raw_payload_hash FROM bitcoin_observations WHERE metric_id IN ({$placeholders}) AND series_key = 'main' ORDER BY observation_date, metric_id");
    $inputQuery->execute($inputIds);
    $base = [];
    while (($row = $inputQuery->fetch(PDO::FETCH_ASSOC)) !== false) {
        $metric = (string) $row['metric_id'];
        $date = (string) $row['observation_date'];
        $hash = (string) ($row['raw_payload_hash'] ?: hash('sha256', $metric . "\x1f" . $date . "\x1f" . $row['value']));
        $base[$metric][$date] = ['value' => (float) $row['value'], 'hash' => $hash];
    }
    if (empty($base['price_usd']) || empty($base['supply_btc']) || empty($base['blocks_count'])) {
        throw new RuntimeException('Für Ableitungen fehlen Preis, Umlaufmenge oder Blockzählung in der Datenbank.');
    }

    $sourceQuery = $pdo->prepare('SELECT id, is_active, license_note, attribution_text FROM bitcoin_data_sources WHERE slug = :slug');
    $sourceQuery->execute(['slug' => BITCOIN_DERIVED_SOURCE]);
    $source = $sourceQuery->fetch();
    if ($source === false || (int) $source['is_active'] !== 1 || trim((string) $source['license_note']) === '' || trim((string) $source['attribution_text']) === '') {
        throw new RuntimeException('Die abgeleitete Quelle ist nicht aktiv oder nicht vollständig dokumentiert. SQL 004 fehlt möglicherweise.');
    }
    $sourceId = (int) $source['id'];
    $latestQuery = $pdo->prepare('SELECT metric_id, MAX(observation_date) AS latest_date FROM bitcoin_observations WHERE source_id = :source GROUP BY metric_id');
    $latestQuery->execute(['source' => $sourceId]);
    $latest = [];
    foreach ($latestQuery->fetchAll() as $row) {
        $latest[(string) $row['metric_id']] = (string) $row['latest_date'];
    }

    $outputRows = [];
    $derivedValues = [];
    $derivedKeys = [];
    $getBase = static fn (string $metric, string $date): ?array => $base[$metric][$date] ?? null;
    $getDerived = static function (string $metric, string $date) use (&$derivedValues): ?array {
        return $derivedValues[$metric][$date] ?? null;
    };
    $marketCapByDate = [];
    $emit = static function (string $metric, string $date, float $number, array $inputs, string $formula) use (&$outputRows, &$derivedKeys, &$derivedValues, $latest, $activeSet): ?array {
        $value = bitcoin_derived_value($number);
        if ($value === null) {
            return null;
        }
        $inputHash = hash('sha256', implode("\x1f", $inputs));
        $hash = hash('sha256', BITCOIN_DERIVATION_VERSION . "\x1f" . $formula . "\x1f" . $metric . "\x1f" . $date . "\x1f" . $inputHash . "\x1f" . $value);
        $item = ['value' => (float) $value, 'hash' => $hash];
        $derivedValues[$metric][$date] = $item;
        if (($activeSet === null || isset($activeSet[$metric])) && (!isset($latest[$metric]) || $date > $latest[$metric])) {
            $key = $metric . '/main/' . $date;
            if (isset($derivedKeys[$key])) {
                throw new RuntimeException('Doppelte Ableitung: ' . $key);
            }
            $derivedKeys[$key] = true;
            $outputRows[] = [$metric, $date, $value, 'derived-v3:' . $formula, $hash];
        }
        return $item;
    };
    $emitVersioned = static function (string $metric, string $date, float $number, array $inputs, string $formula, string $version) use (&$outputRows, &$derivedKeys, &$derivedValues, $latest, $activeSet): ?array {
        $value = bitcoin_derived_value($number);
        if ($value === null) {
            return null;
        }
        $key = $metric . '/main/' . $date;
        $inputHash = hash('sha256', implode("\x1f", $inputs));
        $hash = hash('sha256', $version . "\x1f" . $formula . "\x1f" . $key . "\x1f" . $inputHash . "\x1f" . $value);
        $item = ['value' => (float) $value, 'hash' => $hash];
        $derivedValues[$metric][$date] = $item;
        if (($activeSet === null || isset($activeSet[$metric])) && (!isset($latest[$metric]) || $date > $latest[$metric])) {
            if (isset($derivedKeys[$key])) {
                throw new RuntimeException('Doppelte Ableitung: ' . $key);
            }
            $derivedKeys[$key] = true;
            $outputRows[] = [$metric, $date, $value, $version . ':' . $formula, $hash];
        }
        return $item;
    };
    $emitObm = static fn (string $metric, string $date, float $number, array $inputs, string $formula): ?array
        => $emitVersioned($metric, $date, $number, $inputs, $formula, 'obm-derived-metrics-v1');
    $emitCointime = static fn (string $metric, string $date, float $number, array $inputs, string $formula): ?array
        => $emitVersioned($metric, $date, $number, $inputs, $formula, 'cointime-mvrv-v1');

    $cddDates = array_values(array_intersect(
        array_keys($base['coin_days_destroyed'] ?? []),
        array_keys($base['supply_btc'] ?? []),
    ));
    sort($cddDates, SORT_STRING);
    $adjustedCddSum = 0.0;
    $adjustedCddCount = 0;
    $adjustedCddHistoryHash = '';
    foreach ($cddDates as $date) {
        $cdd = $getBase('coin_days_destroyed', $date);
        $supply = $getBase('supply_btc', $date);
        if ($cdd === null || $supply === null || $supply['value'] <= 0) continue;
        $adjustedCdd = $cdd['value'] / $supply['value'];
        $adjustedCddSum += $adjustedCdd;
        $adjustedCddCount++;
        $adjustedCddHistoryHash = bitcoin_derived_chain($adjustedCddHistoryHash, $date, $cdd['hash'], $supply['hash']);
        $expandingMean = $adjustedCddSum / $adjustedCddCount;
        $emitObm(
            'binary_coin_days_destroyed',
            $date,
            $adjustedCdd > $expandingMean ? 1.0 : 0.0,
            [$adjustedCddHistoryHash],
            'obm-cdd-over-circulating-supply-above-expanding-mean-including-current',
        );
    }

    $priceMap = $base['price_usd'] ?? [];
    $priceDates = array_keys($priceMap);
    sort($priceDates, SORT_STRING);
    $priceRun = bitcoin_derived_contiguous_starts($priceDates);
    $prices = [];
    foreach ($priceDates as $date) {
        $prices[] = ['date' => $date] + $priceMap[$date];
    }
    $runningAth = 0.0;
    $athChain = '';
    $activeYear = '';
    $ytdReference = null;
    foreach ($prices as $index => $item) {
        $year = substr($item['date'], 0, 4);
        if ($year !== $activeYear) {
            $activeYear = $year;
            $ytdReference = $index > 0 ? $prices[$index - 1] : null;
        }
        $runningAth = max($runningAth, $item['value']);
        $athChain = bitcoin_derived_chain($athChain, $item['date'], $item['hash']);
        $emit('price_ath_usd', $item['date'], $runningAth, [$athChain], 'running-ath');
        $emit('drawdown_ath', $item['date'], ($item['value'] / $runningAth - 1) * 100, [$item['hash'], $athChain], 'distance-from-running-ath');
        $emit('sats_per_usd', $item['date'], 100000000 / $item['value'], [$item['hash']], 'satoshis-per-usd');
        foreach ([1 => 'return_1d', 7 => 'return_7d', 30 => 'return_30d', 90 => 'return_90d', 365 => 'return_1y'] as $days => $metric) {
            $prior = $getBase('price_usd', bitcoin_derived_date($item['date'], -$days));
            if ($prior !== null && $prior['value'] > 0) {
                $emit($metric, $item['date'], ($item['value'] / $prior['value'] - 1) * 100, [$item['hash'], $prior['hash']], 'return-' . $days . 'd');
            }
        }
        $priorThreeYears = $getBase('price_usd', bitcoin_core_calendar_years($item['date'], -3));
        if ($priorThreeYears !== null && $priorThreeYears['value'] > 0) {
            $emit('cagr_3y_pct', $item['date'], (($item['value'] / $priorThreeYears['value']) ** (1 / 3) - 1) * 100,
                [$item['hash'], $priorThreeYears['hash']], 'geometric-annualized-return-exact-three-calendar-years');
        }
        if ($ytdReference !== null && $ytdReference['value'] > 0) {
            $emit('return_ytd_pct', $item['date'], ($item['value'] / $ytdReference['value'] - 1) * 100, [$item['hash'], $ytdReference['hash']], 'year-to-date');
        }
        if ($index - $priceRun[$index] + 1 >= 200) {
            $start = $index - 199;
            $sum = 0.0;
            for ($i = $start; $i <= $index; $i++) $sum += $prices[$i]['value'];
            $sma = $emit('price_sma_200d_usd', $item['date'], $sum / 200, bitcoin_derived_window_hash($prices, $start, $index), 'sma-200-calendar-days');
            if ($sma !== null && $sma['value'] > 0) $emit('mayer_multiple', $item['date'], $item['value'] / $sma['value'], [$item['hash'], $sma['hash']], 'price-divided-by-200-day-sma');
        }
        foreach ([30 => 'volatility_30d', 90 => 'volatility_90d', 365 => 'realized_volatility_1y'] as $days => $metric) {
            if ($index - $priceRun[$index] < $days) continue;
            $start = $index - $days;
            $returns = [];
            for ($i = $start + 1; $i <= $index; $i++) $returns[] = log($prices[$i]['value'] / $prices[$i - 1]['value']);
            $mean = array_sum($returns) / count($returns);
            $variance = 0.0;
            foreach ($returns as $return) $variance += ($return - $mean) ** 2;
            $variance /= count($returns);
            $emit($metric, $item['date'], sqrt($variance) * sqrt(365) * 100, bitcoin_derived_window_hash($prices, $start, $index), 'annualized-log-return-volatility-' . $days . 'd');
        }
    }

    $weeklyCloses = array_values(array_filter($prices, static fn (array $item): bool => gmdate('w', strtotime($item['date'] . ' UTC')) === '0'));
    for ($index = 199; $index < count($weeklyCloses); $index++) {
        if (strtotime($weeklyCloses[$index]['date'] . ' UTC') - strtotime($weeklyCloses[$index - 1]['date'] . ' UTC') !== 7 * 86400) continue;
        $start = $index - 199;
        $window = array_slice($weeklyCloses, $start, 200);
        $valid = true;
        for ($i = 1; $i < count($window); $i++) {
            if (strtotime($window[$i]['date'] . ' UTC') - strtotime($window[$i - 1]['date'] . ' UTC') !== 7 * 86400) {
                $valid = false;
                break;
            }
        }
        if (!$valid) continue;
        $emit('price_sma_200w_usd', $weeklyCloses[$index]['date'], array_sum(array_column($window, 'value')) / 200, array_column($window, 'hash'), 'sma-200-weekly-sunday-closes');
    }

    $fxDates = array_keys($base['fx_usd_per_eur'] ?? []);
    sort($fxDates, SORT_STRING);
    $fxIndex = 0;
    foreach ($priceDates as $date) {
        while (isset($fxDates[$fxIndex + 1]) && $fxDates[$fxIndex + 1] <= $date) $fxIndex++;
        $fxDate = $fxDates[$fxIndex] ?? null;
        if ($fxDate === null || $fxDate > $date || strtotime($date . ' UTC') - strtotime($fxDate . ' UTC') > 7 * 86400) continue;
        $price = $getBase('price_usd', $date);
        $fx = $getBase('fx_usd_per_eur', $fxDate);
        if ($price !== null && $fx !== null && $fx['value'] > 0) {
            $emit('price_eur', $date, $price['value'] / $fx['value'], [$price['hash'], $fx['hash'], $fxDate], 'usd-price-divided-by-ecb-usd-per-eur');
        }
    }

    $blockDates = array_keys($base['blocks_count'] ?? []);
    sort($blockDates, SORT_STRING);
    $cumulativeHeight = 0;
    $heightAvailable = $blockDates !== [];
    $previousBlockDate = null;
    foreach ($blockDates as $date) {
        $blocks = $getBase('blocks_count', $date);
        if ($previousBlockDate !== null && strtotime($date . ' UTC') - strtotime($previousBlockDate . ' UTC') !== 86400) $heightAvailable = false;
        $previousBlockDate = $date;
        if ($blocks === null || $blocks['value'] < 0 || floor($blocks['value']) !== $blocks['value']) {
            $heightAvailable = false;
            continue;
        }
        if (!$heightAvailable) continue;
        $firstHeight = $cumulativeHeight + 1;
        $cumulativeHeight += (int) $blocks['value'];
        if ($blocks['value'] <= 0) continue;
        $previousHeight = $firstHeight === 1 ? 'coinmetrics-block-counts-from-genesis-offset-0' : ($getDerived('block_height', bitcoin_derived_date($date, -1))['hash'] ?? 'previous-height-unavailable');
        $height = $emit('block_height', $date, $cumulativeHeight, [$blocks['hash'], $previousHeight], 'cumulative-coinmetrics-block-count-from-genesis-offset-0');
        $issuance = bitcoin_derived_issuance_satoshis($firstHeight, $cumulativeHeight) / 100000000;
        $emit('issued_btc_daily', $date, $issuance, [$blocks['hash'], $height['hash']], 'bitcoin-consensus-subsidy-sum-by-block-height');
        $emit('block_reward_btc', $date, bitcoin_derived_subsidy_satoshis($cumulativeHeight) / 100000000, [$height['hash']], 'bitcoin-consensus-subsidy-at-daily-final-height');
        $emit('blocks_until_halving', $date, bitcoin_core_blocks_until_halving($cumulativeHeight), [$height['hash']], 'bitcoin-protocol-blocks-until-next-halving-v2');
        // Difficulty main is supplied by OBM; the former mean estimate remains archived.
    }

    $marketCapSeries = [];
    $capDates = array_unique(array_merge(array_keys($base['price_usd'] ?? []), array_keys($base['supply_btc'] ?? [])));
    sort($capDates, SORT_STRING);
    foreach ($capDates as $date) {
        $price = $getBase('price_usd', $date);
        $supply = $getBase('supply_btc', $date);
        if ($price === null || $supply === null) continue;
        $cap = $emit('market_cap_usd', $date, $price['value'] * $supply['value'], [$price['hash'], $supply['hash']], 'price-times-circulating-supply');
        if ($cap !== null) {
            $marketCapSeries[] = ['date' => $date] + $cap;
            $marketCapByDate[$date] = $cap;
        }
    }

    $realizedByDate = [];
    $capCount = 0;
    $meanCap = 0.0;
    $m2Cap = 0.0;
    $cumulativeCapHash = '';
    foreach ($marketCapSeries as $cap) {
        $capCount++;
        $delta = $cap['value'] - $meanCap;
        $meanCap += $delta / $capCount;
        $m2Cap += $delta * ($cap['value'] - $meanCap);
        $cumulativeCapHash = bitcoin_derived_chain($cumulativeCapHash, $cap['date'], $cap['hash']);
        $std = $capCount > 1 ? sqrt($m2Cap / $capCount) : 0.0;
        $mvrv = $getBase('mvrv_ratio', $cap['date']);
        if ($mvrv === null || $mvrv['value'] <= 0) continue;
        $realized = $emit('realized_cap_usd', $cap['date'], $cap['value'] / $mvrv['value'], [$cap['hash'], $mvrv['hash']], 'market-cap-divided-by-mvrv');
        if ($realized === null) continue;
        $realizedByDate[$cap['date']] = $realized;
        $supply = $getBase('supply_btc', $cap['date']);
        if ($supply !== null && $supply['value'] > 0) $emit('realized_price_usd', $cap['date'], $realized['value'] / $supply['value'], [$realized['hash'], $supply['hash']], 'realized-cap-divided-by-supply');
        $emit('nupl_pct', $cap['date'], ($cap['value'] - $realized['value']) / $cap['value'] * 100, [$cap['hash'], $realized['hash']], 'net-unrealized-profit-loss');
        if ($std > 0) $emit('mvrv_zscore', $cap['date'], ($cap['value'] - $realized['value']) / $std, [$cumulativeCapHash, $realized['hash']], 'cumulative-population-standard-deviation-of-market-cap');
        $emit('delta_cap_usd', $cap['date'], $realized['value'] - $meanCap, [$cumulativeCapHash, $realized['hash']], 'realized-cap-minus-cumulative-average-cap');
    }

    $allDates = array_unique(array_merge(
        array_keys($base['price_usd'] ?? []), array_keys($base['supply_btc'] ?? []),
        array_keys($base['fees_total_btc'] ?? []),
        array_keys($base['blocks_count'] ?? []), array_keys($base['transactions_count'] ?? []),
        array_keys($base['hashrate_eh_s'] ?? [])
    ));
    sort($allDates, SORT_STRING);
    $thermocap = 0.0;
    $thermocapChain = '';
    $thermocapValid = true;
    $thermocapStarted = false;
    $dailyIssuanceUsd = [];
    foreach ($allDates as $date) {
        $price = $getBase('price_usd', $date);
        $issuance = $getDerived('issued_btc_daily', $date);
        $fees = $getBase('fees_total_btc', $date);
        $supply = $getBase('supply_btc', $date);
        if ($issuance !== null && $supply !== null && $supply['value'] > 0) {
            $emit('inflation_rate_pct', $date, $issuance['value'] / $supply['value'] * 365 * 100, [$issuance['hash'], $supply['hash']], 'annualized-daily-issuance-over-circulating-supply');
        }
        if ($issuance !== null && $fees !== null) {
            $minerRevenueBtc = $emit('miner_revenue_btc', $date, $issuance['value'] + $fees['value'], [$issuance['hash'], $fees['hash']], 'issuance-plus-transaction-fees');
            if ($minerRevenueBtc !== null) $emit('fee_share_miner_revenue_pct', $date, $minerRevenueBtc['value'] > 0 ? $fees['value'] / $minerRevenueBtc['value'] * 100 : 0, [$fees['hash'], $issuance['hash']], 'fees-divided-by-total-miner-revenue');
            $transactions = $getBase('transactions_count', $date);
            if ($price !== null && $minerRevenueBtc !== null) {
                $feeUsd = $emit('fees_total_usd', $date, $fees['value'] * $price['value'], [$fees['hash'], $price['hash']], 'btc-fees-times-daily-usd-price');
                $emit('miner_fee_revenue_usd', $date, $fees['value'] * $price['value'], [$fees['hash'], $price['hash']], 'btc-fees-times-daily-usd-price');
                $minerRevenueUsd = $emit('miner_revenue_usd', $date, $minerRevenueBtc['value'] * $price['value'], [$minerRevenueBtc['hash'], $price['hash']], 'btc-miner-revenue-times-daily-usd-price');
                if ($feeUsd !== null && $transactions !== null && $transactions['value'] > 0) $emit('fees_average_usd', $date, $feeUsd['value'] / $transactions['value'], [$feeUsd['hash'], $transactions['hash']], 'daily-usd-fees-divided-by-transactions');
                $hashrate = $getBase('hashrate_eh_s', $date);
                if ($hashrate !== null && $hashrate['value'] > 0 && $minerRevenueUsd !== null) $emit('hashprice_usd_ph_day', $date, $minerRevenueUsd['value'] / ($hashrate['value'] * 1000), [$minerRevenueUsd['hash'], $hashrate['hash']], 'usd-revenue-per-ph-s-per-day');
            }
        }
        $blocks = $getBase('blocks_count', $date);
        if ($blocks !== null && $blocks['value'] > 0) $emit('block_interval_min', $date, 1440 / $blocks['value'], [$blocks['hash']], 'utc-day-minutes-divided-by-block-count-estimate');
        if ($price !== null && $issuance !== null) {
            $issuanceUsd = $issuance['value'] * $price['value'];
            $issuanceHash = hash('sha256', $issuance['hash'] . "\x1f" . $price['hash']);
            $dailyIssuanceUsd[] = ['date' => $date, 'value' => $issuanceUsd, 'hash' => $issuanceHash];
            if (!$thermocapStarted) $thermocapStarted = true;
            if ($thermocapValid) {
                $thermocap += $issuanceUsd;
                $thermocapChain = bitcoin_derived_chain($thermocapChain, $date, $issuance['hash'], $price['hash']);
                $thermo = $emit('thermocap_usd', $date, $thermocap, [$thermocapChain], 'coinbase-issuance-times-daily-usd-price-since-first-price');
                $cap = $marketCapByDate[$date] ?? null;
                $realized = $realizedByDate[$date] ?? null;
                if ($cap !== null && $thermo !== null && $thermo['value'] > 0) $emit('market_cap_thermocap_ratio', $date, $cap['value'] / $thermo['value'], [$cap['hash'], $thermo['hash']], 'market-cap-divided-by-thermocap');
                if ($realized !== null && $thermo !== null) $emit('investor_cap_usd', $date, $realized['value'] - $thermo['value'], [$realized['hash'], $thermo['hash']], 'realized-cap-minus-thermocap');
            }
        } elseif ($thermocapStarted) {
            $thermocapValid = false;
        }
    }

    $issuanceDates = array_column($dailyIssuanceUsd, 'date');
    $issuanceRun = bitcoin_derived_contiguous_starts($issuanceDates);
    foreach ($dailyIssuanceUsd as $index => $row) {
        if ($index - $issuanceRun[$index] + 1 < 365) continue;
        $start = $index - 364;
        $window = array_slice($dailyIssuanceUsd, $start, 365);
        $sum = 0.0;
        $hashes = [];
        foreach ($window as $point) {
            $sum += $point['value'];
            $hashes[] = $point['hash'];
        }
        $average = $sum / 365;
        if ($average > 0) $emit('puell_multiple', $row['date'], $row['value'] / $average, $hashes, 'daily-issuance-usd-over-365-day-average');
    }

    $transactionDates = array_keys($base['transactions_count'] ?? []);
    sort($transactionDates, SORT_STRING);
    foreach ($transactionDates as $date) {
        $transactions = $getBase('transactions_count', $date);
        $blocks = $getBase('blocks_count', $date);
        if ($transactions !== null && $blocks !== null && $blocks['value'] > 0) $emit('transactions_per_block', $date, $transactions['value'] / $blocks['value'], [$transactions['hash'], $blocks['hash']], 'daily-transactions-divided-by-daily-blocks');
    }

    $blockDates = array_keys($base['blocks_count'] ?? []);
    sort($blockDates, SORT_STRING);
    for ($index = 6; $index < count($blockDates); $index++) {
        if (strtotime($blockDates[$index] . ' UTC') - strtotime($blockDates[$index - 6] . ' UTC') !== 6 * 86400) continue;
        $window = [];
        $total = 0.0;
        for ($i = $index - 6; $i <= $index; $i++) {
            $point = $getBase('blocks_count', $blockDates[$i]);
            if ($point === null) {
                $window = [];
                break;
            }
            $window[] = $point;
            $total += $point['value'];
        }
        if ($window !== [] && $total > 0) $emit('block_interval_7d_min', $blockDates[$index], 10080 / $total, array_column($window, 'hash'), 'seven-day-minutes-divided-by-block-count-estimate');
    }

    $inflowMap = $base['exchange_inflow_btc'] ?? [];
    $outflowMap = $base['exchange_outflow_btc'] ?? [];
    $flowDates = array_intersect(array_keys($inflowMap), array_keys($outflowMap));
    sort($flowDates, SORT_STRING);
    foreach ($flowDates as $date) {
        $inflow = $getBase('exchange_inflow_btc', $date);
        $outflow = $getBase('exchange_outflow_btc', $date);
        $emit('exchange_netflow_btc', $date, $inflow['value'] - $outflow['value'], [$inflow['hash'], $outflow['hash']], 'exchange-inflow-minus-outflow');
    }

    $obmVolumeDates = array_keys($base['transaction_volume_btc'] ?? []);
    sort($obmVolumeDates, SORT_STRING);
    $usdVolumeByDate = [];
    $nvtRatioByDate = [];
    foreach ($obmVolumeDates as $date) {
        $volume = $getBase('transaction_volume_btc', $date);
        $price = $getBase('price_usd', $date);
        $transactions = $getBase('transactions_count', $date);
        if ($volume === null) continue;

        if ($price !== null && $price['value'] > 0) {
            $usdVolume = $emitObm(
                'transaction_volume_usd',
                $date,
                $volume['value'] * $price['value'],
                [$volume['hash'], $price['hash']],
                'obm-output-volume-times-coinmetrics-usd-close',
            );
            if ($usdVolume !== null) {
                $usdVolumeByDate[$date] = $usdVolume;
                $marketCap = $marketCapByDate[$date] ?? null;
                if ($marketCap !== null && $usdVolume['value'] > 0) {
                    $nvtRatioByDate[$date] = $emitObm(
                        'nvt_ratio',
                        $date,
                        $marketCap['value'] / $usdVolume['value'],
                        [$marketCap['hash'], $usdVolume['hash']],
                        'market-cap-over-obm-gross-output-volume-usd',
                    );
                }
            }
        }

        if ($transactions !== null && $transactions['value'] > 0) {
            $emitVersioned(
                'mean_transaction_size_btc',
                $date,
                $volume['value'] / $transactions['value'],
                [$volume['hash'], $transactions['hash']],
                'obm-output-volume-divided-by-coinmetrics-non-coinbase-tx-count',
                'obm-derived-metrics-v2',
            );
        }
    }

    $valuationDates = array_keys($usdVolumeByDate);
    sort($valuationDates, SORT_STRING);
    for ($index = 89; $index < count($valuationDates); $index++) {
        if (strtotime($valuationDates[$index] . ' UTC') - strtotime($valuationDates[$index - 89] . ' UTC') !== 89 * 86400) continue;
        $date = $valuationDates[$index];
        $window = array_slice($valuationDates, $index - 89, 90);
        $volumeRows = array_map(static fn (string $windowDate): array => $usdVolumeByDate[$windowDate], $window);
        $meanVolume = array_sum(array_column($volumeRows, 'value')) / 90;
        $marketCap = $marketCapByDate[$date] ?? null;
        if ($marketCap !== null && $meanVolume > 0) {
            $emitObm(
                'nvt_signal',
                $date,
                $marketCap['value'] / $meanVolume,
                array_merge([$marketCap['hash']], array_column($volumeRows, 'hash')),
                'market-cap-over-90d-sma-obm-gross-output-volume-usd',
            );
        }
    }

    for ($index = 729; $index < count($valuationDates); $index++) {
        if (strtotime($valuationDates[$index] . ' UTC') - strtotime($valuationDates[$index - 729] . ' UTC') !== 729 * 86400) continue;
        $date = $valuationDates[$index];
        $nvtWindow = [];
        for ($nvtIndex = $index - 729; $nvtIndex <= $index; $nvtIndex++) {
            $ratio = $nvtRatioByDate[$valuationDates[$nvtIndex]] ?? null;
            if ($ratio !== null) $nvtWindow[] = $ratio;
        }
        if (count($nvtWindow) < (int) ceil(730 * 0.9)) continue;

        $volumeWindowDates = array_slice($valuationDates, $index - 89, 90);
        $volumeWindow = array_map(static fn (string $windowDate): array => $usdVolumeByDate[$windowDate], $volumeWindowDates);
        $medianRatio = bitcoin_derived_median(array_column($nvtWindow, 'value'));
        $medianVolume = bitcoin_derived_median(array_column($volumeWindow, 'value'));
        $supply = $getBase('supply_btc', $date);
        if ($supply === null || $supply['value'] <= 0 || $medianRatio === null || $medianVolume === null || $medianVolume <= 0) continue;

        $nvtPrice = $emitObm(
            'nvt_price_usd',
            $date,
            $medianRatio * $medianVolume / $supply['value'],
            array_merge(array_column($nvtWindow, 'hash'), array_column($volumeWindow, 'hash'), [$supply['hash']]),
            '2y-median-nvt-ratio-times-90d-median-obm-gross-output-volume-over-supply',
        );
        $price = $getBase('price_usd', $date);
        if ($price !== null && $nvtPrice !== null && $nvtPrice['value'] > 0) {
            $emitObm(
                'nvt_premium_pct',
                $date,
                ($price['value'] / $nvtPrice['value'] - 1) * 100,
                [$price['hash'], $nvtPrice['hash']],
                'price-over-slow-90d-nvt-price-minus-one',
            );
        }
    }

    $rvtDaily = [];
    foreach ($valuationDates as $date) {
        $volume = $usdVolumeByDate[$date];
        $realizedCap = $getDerived('realized_cap_usd', $date);
        $rvtDaily[] = $volume['value'] > 0 && $realizedCap !== null
            ? ['date' => $date, 'value' => $realizedCap['value'] / $volume['value'], 'inputHashes' => [$realizedCap['hash'], $volume['hash']]]
            : null;
    }
    for ($index = 27; $index < count($valuationDates); $index++) {
        if (strtotime($valuationDates[$index] . ' UTC') - strtotime($valuationDates[$index - 27] . ' UTC') !== 27 * 86400) continue;
        $window = array_slice($rvtDaily, $index - 27, 28);
        if (in_array(null, $window, true)) continue;
        $average = array_sum(array_column($window, 'value')) / 28;
        $hashes = [];
        foreach ($window as $point) array_push($hashes, ...$point['inputHashes']);
        $emitObm(
            'rvt_ratio',
            $valuationDates[$index],
            $average,
            $hashes,
            '28d-sma-realized-cap-over-obm-gross-output-volume-usd',
        );
    }

    $weightDates = array_keys($base['block_weight_mwu'] ?? []);
    sort($weightDates, SORT_STRING);
    foreach ($weightDates as $date) {
        $weight = $getBase('block_weight_mwu', $date);
        $blocks = $getBase('blocks_count', $date);
        if ($weight === null || $blocks === null || $blocks['value'] <= 0) continue;
        $utilization = $weight['value'] / ($blocks['value'] * 4) * 100;
        if ($utilization > 100.0000001) {
            throw new RuntimeException($date . ': Blockauslastung über 100 Prozent; OBM- und Coin-Metrics-Tagesgrenzen prüfen.');
        }
        $emitObm(
            'blockspace_utilization_pct',
            $date,
            $utilization,
            [$weight['hash'], $blocks['hash'], 'bitcoin-consensus-max-block-weight-4000000-wu'],
            'obm-daily-block-weight-over-coinmetrics-daily-consensus-capacity',
        );
    }

    foreach ($priceDates as $date) {
        $price = $getBase('price_usd', $date);
        $realizedPrice = $getDerived('realized_price_usd', $date);
        $liveliness = $getBase('liveliness', $date);
        $mvrv = $getBase('mvrv_ratio', $date);
        if ($price === null || $realizedPrice === null || $liveliness === null || $mvrv === null) continue;
        if ($price['value'] <= 0 || $realizedPrice['value'] <= 0 || $liveliness['value'] <= 0 || $liveliness['value'] >= 1) continue;

        $baseMvrv = $price['value'] / $realizedPrice['value'];
        if (abs($baseMvrv - $mvrv['value']) > 0.000001) {
            throw new RuntimeException($date . ': Preis geteilt durch Realized Price weicht unerwartet vom gespeicherten MVRV ab.');
        }
        $inputs = [$price['hash'], $realizedPrice['hash'], $liveliness['hash'], $mvrv['hash']];
        $emitCointime(
            'active_mvrv_ratio',
            $date,
            $price['value'] / ($realizedPrice['value'] / $liveliness['value']),
            $inputs,
            'spot-price-over-realized-price-divided-by-liveliness',
        );
        $emitCointime(
            'vaulted_mvrv_ratio',
            $date,
            $price['value'] / ($realizedPrice['value'] / (1 - $liveliness['value'])),
            $inputs,
            'spot-price-over-realized-price-divided-by-one-minus-liveliness',
        );
    }

    $pdo->beginTransaction();
    $run = $pdo->prepare("INSERT INTO bitcoin_sync_runs (trigger_type, started_at, status) VALUES (:trigger, UTC_TIMESTAMP(), 'running')");
    $run->execute(['trigger' => $trigger]);
    $runId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO bitcoin_observations (metric_id, series_key, source_id, observation_date, value, source_timestamp, fetched_at, quality_status, source_revision, raw_payload_hash) VALUES (:metric, \'main\', :source, :date, :value, NULL, UTC_TIMESTAMP(), \'provisional\', :revision, :hash)');
    $touched = [];
    foreach ($outputRows as [$metric, $date, $value, $revision, $hash]) {
        $insert->execute(['metric' => $metric, 'source' => $sourceId, 'date' => $date, 'value' => $value, 'revision' => $revision, 'hash' => $hash]);
        $touched[$metric] = true;
    }

    $metricUpdate = $pdo->prepare(<<<'SQL'
        UPDATE bitcoin_metrics AS m
        SET m.source_id = :source,
            m.observation_count = (SELECT COUNT(*) FROM bitcoin_observations AS o WHERE o.metric_id = m.metric_id),
            m.coverage_start = (SELECT MIN(o.observation_date) FROM bitcoin_observations AS o WHERE o.metric_id = m.metric_id),
            m.coverage_end = (SELECT MAX(o.observation_date) FROM bitcoin_observations AS o WHERE o.metric_id = m.metric_id),
            m.availability_status = CASE WHEN m.observation_count > 0 THEN 'partial' ELSE m.availability_status END
        WHERE m.metric_id = :metric
        SQL);
    $metadata = $pdo->prepare('SELECT expected_start, calendar_type FROM bitcoin_metrics WHERE metric_id = :metric');
    $summary = $pdo->prepare('SELECT COUNT(*) AS observation_count, MIN(observation_date) AS coverage_start, MAX(observation_date) AS coverage_end, SUM(quality_status = \'flagged\') AS flagged_count FROM bitcoin_observations WHERE metric_id = :metric AND series_key = \'main\'');
    $quality = $pdo->prepare(<<<'SQL'
        INSERT INTO bitcoin_data_quality (metric_id, series_key, expected_start, expected_end, observation_count, duplicate_count, missing_period_count, flagged_count, coverage_start, coverage_end, notes, checked_at)
        VALUES (:metric, 'main', :expected_start, :expected_end, :count, 0, :missing, :flagged, :coverage_start, :coverage_end, :notes, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE expected_start = VALUES(expected_start), expected_end = VALUES(expected_end), observation_count = VALUES(observation_count), missing_period_count = VALUES(missing_period_count), flagged_count = VALUES(flagged_count), coverage_start = VALUES(coverage_start), coverage_end = VALUES(coverage_end), notes = VALUES(notes), checked_at = VALUES(checked_at)
        SQL);
    $sync = $pdo->prepare(<<<'SQL'
        INSERT INTO bitcoin_sync_state (metric_id, latest_observation_date, last_checked_at, last_successful_sync_at, consecutive_failures)
        VALUES (:metric, :latest, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
        ON DUPLICATE KEY UPDATE latest_observation_date = VALUES(latest_observation_date), last_checked_at = VALUES(last_checked_at), last_successful_sync_at = VALUES(last_successful_sync_at), consecutive_failures = 0, last_error_at = NULL, last_error_code = NULL, last_error_message = NULL
        SQL);
    $expectedEnd = (new DateTimeImmutable('yesterday', new DateTimeZone('UTC')))->format('Y-m-d');
    foreach (array_keys($touched) as $metric) {
        $metricUpdate->execute(['source' => $sourceId, 'metric' => $metric]);
        $metadata->execute(['metric' => $metric]);
        $meta = $metadata->fetch();
        $summary->execute(['metric' => $metric]);
        $stats = $summary->fetch();
        $expectedStart = $meta['expected_start'] ?: $stats['coverage_start'];
        $missing = 0;
        $notes = 'Abgeleitete UTC-Tagesreihe. Fehlende Eingaben werden nicht aufgefüllt.';
        if ($meta['calendar_type'] === 'calendar_daily' && $expectedStart !== null) {
            $days = (new DateTimeImmutable($expectedStart))->diff(new DateTimeImmutable($expectedEnd))->days + 1;
            $missing = max(0, $days - (int) $stats['observation_count']);
        }
        $quality->execute(['metric' => $metric, 'expected_start' => $expectedStart, 'expected_end' => $expectedEnd, 'count' => (int) $stats['observation_count'], 'missing' => $missing, 'flagged' => (int) $stats['flagged_count'], 'coverage_start' => $stats['coverage_start'], 'coverage_end' => $stats['coverage_end'], 'notes' => $notes]);
        $sync->execute(['metric' => $metric, 'latest' => $stats['coverage_end']]);
    }
    $finish = $pdo->prepare("UPDATE bitcoin_sync_runs SET completed_at = UTC_TIMESTAMP(), status = 'success', metrics_checked = :checked, metrics_succeeded = :succeeded, rows_inserted = :inserted WHERE id = :id");
    $finish->execute(['checked' => count($touched), 'succeeded' => count($touched), 'inserted' => count($outputRows), 'id' => $runId]);
    $pdo->commit();
    echo sprintf("Ableitungen: %d neue Beobachtungen für %d Kennzahlen, %d Formelergebnisse im Speicher berechnet.\n", count($outputRows), count($touched), count($derivedKeys));
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Bitcoin-Ableitungen fehlgeschlagen: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
