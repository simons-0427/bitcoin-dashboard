#!/bin/sh
set -eu
umask 077

BASE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PHP_BIN=${BITCOIN_PHP_BIN:-php}
CA_BUNDLE="$BASE/includes/bitcoin/cacert.pem"
if [ ! -r "$CA_BUNDLE" ]; then
    printf '%s\n' 'Das Zertifikatspaket includes/bitcoin/cacert.pem fehlt oder ist nicht lesbar.' >&2
    exit 1
fi
bitcoin_php() {
    "$PHP_BIN" -d "curl.cainfo=$CA_BUNDLE" -d "openssl.cafile=$CA_BUNDLE" "$@"
}
COINMETRICS_CSV=$(mktemp "$BASE/var/coinmetrics.XXXXXX")
ECB_CSV=$(mktemp "$BASE/var/ecb.XXXXXX")
OBM_CSV=$(mktemp "$BASE/var/obm.XXXXXX")
CFTC_CSV=$(mktemp "$BASE/var/cftc.XXXXXX")
CORE_CSV=$(mktemp "$BASE/var/core-series.XXXXXX")
trap 'rm -f "$COINMETRICS_CSV" "$ECB_CSV" "$OBM_CSV" "$CFTC_CSV" "$CORE_CSV"' EXIT HUP INT TERM

bitcoin_php "$BASE/cron/fetch-coinmetrics.php" 7 "$COINMETRICS_CSV"
bitcoin_php "$BASE/cron/fetch-ecb-fx.php" 7 "$ECB_CSV"
bitcoin_php "$BASE/cron/fetch-open-bitcoin-metrics.php" 90 "$OBM_CSV"
bitcoin_php "$BASE/cron/fetch-core-series.php" 90 "$CORE_CSV"
bitcoin_php "$BASE/cron/import-observations.php" --trigger=cron "$COINMETRICS_CSV"
bitcoin_php "$BASE/cron/fetch-cftc-futures-open-interest.php" --recent "$CFTC_CSV"
bitcoin_php "$BASE/cron/import-observations.php" --trigger=cron "$CFTC_CSV"
bitcoin_php "$BASE/cron/import-observations.php" --trigger=cron "$ECB_CSV"
if [ "$(wc -l < "$OBM_CSV")" -gt 1 ]; then
    bitcoin_php "$BASE/cron/import-observations.php" --trigger=cron "$OBM_CSV"
else
    printf '%s\n' 'OBM lieferte im 90-Tage-Fenster keine neuen Zeilen; der Abruf wird übersprungen.'
fi
bitcoin_php "$BASE/cron/import-observations.php" --trigger=cron "$CORE_CSV"
bitcoin_php "$BASE/cron/derive-metrics.php" --trigger=cron
