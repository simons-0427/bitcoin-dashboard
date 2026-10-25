export const CURRENCIES = ['original', 'USD', 'EUR', 'GBP', 'JPY', 'CNY'];
export const COMPARISON_PERIODS = ['previous', '1y', '3y', '10y', 'max'];
export const FX_SERIES = Object.freeze({
  EUR: { id: 'eurusd', usdPerCurrency: true },
  GBP: { id: 'gbpusd', usdPerCurrency: true },
  JPY: { id: 'usdjpy', usdPerCurrency: false },
  CNY: { id: 'usdcny', usdPerCurrency: false },
});

export function numberOrNull(value) {
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

export function monetaryCurrency(metric) {
  return String(metric?.unit || '').match(/\b(USD|EUR|GBP|JPY|CNY)\b/)?.[1] || null;
}

export function displayMetric(metric, target) {
  const source = monetaryCurrency(metric);
  if (!source || target === 'original' || source === target || !CURRENCIES.includes(target)) return metric;
  const names = source === 'USD' ? /\bUSD\b|US-Dollar/g : source === 'EUR' ? /\bEUR\b|Euro/g : new RegExp(`\\b${source}\\b`, 'g');
  return { ...metric, unit: metric.unit.replace(source, target),
    label: metric.label.replace(names, target),
    description: ['price_usd', 'price_eur'].includes(metric.id)
      ? `Täglicher Referenzpreis in ${target}, historisch aus ${source}/BTC umgerechnet.`
      : `${metric.description || ''} Anzeige in ${target} zum historischen Wechselkurs.` };
}

export function prepareFxSeries(points) {
  return (Array.isArray(points) ? points : [])
    .filter((point) => /^\d{4}-\d{2}-\d{2}$/.test(point.date) && numberOrNull(point.value) > 0)
    .map((point) => ({ ...point, value: Number(point.value) }))
    .sort((a, b) => a.date.localeCompare(b.date));
}

function rateOnOrBefore(points, date) {
  let low = 0, high = points.length - 1, result = null;
  while (low <= high) {
    const middle = Math.floor((low + high) / 2);
    if (points[middle].date <= date) { result = points[middle]; low = middle + 1; }
    else high = middle - 1;
  }
  // Bridge weekends and holidays, but never use a future or indefinitely stale quote.
  const age = result ? (Date.parse(`${date}T00:00:00Z`) - Date.parse(`${result.date}T00:00:00Z`)) / 86_400_000 : Infinity;
  return age >= 0 && age <= 7 ? result : null;
}

export function convertPoints(metric, points, target, fx = {}) {
  const source = monetaryCurrency(metric);
  const outputMetric = displayMetric(metric, target);
  if (!source || target === 'original' || source === target || !CURRENCIES.includes(target)) {
    return { metric: outputMetric, points, converted: false, missing: 0, fxIds: [] };
  }
  const fxIds = [...new Set([source, target].filter((code) => code !== 'USD').map((code) => FX_SERIES[code].id))];
  let missing = 0;
  const converted = points.map((input) => {
    const point = input || {};
    const value = numberOrNull(point.value);
    const quotes = {};
    const usdValue = (code) => {
      if (code === 'USD') return 1;
      const quote = rateOnOrBefore(fx[code] || [], point.date);
      if (!quote) return null;
      quotes[FX_SERIES[code].id] = quote.date;
      return FX_SERIES[code].usdPerCurrency ? quote.value : 1 / quote.value;
    };
    const from = usdValue(source), to = usdValue(target);
    const result = value === null || from === null || to === null ? null : value * from / to;
    if (result === null) missing++;
    return { ...point, value: result, originalValue: point.value, originalUnit: metric.unit,
      fxDates: quotes, fxIds, displayCurrency: target };
  });
  return { metric: outputMetric, points: converted, converted: true, missing, fxIds };
}

export function comparisonPoints(stored, period = 'previous') {
  const latest = { date: stored?.latestDate, value: stored?.latestValue };
  const baseline = period === 'previous'
    ? { date: stored?.previousDate, value: stored?.previousValue }
    : stored?.comparisons?.[period];
  return [baseline, latest];
}

const dateFormatter = new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', day: '2-digit', month: '2-digit', year: 'numeric' });
function dateLabel(date) { return dateFormatter.format(new Date(`${date}T00:00:00Z`)); }
function precision(value) { return Math.abs(value) >= 1 ? 2 : Math.min(12, Math.max(2, Math.ceil(-Math.log10(Math.abs(value) || 1)) + 2)); }

export function formatCardChange(points, unit, mode = 'relative') {
  const first = points[0], last = points[1];
  const from = numberOrNull(first?.value), to = numberOrNull(last?.value);
  const unavailable = (label) => ({ text: 'Kein Vergleich', direction: 'neutral', label });
  if (!first?.date || !last?.date || first.date >= last.date || from === null || to === null) {
    return unavailable('Für diesen Vergleich fehlen zwei gültige Werte oder passende historische Wechselkurse.');
  }
  const delta = to - from;
  if (mode !== 'absolute' && from === 0 && delta !== 0) {
    return unavailable('Relative Änderung bei einem Vergleichswert von null nicht berechenbar.');
  }
  const value = mode === 'absolute' ? delta : from === 0 ? 0 : delta / Math.abs(from) * 100;
  const digits = mode === 'absolute' ? precision(value) : 2;
  const rounded = Number(value.toFixed(digits));
  const direction = rounded > 0 ? 'up' : rounded < 0 ? 'down' : 'neutral';
  const arrow = direction === 'up' ? '↑' : direction === 'down' ? '↓' : '→';
  const sign = rounded > 0 ? '+' : rounded < 0 ? '−' : '';
  const number = new Intl.NumberFormat('de-DE', { maximumFractionDigits: digits,
    ...(mode === 'absolute' && Math.abs(value) >= 1_000_000 ? { notation: 'compact' } : {}) }).format(Math.abs(value));
  const suffix = mode === 'absolute' ? unit.startsWith('%') ? unit.replace('%', 'Pp.') : unit : '%';
  const text = `${arrow} ${sign}${number}${suffix ? ` ${suffix}` : ''}`;
  const exact = new Intl.NumberFormat('de-DE', { maximumFractionDigits: digits }).format(Math.abs(value));
  return { text, direction, label: `${mode === 'absolute' ? 'Absolute' : 'Relative'} Änderung vom ${dateLabel(first.date)} bis ${dateLabel(last.date)}: ${arrow} ${sign}${exact} ${suffix}` };
}

export function changeDescription(mode, period) {
  const label = {
    previous: 'dem unmittelbar vorherigen gespeicherten Wert',
    '1y': 'dem ersten gespeicherten Wert innerhalb des letzten Jahres',
    '3y': 'dem ersten gespeicherten Wert innerhalb der letzten drei Jahre',
    '10y': 'dem ersten gespeicherten Wert innerhalb der letzten zehn Jahre',
    max: 'dem ersten gespeicherten Wert der gesamten Zeitreihe',
  }[period] || 'dem unmittelbar vorherigen gespeicherten Wert';
  return `${mode === 'absolute' ? 'Absolute' : 'Relative'} Änderung gegenüber ${label}.`;
}
