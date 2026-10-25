"""Independent checks for the selected public Bitcoin history; no database access."""
import bisect
import calendar
import csv
import datetime as dt
import json
import math
from pathlib import Path

root = Path(__file__).resolve().parent.parent
manifest = json.loads((root / 'var/bitcoin-core-manifest.json').read_text(encoding='utf-8'))
active = set(json.loads((root / 'data/active-metrics.json').read_text())['metricIds'])
allowed = {'coinmetrics_community_btc', 'open_bitcoin_metrics_obm', 'cftc_cot_legacy', 'alternative_me_fng', 'derived_bitcoin_metrics'}
values = {}
sources = {}
keys = set()
count = 0
for row in csv.DictReader((root / 'var/bitcoin-core-master.csv').open(encoding='utf-8', newline='')):
    metric, date = row['metric_id'], row['observation_date']
    assert metric in active and row['series_key'] == 'main'
    assert row['source_slug'] in allowed
    assert date <= manifest['cutoffInclusive'] and dt.date.fromisoformat(date).isoformat() == date
    assert (metric, date) not in keys
    assert len(row['raw_payload_hash']) == 64
    keys.add((metric, date))
    values.setdefault(metric, {})[date] = float(row['value'])
    sources.setdefault(metric, set()).add(row['source_slug'])
    count += 1
assert len(active) == len(values) == 50 and count == manifest['observations']
inputs = {}
for row in csv.DictReader((root / 'var/bitcoin-core-inputs.csv').open(encoding='utf-8', newline='')):
    inputs.setdefault(row['metric_id'], {})[row['observation_date']] = float(row['value'])

checks = 0
def near(actual, expected, context):
    global checks
    checks += 1
    assert math.isclose(actual, expected, rel_tol=1e-12, abs_tol=2e-10), (context, actual, expected)

def shift(date, days):
    return (dt.date.fromisoformat(date) + dt.timedelta(days=days)).isoformat()

def sample(metric):
    dates = sorted(values[metric])
    return sorted(set(dates[::max(1, len(dates)//140)] + dates[-30:]))

prices = inputs['price_usd']
supply = inputs['supply_btc']
mvrv = inputs['mvrv_ratio']
for metric in ('market_cap_usd', 'realized_cap_usd', 'realized_price_usd', 'nupl_pct'):
    for date in sample(metric):
        expected = {'market_cap_usd': lambda: prices[date]*supply[date], 'realized_cap_usd': lambda: prices[date]*supply[date]/mvrv[date],
                    'realized_price_usd': lambda: prices[date]/mvrv[date], 'nupl_pct': lambda: 100*(1-1/mvrv[date])}[metric]()
        near(values[metric][date], expected, (metric, date))
for days, metric in [(1,'return_1d'),(7,'return_7d'),(30,'return_30d'),(90,'return_90d'),(365,'return_1y')]:
    for date in sample(metric):
        near(values[metric][date], (prices[date]/prices[shift(date,-days)]-1)*100, (metric,date))
for date in sample('cagr_3y_pct'):
    source=dt.date.fromisoformat(date);year=source.year-3
    prior=dt.date(year,source.month,min(source.day,calendar.monthrange(year,source.month)[1])).isoformat()
    near(values['cagr_3y_pct'][date], ((prices[date]/prices[prior])**(1/3)-1)*100, ('cagr',date))
for date in sample('price_sma_200d_usd'):
    near(values['price_sma_200d_usd'][date], sum(prices[shift(date,-i)] for i in range(200))/200, ('sma200d',date))
for date in sample('mayer_multiple'):
    near(values['mayer_multiple'][date], prices[date]/values['price_sma_200d_usd'][date], ('mayer',date))
weekly = sorted(date for date in prices if dt.date.fromisoformat(date).weekday()==6)
for date in sample('price_sma_200w_usd'):
    position=weekly.index(date)
    near(values['price_sma_200w_usd'][date], sum(prices[d] for d in weekly[position-199:position+1])/200, ('sma200w',date))
for days,metric in [(30,'volatility_30d'),(90,'volatility_90d')]:
    for date in sample(metric):
        returns=[math.log(prices[shift(date,-i)]/prices[shift(date,-i-1)]) for i in range(days)]
        mean=sum(returns)/days
        near(values[metric][date], math.sqrt(sum((r-mean)**2 for r in returns)/days)*math.sqrt(365)*100, (metric,date))
fx_dates=sorted(inputs['fx_usd_per_eur'])
for date in sample('price_eur'):
    fx=fx_dates[bisect.bisect_right(fx_dates,date)-1]
    assert (dt.date.fromisoformat(date)-dt.date.fromisoformat(fx)).days<=7
    near(values['price_eur'][date],prices[date]/inputs['fx_usd_per_eur'][fx],('eur',date))

height=0;heights={}
for date in sorted(inputs['blocks_count']):
    height+=int(inputs['blocks_count'][date]);heights[date]=height
for date in sample('blocks_until_halving'):
    expected=(heights[date]//210000+1)*210000-heights[date]
    assert values['blocks_until_halving'][date]==expected
    checks+=1
for date in sample('block_reward_btc'):
    near(values['block_reward_btc'][date],(5000000000 >> (heights[date]//210000))/100000000,('subsidy',date))
for date in sample('issued_btc_daily'):
    first=heights[date]-int(inputs['blocks_count'][date])+1
    expected=sum(5000000000 >> (height//210000) for height in range(first,heights[date]+1))/100000000
    near(values['issued_btc_daily'][date],expected,('issuance',date))
for date in sample('puell_multiple'):
    issuance_usd=[values['issued_btc_daily'][shift(date,-i)]*prices[shift(date,-i)] for i in range(365)]
    near(values['puell_multiple'][date],issuance_usd[0]/(sum(issuance_usd)/365),('puell',date))
for date in sample('blockspace_utilization_pct'):
    near(values['blockspace_utilization_pct'][date], inputs['block_weight_mwu'][date]/(inputs['blocks_count'][date]*4)*100,('utilization',date))
for date in sample('exchange_netflow_btc'):
    near(values['exchange_netflow_btc'][date],inputs['exchange_inflow_btc'][date]-inputs['exchange_outflow_btc'][date],('exchange netflow',date))
for date in sample('fees_average_usd'):
    near(values['fees_average_usd'][date],inputs['fees_total_btc'][date]*prices[date]/inputs['transactions_count'][date],('average fee',date))
for date in sample('miner_revenue_usd'):
    near(values['miner_revenue_usd'][date],(values['issued_btc_daily'][date]+inputs['fees_total_btc'][date])*prices[date],('miner revenue',date))
for date in sample('hashprice_usd_ph_day'):
    near(values['hashprice_usd_ph_day'][date],values['miner_revenue_usd'][date]/(inputs['hashrate_eh_s'][date]*1000),('hashprice',date))

obm={'difficulty','utxo_count','coin_days_destroyed','liveliness','block_weight_mwu','blockspace_utilization_pct'}
for metric in active:
    expected=max(inputs['block_weight_mwu']) if metric=='blockspace_utilization_pct' else max(inputs[metric]) if metric in obm or metric=='futures_open_interest_usd' else weekly[-1] if metric=='price_sma_200w_usd' else manifest['cutoffInclusive']
    assert max(values[metric])==expected,(metric,max(values[metric]),expected)
assert sources['fear_greed_index']=={'alternative_me_fng'} and sources['difficulty']=={'open_bitcoin_metrics_obm'}
print(json.dumps({'metrics':len(values),'observations':count,'independentFormulaChecks':checks,'sourceAndDateChecks':'passed','privateSourcesInActiveMaster':False},indent=2))
