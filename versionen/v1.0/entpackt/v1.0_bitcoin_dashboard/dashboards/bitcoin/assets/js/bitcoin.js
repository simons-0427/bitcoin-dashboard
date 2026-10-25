import { renderChartTooltip, bitcoinCrosshair, bindChartKeyboard } from './chart-interaction.js?v=20261022-1';

import { CURRENCIES, COMPARISON_PERIODS, FX_SERIES, prepareFxSeries,
  displayMetric, convertPoints, comparisonPoints, formatCardChange, changeDescription } from './display.js?v=20261025-1';

let displayCurrency = 'USD';
let cardChangeMode = 'relative';
let cardChangePeriod = 'previous';
let fxSeries = {};
let fxLoading;
let currentSeries;
const categoryName = new Map();
let catalog;
let selectedId = 'price_usd';
let activeCategory = 'all';
let activeRange = 'max';
let chartScale = 'linear';
let seriesRequest;
let bitcoinChart;
const storedMetrics = new Map();
const seriesPalette = [
  '#f7931a', '#38bdf8', '#c4b5fd', '#f6a94a', '#f472b6', '#4ade80',
  '#fb7185', '#22d3ee', '#e879f9', '#a3e635', '#facc15', '#60a5fa',
  '#2dd4bf', '#c084fc', '#fb923c', '#fda4af', '#93c5fd', '#86efac',
  '#fde047', '#e11d48', '#7dd3fc', '#d8b4fe', '#a78bfa',
];

const $ = (selector) => document.querySelector(selector);

function formatValue(raw) {
  if (raw === null || raw === undefined || raw === '') return 'Keine Daten';
  const value = Number(raw);
  if (!Number.isFinite(value)) return 'Keine Daten';
  const magnitude = Math.abs(value);
  const digits = magnitude >= 1_000_000 ? 0 : magnitude >= 100 ? 2 : magnitude >= 1 ? 4 : 12;
  return new Intl.NumberFormat('de-DE', { maximumFractionDigits: digits }).format(value);
}

function formatCardValue(raw, unit = '') {
  if (raw === null || raw === undefined || raw === '') return 'Keine Daten';
  const value = Number(raw);
  if (!Number.isFinite(value)) return 'Keine Daten';
  return Math.abs(value) >= 1_000_000
    ? new Intl.NumberFormat('de-DE', { notation: 'compact', maximumFractionDigits: 2 }).format(value)
    : /^(%|USD|EUR|GBP|JPY|CNY)/.test(unit)
      ? new Intl.NumberFormat('de-DE', { maximumFractionDigits: Math.abs(value) < 0.01 && value !== 0 ? Math.min(10, Math.ceil(-Math.log10(Math.abs(value))) + 1) : 2 }).format(value)
      : formatValue(value);
}

function formatAxisValue(raw, unit) {
  const value = Number(raw);
  if (!Number.isFinite(value)) return '';
  const number = Math.abs(value) >= 1_000_000
    ? new Intl.NumberFormat('de-DE', { notation: 'compact', maximumFractionDigits: 2 }).format(value)
    : formatValue(value);
  return unit ? `${number} ${unit}` : number;
}

function formatDate(isoDate) {
  if (!isoDate) return 'Offen';
  const date = new Date(`${isoDate}T00:00:00Z`);
  return Number.isNaN(date.getTime()) ? isoDate : new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function metricChange(metric, stored) {
  const points = comparisonPoints(stored, cardChangePeriod);
  const displayed = convertPoints(metric, points, displayCurrency, fxSeries);
  return formatCardChange(displayed.points, displayed.metric.unit, cardChangeMode);
}

function syncLanguageTargets() {
  const current = new URL(window.location.href);
  const parameters = new URLSearchParams();
  for (const key of ['metric', 'currency', 'changeMode', 'changePeriod', 'scale']) {
    if (current.searchParams.has(key)) parameters.set(key, current.searchParams.get(key));
  }
  const query = parameters.size ? `?${parameters}` : '';
  document.querySelectorAll('[data-language-target]').forEach((button) => {
    for (const key of ['languageTarget', 'languageFileTarget']) {
      if (button.dataset[key]) button.dataset[key] = button.dataset[key].split('?')[0] + query;
    }
  });
}

function updateDisplayUrl() {
  const url = new URL(window.location.href);
  for (const [key, value, fallback] of [['currency', displayCurrency, 'USD'],
    ['changeMode', cardChangeMode, 'relative'], ['changePeriod', cardChangePeriod, 'previous'],
    ['scale', chartScale === 'logarithmic' ? 'log' : 'linear', 'linear']]) {
    if (value === fallback) url.searchParams.delete(key);
    else url.searchParams.set(key, value);
  }
  window.history.replaceState(null, '', url);
  syncLanguageTargets();
}

function renderCardChanges() {
  $('#card-change-description').textContent = changeDescription(cardChangeMode, cardChangePeriod);
  renderOverview();
  renderCatalog();
  updateDisplayUrl();
}

async function ensureFxSeries() {
  if (Object.keys(fxSeries).length === Object.keys(FX_SERIES).length) return fxSeries;
  if (fxLoading) return fxLoading;
  fxLoading = Promise.all(Object.entries(FX_SERIES).map(async ([currency, definition]) => {
    const url = new URL('../weltmarkt/api/weltmarkt/series.php', window.location.href);
    url.searchParams.set('metric', definition.id);
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`Weltmarkt-Devisenreihe ${definition.id} nicht verfügbar.`);
    const payload = await response.json();
    const points = prepareFxSeries(payload.points);
    if (!payload.success || !points.length) throw new Error(`Weltmarkt-Devisenreihe ${definition.id} enthält keine Kurse.`);
    return [currency, points];
  })).then((entries) => { fxSeries = Object.fromEntries(entries); return fxSeries; })
    .finally(() => { fxLoading = null; });
  return fxLoading;
}

async function changeCurrency(target) {
  if (!CURRENCIES.includes(target)) return;
  const previous = displayCurrency;
  const select = $('#display-currency');
  const note = $('#currency-note');
  select.disabled = true;
  note.classList.remove('is-error');
  note.textContent = target === 'original' ? 'Originaleinheiten werden angezeigt.' : 'Gespeicherte Weltmarkt-Devisenkurse werden geladen …';
  try {
    if (target !== 'original') await ensureFxSeries();
    displayCurrency = target;
    select.value = target;
    note.textContent = target === 'original' ? 'Originaleinheiten der gespeicherten Quellen.'
      : `Geldbeträge in ${target}: historische Weltmarkt-Kurse, letzter verfügbarer Handelstag bis höchstens 7 Kalendertage zuvor. Fehlende Kurse bleiben Lücken. Prozent- und BTC-Werte bleiben unverändert.`;
    renderCardChanges();
    const selected = catalog.metrics.find((metric) => metric.id === selectedId);
    if (selected) $('#chart-title').textContent = displayMetric(selected, displayCurrency).label;
    if (currentSeries?.metricId === selectedId) renderChart(selected, currentSeries.stored, currentSeries.data);
  } catch (error) {
    displayCurrency = previous;
    select.value = previous;
    updateDisplayUrl();
    note.textContent = `${error.message} Die bisherige Währung bleibt ausgewählt.`;
    note.classList.add('is-error');
  } finally { select.disabled = false; }
}

function updateChartScaleControls(ready = false, logAllowed = false, unavailableNote = '') {
  if (ready && chartScale === 'logarithmic' && !logAllowed) chartScale = 'linear';
  document.querySelectorAll('[data-chart-scale]').forEach((button) => {
    const active = button.dataset.chartScale === chartScale;
    button.disabled = !ready || (button.dataset.chartScale === 'logarithmic' && !logAllowed);
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-pressed', String(active));
  });
  const note = $('#chart-scale-note');
  note.textContent = !ready ? '' : !logAllowed ? unavailableNote
    : chartScale === 'logarithmic' ? 'Logarithmisch: Gleiche Abstände entsprechen gleichen prozentualen Änderungen.' : '';
  note.hidden = !note.textContent;
  if (ready) updateDisplayUrl();
}

function showEmptyChart(title, description) {
  updateChartScaleControls();
  bitcoinChart?.destroy();
  $('#chart-tooltip')?.remove();
  bitcoinChart = undefined;
  $('#chart-area').classList.remove('is-pool-snapshot');
  $('#chart-area').classList.add('is-empty');
  $('#chart-details').replaceChildren();
  $('#chart-details').hidden = true;
  $('#chart-canvas').hidden = true;
  $('#chart-empty').hidden = false;
  $('#chart-empty-title').textContent = title;
  $('#chart-empty-description').textContent = description;
  $('#chart-area').setAttribute('aria-label', `${title}. ${description}`);
  $('#stat-latest-label').textContent = 'Letzter Wert';
  $('#stat-latest').textContent = 'Keine Daten';
  $('#stat-latest-date').textContent = 'Stand: noch nicht importiert';
  $('#stat-change-label').textContent = 'Änderung im Zeitraum';
  $('#stat-change').textContent = 'Keine Daten';
  $('#stat-change-note').textContent = 'Erster bis letzter gespeicherter Wert';
  $('#stat-coverage').textContent = 'Offen';
  $('#stat-coverage-note').textContent = 'Je Kennzahl separat geprüft';
}

function updateRangeButtons(enabled) {
  document.querySelectorAll('.range-controls button').forEach((button) => {
    button.disabled = !enabled;
    button.classList.toggle('is-active', button.dataset.range === activeRange);
  });
}

function setSource(name, sourceUrl, attribution, sourceSlug) {
  $('#chart-source').textContent = name;
  const sourceLink = $('#chart-source-link');
  let safeUrl;
  try {
    safeUrl = new URL(sourceUrl);
  } catch (_) {
    safeUrl = null;
  }
  sourceLink.hidden = safeUrl?.protocol !== 'https:';
  if (!sourceLink.hidden) sourceLink.href = safeUrl.href;
  $('#chart-attribution').textContent = attribution || '';
  $('#chart-license-link').hidden = sourceSlug !== 'coinmetrics_community_btc';
}

function selectMetric(metricId, scrollToChart = false) {
  const metric = catalog.metrics.find((item) => item.id === metricId);
  if (!metric) return;

  selectedId = metric.id;
  currentSeries = undefined;
  $('#chart-category').textContent = categoryName.get(metric.category) || metric.category;
  $('#chart-title').textContent = displayMetric(metric, displayCurrency).label;
  $('#chart-description').textContent = metric.description;
  const stored = storedMetrics.get(metric.id);
  setSource(stored?.sourceName || `${metric.sourceHint} (Kandidat)`, stored?.sourceUrl, stored?.attribution, stored?.sourceSlug);
  $('#chart-frequency').textContent = metric.frequency === 'weekly'
    ? 'Wöchentlich'
    : metric.frequency === 'monthly'
      ? 'Monatlich'
      : metric.frequency === 'event'
        ? 'Je Ereignis'
        : 'Täglich';
  $('#chart-method').textContent = stored?.methodology || 'Definition und Abdeckung vor Import prüfen';
  document.querySelectorAll('[data-metric-id]').forEach((card) => {
    const isSelected = card.dataset.metricId === metric.id;
    card.classList.toggle('is-selected', isSelected);
    card.setAttribute('aria-pressed', String(isSelected));
  });

  const url = new URL(window.location.href);
  url.searchParams.set('metric', metric.id);
  window.history.replaceState(null, '', url);
  syncLanguageTargets();
  loadSeries(metric);
  if (scrollToChart) $('#trend').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function loadSeries(metric) {
  if (seriesRequest) seriesRequest.abort();
  const stored = storedMetrics.get(metric.id);
  if (storedMetrics.size === 0) {
    updateRangeButtons(false);
    showEmptyChart('Datenbank wird verbunden', 'Gespeicherte Bitcoin-Werte werden abgefragt.');
    return;
  }
  if (!stored || (!(Number(stored.observationCount) > 0) && !stored.latestDate)) {
    updateRangeButtons(false);
    showEmptyChart('Noch keine Daten', 'Für diese Kennzahl sind noch keine Beobachtungen gespeichert.');
    return;
  }

  updateRangeButtons(metric.id !== 'mining_pool_share' && Number(stored.observationCount) > 1);
  showEmptyChart('Zeitreihe wird geladen', 'Gespeicherte Beobachtungen werden abgerufen.');
  const controller = new AbortController();
  seriesRequest = controller;
  const url = new URL('api/bitcoin/series.php', window.location.href);
  url.searchParams.set('metric', metric.id);
  if (activeRange !== 'max') {
    const end = new Date(`${stored.coverageEnd || new Date().toISOString().slice(0, 10)}T00:00:00Z`);
    const start = new Date(end);
    start.setUTCFullYear(start.getUTCFullYear() - Number(activeRange));
    url.searchParams.set('start', start.toISOString().slice(0, 10));
    url.searchParams.set('end', end.toISOString().slice(0, 10));
  }

  try {
    const response = await fetch(url, { signal: controller.signal });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    if (!data.success || !Array.isArray(data.points)) throw new Error('Ungültige Antwort');
    if (selectedId === metric.id && seriesRequest === controller && !controller.signal.aborted) {
      currentSeries = { metricId: metric.id, stored, data };
      renderChart(metric, stored, data);
    }
  } catch (error) {
    if (error.name !== 'AbortError' && selectedId === metric.id) {
      console.error('Bitcoin series:', error);
      showEmptyChart('Zeitreihe nicht verfügbar', 'Die gespeicherten Werte konnten gerade nicht geladen werden.');
    }
  }
}

function renderChart(metric, stored, data) {
  const conversion = convertPoints(metric, data.points, displayCurrency, fxSeries);
  metric = conversion.metric;
  data = { ...data, points: conversion.points };
  $('#chart-title').textContent = metric.label;
  $('#chart-description').textContent = metric.description;
  $('#chart-fx-link').hidden = !conversion.converted;
  if (conversion.converted) $('#chart-fx-link').href = `../weltmarkt/?metric=${conversion.fxIds[0]}`;
  const points = data.points
    .filter((point) => point.date && (point.value === null || point.value !== '' && Number.isFinite(Number(point.value))))
    .map((point) => ({ ...point, series: point.series || 'main', value: point.value === null ? null : Number(point.value) }))
    .filter((point) => Number.isFinite(Date.parse(`${point.date}T00:00:00Z`)))
    .sort((left, right) => left.date.localeCompare(right.date) || left.series.localeCompare(right.series));
  if (!points.some((point) => point.value !== null)) {
    showEmptyChart('Keine Werte im Zeitraum', conversion.converted ? 'Für diesen Zeitraum fehlen passende historische Wechselkurse. Wähle Original oder einen anderen Zeitraum.' : 'Wähle einen längeren Zeitraum oder eine andere Kennzahl.');
    return;
  }

  const accent = metric.id === 'price_usd' || metric.id === 'price_eur'
    ? '#f7931a'
    : getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#6ee7b7';
  const dates = [...new Set(points.map((point) => point.date))].sort();
  const isPoolShare = metric.id === 'mining_pool_share';
  const isPoolHashrateShare = metric.id === 'mining_pool_hashrate_share_pct';
  const isAgeWave = metric.id === 'realized_cap_hodl_waves';
  const poolSnapshotDate = isPoolShare ? dates.at(-1) : null;
  const poolSnapshotPoints = isPoolShare
    ? points.filter((point) => point.date === poolSnapshotDate).sort((left, right) => right.value - left.value)
    : [];
  const seriesMetadata = new Map((data.series || []).map((series) => [series.series_key, series]));
  const seriesLabelFallbacks = new Map([
    ['openbitcoin_rolling_24h', 'OpenBitcoin rollierende 24 Stunden'],
  ]);
  const seriesLabel = (seriesKey) => {
    const displayName = seriesMetadata.get(seriesKey)?.display_name;
    return (displayName && displayName !== seriesKey)
      ? displayName
      : (seriesLabelFallbacks.get(seriesKey) || seriesKey);
  };
  const seriesKeys = [...new Set(points.map((point) => point.series))].sort((left, right) => {
    if (left === 'main') return -1;
    if (right === 'main') return 1;
    return (seriesMetadata.get(left)?.display_order || 0) - (seriesMetadata.get(right)?.display_order || 0);
  });
  let labels = dates;
  let datasets;
  if (isPoolShare) {
    const topPools = poolSnapshotPoints.slice(0, 12).map((point) => ({
      label: seriesLabel(point.series),
      value: point.value,
      sourcePoint: point,
    }));
    const remainingPools = poolSnapshotPoints.slice(12);
    if (remainingPools.length) {
      topPools.push({
        label: `Übrige Pools (${remainingPools.length})`,
        value: remainingPools.reduce((sum, point) => sum + point.value, 0),
        sourcePoint: remainingPools[0],
      });
    }
    labels = topPools.map((pool) => pool.label);
    datasets = [{
      label: 'Anteil an zugeordneten Blöcken',
      data: topPools.map((pool) => pool.value),
      sourcePoints: topPools.map((pool) => pool.sourcePoint),
      borderColor: accent,
      backgroundColor: `${accent}a8`,
      borderWidth: 1,
      borderRadius: 3,
      maxBarThickness: 22,
    }];
    renderPoolShareDetails(poolSnapshotPoints, seriesMetadata, poolSnapshotDate);
  } else {
    $('#chart-details').replaceChildren();
    $('#chart-details').hidden = true;
    datasets = seriesKeys.map((seriesKey, index) => {
      const seriesPoints = points.filter((point) => point.series === seriesKey);
      const pointsByDate = new Map(seriesPoints.map((point) => [point.date, point]));
      const seriesName = seriesKey === 'main'
        ? metric.label
        : seriesLabel(seriesKey);
      const color = seriesKeys.length === 1 ? accent : seriesPalette[index % seriesPalette.length];
      return {
        label: seriesName,
        data: dates.map((date) => pointsByDate.get(date)?.value ?? null),
        sourcePoints: dates.map((date) => pointsByDate.get(date) || null),
        borderColor: color,
        backgroundColor: `${color}18`,
        borderWidth: 2.2,
        pointRadius: seriesPoints.length < 60 ? 2 : 0,
        pointHoverRadius: 4,
        pointHitRadius: 10,
        pointHoverBackgroundColor: '#101017',
        pointHoverBorderColor: color,
        tension: 0.08,
        spanGaps: false,
        fill: isAgeWave || seriesKeys.length === 1,
      };
    });
  }
  const yValues = isPoolShare ? [] : datasets.flatMap((dataset) => dataset.data).filter(Number.isFinite);
  const allValuesAreNonNegative = yValues.length > 0 && yValues.every((value) => value >= 0);
  const logAllowed = !isPoolShare && !isAgeWave && yValues.length > 0 && yValues.every((value) => value > 0);
  updateChartScaleControls(true, logAllowed, isPoolShare || isAgeWave
    ? 'Für Balken- und gestapelte Diagramme wird die nominale Skala verwendet.'
    : 'Die Reihe enthält Nullwerte oder negative Werte. Logarithmisch ist nur für ausschließlich positive Werte verfügbar.');
  const chartCanvas = $('#chart-canvas');
  if (!window.Chart) {
    showEmptyChart('Diagramm nicht verfügbar', 'Die lokale Chart-Bibliothek konnte nicht geladen werden.');
    return;
  }
  bitcoinChart?.destroy();
  chartCanvas.hidden = false;
  chartCanvas.tabIndex = 0;
  $('#chart-area').classList.remove('is-empty');
  $('#chart-area').classList.toggle('is-pool-snapshot', isPoolShare);
  chartCanvas.setAttribute('aria-describedby', 'chart-hover-help');
  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 140 },
    events: ['mousemove', 'mouseout', 'click', 'touchstart', 'touchmove'],
    normalized: true,
    interaction: isPoolShare
      ? { mode: 'nearest', intersect: true }
      : isAgeWave
        ? { mode: 'nearest', intersect: false }
        : { mode: 'index', intersect: false, axis: 'x' },
    plugins: {
      legend: {
        display: !isPoolShare && datasets.length > 1,
        position: 'bottom',
        labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 18, color: '#8f91a3' },
      },
      tooltip: {
        enabled: false,
        external: renderChartTooltip,
        mode: isPoolShare || isAgeWave ? 'nearest' : 'index',
        intersect: isPoolShare,
        backgroundColor: 'rgba(12,12,15,.96)',
        titleColor: '#f8fafc',
        bodyColor: '#d4d4df',
        borderColor: '#343446',
        borderWidth: 1,
        padding: 12,
        callbacks: {
          title: (items) => {
            if (!items.length) return '';
            return isPoolShare ? `Stand ${formatDate(poolSnapshotDate)}` : formatDate(dates[items[0].dataIndex]);
          },
          label: (context) => {
            const value = isPoolShare ? context.parsed.x : context.parsed.y;
            const name = isPoolShare ? context.label : context.dataset.label;
            return `${name}: ${formatAxisValue(value, metric.unit)}`;
          },
          afterLabel: (context) => {
            if (isPoolHashrateShare) return '';
            const point = context.dataset.sourcePoints?.[context.dataIndex];
            if (!point) return '';
            const source = point.sourceName ? `Quelle: ${point.sourceName}` : '';
            if (isAgeWave) return source ? [source] : '';
            const timestamp = point.sourceTimestamp ? `Erfasst: ${point.sourceTimestamp}` : '';
            const fx = point.fxDates ? `Weltmarkt FX: ${Object.entries(point.fxDates).map(([id, date]) => `${id} vom ${formatDate(date)}`).join(', ')}` : '';
            return [source, timestamp, fx].filter(Boolean);
          },
          footer: (items) => {
            if (!isPoolHashrateShare || !items.length) return '';
            const point = items.map((item) => item.dataset.sourcePoints?.[item.dataIndex]).find(Boolean);
            return point?.sourceName ? `Quelle: ${point.sourceName}` : '';
          },
        },
      },
    },
  };
  if (isPoolShare) {
    chartOptions.indexAxis = 'y';
    chartOptions.scales = {
      x: {
        title: { display: true, text: metric.unit, color: '#cbd0df', font: { size: 12 } },
        beginAtZero: true,
        grace: '8%',
        grid: { color: 'rgba(255, 255, 255, 0.08)' },
        border: { display: true, color: 'rgba(148, 163, 184, .35)' },
        ticks: { color: '#aeb2c1', maxTicksLimit: 7, padding: 8, callback: (value) => formatAxisValue(value, metric.unit) },
      },
      y: {
        grid: { display: false },
        border: { color: '#2b2b39' },
        ticks: { color: '#aeb2c1', autoSkip: false, font: { size: 10 } },
      },
    };
  } else {
    chartOptions.scales = {
      x: {
        grid: { display: false },
        border: { color: '#2b2b39' },
        ticks: {
          color: '#8f91a3',
          maxTicksLimit: Math.max(3, Math.min(8, Math.floor(chartCanvas.parentElement.clientWidth / 95))),
          maxRotation: 0,
          callback(value) {
            const date = this.getLabelForValue(value);
            const timestamp = Date.parse(`${date}T00:00:00Z`);
            if (!Number.isFinite(timestamp)) return '';
            const spanDays = (Date.parse(`${dates.at(-1)}T00:00:00Z`) - Date.parse(`${dates[0]}T00:00:00Z`)) / 86_400_000;
            if (spanDays <= 45) {
              return new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', day: '2-digit', month: 'short' }).format(new Date(timestamp));
            }
            const years = spanDays / 365.25;
            return years < 2
              ? new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', month: 'short', year: '2-digit' }).format(new Date(timestamp))
              : new Date(timestamp).getUTCFullYear().toString();
          },
        },
      },
      y: {
        type: chartScale,
        display: true,
        title: { display: true, text: metric.unit, color: '#cbd0df', padding: { top: 4, bottom: 10 }, font: { size: 12, weight: '500' } },
        stacked: isAgeWave,
        min: chartScale === 'linear' && allValuesAreNonNegative ? 0 : undefined,
        grace: '8%',
        grid: { color: 'rgba(255, 255, 255, 0.08)' },
        border: { display: true, color: 'rgba(148, 163, 184, .35)' },
        ticks: {
          color: '#aeb2c1',
          maxTicksLimit: 7,
          padding: 8,
          callback: (value) => formatAxisValue(value, ''),
        },
      },
    };
  }
  bitcoinChart = new window.Chart(chartCanvas.getContext('2d'), {
    type: isPoolShare ? 'bar' : 'line',
    data: { labels, datasets },
    options: chartOptions,
    plugins: [bitcoinCrosshair],
  });
  bindChartKeyboard(bitcoinChart);
  chartCanvas.dataset.scale = chartScale;
  $('#chart-empty').hidden = true;
  $('#chart-area').classList.remove('is-empty');
  $('#chart-area').classList.toggle('is-pool-snapshot', isPoolShare);
  $('#chart-area').setAttribute('aria-label', isPoolShare
    ? `${metric.label}: Balkendiagramm der zwölf größten Pools und der übrigen Pools am ${formatDate(poolSnapshotDate)}. Eine Tabelle enthält alle ${poolSnapshotPoints.length} Poolwerte.`
    : `${metric.label}: ${points.length} gespeicherte Beobachtungen vom ${formatDate(points[0].date)} bis ${formatDate(points.at(-1).date)}. Der Mauszeiger zeigt Datum, Wert und Quelle.`);
  chartCanvas.setAttribute('aria-label', isPoolShare
    ? `${metric.label}: horizontales Balkendiagramm mit Werteachse in Prozent und interaktiven Werten.`
    : `${metric.label}: Diagramm mit sichtbarer Werteachse. Beim Darüberfahren erscheinen Datum, Messwert und Quelle.`);

  const usablePoints = points.filter((point) => point.value !== null);
  const primaryPoints = usablePoints.filter((point) => point.series === 'main');
  const latestPoolHashratePoint = isPoolHashrateShare
    ? points.filter((point) => point.date === dates.at(-1)).sort((left, right) => right.value - left.value)[0]
    : null;
  const statPoints = primaryPoints.length
    ? primaryPoints
    : isPoolHashrateShare && latestPoolHashratePoint
      ? points.filter((point) => point.series === latestPoolHashratePoint.series)
        .sort((left, right) => left.date.localeCompare(right.date))
      : ['hodl_waves', 'realized_cap_hodl_waves'].includes(metric.id)
      ? [...points.reduce((byDate, point) => {
        const current = byDate.get(point.date) || { ...point, value: 0 };
        current.value += point.value;
        byDate.set(point.date, current);
        return byDate;
      }, new Map()).values()].sort((left, right) => left.date.localeCompare(right.date))
      : usablePoints;
  const first = statPoints[0];
  const last = statPoints.at(-1);
  if (isPoolShare) {
    const totalShare = poolSnapshotPoints.reduce((sum, point) => sum + point.value, 0);
    $('#stat-latest-label').textContent = 'Pools im Snapshot';
    $('#stat-latest').textContent = formatValue(poolSnapshotPoints.length);
    $('#stat-latest-date').textContent = `Stand: ${formatDate(poolSnapshotDate)}`;
    $('#stat-change-label').textContent = 'Summe der Anteile';
    $('#stat-change').textContent = `${formatValue(totalShare)} %`;
    $('#stat-change-note').textContent = 'Anteil an identifizierten Pool-Blöcken';
  } else if (isPoolHashrateShare && latestPoolHashratePoint) {
    const poolName = seriesMetadata.get(last.series)?.display_name || last.series;
    $('#stat-latest-label').textContent = 'Größter Pool am letzten Datenpunkt';
    $('#stat-latest').textContent = `${poolName}: ${formatValue(last.value)} %`;
    $('#stat-latest-date').textContent = `Stand: ${formatDate(last.date)}`;
    $('#stat-change-label').textContent = `Anteil von ${poolName}`;
    $('#stat-change').textContent = statPoints.length < 2
      ? 'Nicht berechenbar'
      : `${formatValue(last.value - first.value)} Prozentpunkte`;
    $('#stat-change-note').textContent = `Seit ${formatDate(first.date)}; gleiche Poolreihe`;
  } else {
    $('#stat-latest-label').textContent = 'Letzter Wert';
    $('#stat-latest').textContent = `${formatValue(last.value)} ${metric.unit}`;
    $('#stat-latest-date').textContent = `Stand: ${formatDate(last.date)}`;
    $('#stat-change-label').textContent = 'Änderung im Zeitraum';
    $('#stat-change-note').textContent = 'Erster bis letzter gespeicherter Wert';
    if (statPoints.length < 2) {
      $('#stat-change').textContent = 'Nicht berechenbar';
    } else if (metric.unit.startsWith('%')) {
      $('#stat-change').textContent = `${formatValue(last.value - first.value)} Prozentpunkte`;
    } else if (first.value <= 0) {
      $('#stat-change').textContent = `${formatValue(last.value - first.value)} ${metric.unit}`;
    } else {
      $('#stat-change').textContent = `${formatValue(((last.value / first.value) - 1) * 100)} %`;
    }
  }
  $('#stat-coverage').textContent = `${formatDate(conversion.converted ? usablePoints[0].date : stored.coverageStart || points[0].date)} bis ${formatDate(conversion.converted ? usablePoints.at(-1).date : stored.coverageEnd || last.date)}`;
  $('#stat-coverage-note').textContent = conversion.converted ? `${usablePoints.length} umgerechnete Werte im Zeitraum; ${conversion.missing} ohne passenden FX-Kurs` : `${stored.observationCount} gespeicherte Beobachtungen`;
  setSource(last.sourceName || stored.sourceName || `${metric.sourceHint} (Kandidat)`, last.sourceUrl || stored.sourceUrl, last.attribution || stored.attribution, last.sourceSlug || stored.sourceSlug);
  const comparisonNames = isPoolShare || isPoolHashrateShare ? [] : seriesKeys.filter((seriesKey) => seriesKey !== 'main')
    .map(seriesLabel);
  const methodText = stored.methodology?.replace(/^openbitcoin_rolling_24h\s*\|\s*/, '') || null;
  $('#chart-method').textContent = [...comparisonNames, methodText, conversion.converted ? `Historische Währungsumrechnung über ${conversion.fxIds.join(', ')}; kein zukünftiger Kurs, höchstens 7 Kalendertage Rückgriff. Gespeicherte USD-Ableitungen werden umgerechnet und nicht neu berechnet.` : null].filter(Boolean).join(' | ') || 'Methodik noch nicht hinterlegt';
}

function renderPoolShareDetails(points, seriesMetadata, snapshotDate) {
  const container = $('#chart-details');
  container.replaceChildren();
  const disclosure = document.createElement('details');
  disclosure.className = 'chart-details__disclosure';
  const summary = document.createElement('summary');
  summary.textContent = `Alle ${points.length} Pool-Anteile vom ${formatDate(snapshotDate)} anzeigen`;
  const note = document.createElement('p');
  note.className = 'chart-details__note';
  note.textContent = 'Das Diagramm hebt die zwölf größten Pools hervor. Diese Tabelle zeigt alle identifizierten Pools; nicht zugeordnete Blöcke sind ausgeschlossen.';
  const wrapper = document.createElement('div');
  wrapper.className = 'chart-details__table-wrap';
  const table = document.createElement('table');
  table.className = 'chart-details__table';
  const caption = document.createElement('caption');
  caption.textContent = 'Anteile der identifizierten Blöcke im rollierenden 7-Tage-Fenster.';
  const head = document.createElement('thead');
  const headerRow = document.createElement('tr');
  for (const label of ['Mining-Pool', 'Anteil']) {
    const cell = document.createElement('th');
    cell.scope = 'col';
    cell.textContent = label;
    headerRow.append(cell);
  }
  head.append(headerRow);
  const body = document.createElement('tbody');
  for (const point of points) {
    const row = document.createElement('tr');
    const name = document.createElement('th');
    name.scope = 'row';
    name.textContent = seriesMetadata.get(point.series)?.display_name || point.series;
    const value = document.createElement('td');
    value.textContent = `${formatValue(point.value)} %`;
    row.append(name, value);
    body.append(row);
  }
  table.append(caption, head, body);
  wrapper.append(table);
  disclosure.append(summary, note, wrapper);
  container.append(disclosure);
  container.hidden = false;
}

function makeCard(metric, overview = false) {
  const originalMetric = metric;
  const originalStored = storedMetrics.get(metric.id);
  const change = metricChange(originalMetric, originalStored);
  const displayed = convertPoints(metric, [{ date: originalStored?.latestDate, value: originalStored?.latestValue }], displayCurrency, fxSeries);
  metric = displayed.metric;
  const stored = originalStored ? { ...originalStored, latestValue: displayed.points[0]?.value } : undefined;
  const observationCount = Number(stored?.observationCount || 0);
  const hasObservations = Number.isFinite(observationCount) && observationCount > 0;
  const hasLatest = stored?.latestValue !== null && stored?.latestValue !== undefined && stored?.latestValue !== '';
  const dateLabel = stored?.latestDate
    ? `Stand ${formatDate(stored.latestDate)}`
    : hasObservations && stored?.coverageEnd
      ? `Bis ${formatDate(stored.coverageEnd)}`
      : hasObservations
        ? `${formatCardValue(observationCount)} gespeicherte Beobachtungen`
        : 'Noch keine Daten';
  const observationsLabel = metric.id === 'mining_pool_share'
    ? `${formatCardValue(observationCount)} Pools`
    : `${formatCardValue(observationCount)} Werte`;
  const card = document.createElement('button');
  card.type = 'button';
  card.dataset.metricId = metric.id;
  card.className = overview ? 'metric-card' : 'catalog-card';
  if (metric.id === 'price_usd' || metric.id === 'price_eur') card.classList.add('is-bitcoin-price');
  card.setAttribute('aria-pressed', String(metric.id === selectedId));
  card.setAttribute('aria-label', `${metric.label} auswählen. ${hasLatest ? `Letzter Wert ${formatValue(stored.latestValue)} ${metric.unit}, ${dateLabel}. ${change.label}.` : hasObservations ? `${observationsLabel}, ${dateLabel}. ${change.label}.` : 'Noch keine Daten.'}`);

  if (overview) {
    const top = document.createElement('span');
    top.className = 'metric-card__top';
    const name = document.createElement('span');
    name.textContent = metric.label;
    top.append(name);
    const value = document.createElement('strong');
    value.className = 'metric-card__value';
    value.textContent = hasLatest ? formatCardValue(stored.latestValue, metric.unit) : displayed.converted && hasObservations ? 'FX-Kurs fehlt' : hasObservations ? observationsLabel : 'Keine Daten';
    const trend = document.createElement('span');
    trend.className = `point-change point-change--${change.direction}`;
    trend.textContent = change.text;
    trend.setAttribute('aria-label', change.label);
    trend.title = change.label;
    const foot = document.createElement('span');
    foot.className = 'metric-card__foot';
    const unit = document.createElement('span');
    unit.textContent = metric.unit;
    const date = document.createElement('span');
    date.textContent = dateLabel;
    foot.append(unit, date);
    card.append(top, value, trend, foot);
  } else {
    const name = document.createElement('strong');
    name.textContent = metric.label;
    const description = document.createElement('small');
    description.textContent = metric.description;
    const meta = document.createElement('span');
    meta.className = 'catalog-card__meta';
    const unit = document.createElement('span');
    unit.textContent = metric.unit;
    const latest = document.createElement('span');
    latest.className = 'catalog-card__latest';
    latest.textContent = hasLatest ? formatCardValue(stored.latestValue, metric.unit) : displayed.converted && hasObservations ? 'FX-Kurs fehlt' : hasObservations ? observationsLabel : 'Noch keine Daten';
    const trend = document.createElement('span');
    trend.className = `point-change point-change--${change.direction}`;
    trend.textContent = change.text;
    trend.setAttribute('aria-label', change.label);
    trend.title = change.label;
    const date = document.createElement('span');
    date.textContent = dateLabel;
    meta.append(unit, latest, trend, date);
    card.append(name, description, meta);
  }
  if (!overview && metric.id === 'fear_greed_index' && hasLatest && stored?.sourceSlug === 'alternative_me_fng') {
    const attribution = document.createElement('small');
    attribution.textContent = 'Quelle: Alternative.me';
    card.append(attribution);
  }
  card.addEventListener('click', () => selectMetric(metric.id, !overview));
  return card;
}

function renderOverview() {
  const overview = $('#overview-grid');
  overview.replaceChildren();
  catalog.overviewIds.forEach((id) => {
    const metric = catalog.metrics.find((item) => item.id === id);
    if (metric) overview.append(makeCard(metric, true));
  });
}

function renderFilters() {
  const filters = $('#category-filters');
  const activeCategories = new Set(catalog.metrics.map((metric) => metric.category));
  const options = [{ id: 'all', label: 'Alle' }, ...catalog.categories.filter((category) => activeCategories.has(category.id))];
  options.forEach((category) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = category.label;
    button.dataset.category = category.id;
    button.classList.toggle('is-active', category.id === activeCategory);
    button.setAttribute('aria-pressed', String(category.id === activeCategory));
    button.addEventListener('click', () => {
      activeCategory = category.id;
      filters.querySelectorAll('button').forEach((item) => {
        const active = item.dataset.category === activeCategory;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', String(active));
      });
      renderCatalog();
    });
    filters.append(button);
  });
}

function renderCatalog() {
  const search = $('#metric-search').value.trim().toLocaleLowerCase('de');
  const groups = $('#catalog-groups');
  const coreMetricIds = new Set(catalog.coreMetricIds || []);
  groups.replaceChildren();
  let visibleCount = 0;

  catalog.categories.forEach((category) => {
    if (activeCategory !== 'all' && activeCategory !== 'core' && activeCategory !== category.id) return;
    const metrics = catalog.metrics.filter((metric) => metric.category === category.id &&
      (activeCategory !== 'core' || coreMetricIds.has(metric.id)) &&
      `${metric.label} ${metric.description} ${metric.unit}`.toLocaleLowerCase('de').includes(search));
    if (metrics.length === 0) return;
    visibleCount += metrics.length;

    const group = document.createElement('details');
    group.className = 'catalog-group';
    group.setAttribute('aria-label', category.label);
    const selectedMetric = catalog.metrics.find((metric) => metric.id === selectedId);
    group.open = search.length > 0 || category.id === selectedMetric?.category || category.id === activeCategory;
    const heading = document.createElement('summary');
    heading.className = 'catalog-group__heading';
    const title = document.createElement('h3');
    title.textContent = category.label;
    const count = document.createElement('span');
    count.textContent = `${metrics.length} Kennzahlen`;
    heading.append(title, count);
    const grid = document.createElement('div');
    grid.className = 'catalog-grid';
    metrics.forEach((metric) => grid.append(makeCard(metric, 0)));
    group.append(heading, grid);
    groups.append(group);
  });
  $('#empty-results').hidden = visibleCount !== 0;
}

function bindMobileMenu() {
  const toggle = $('.nav-toggle');
  const menu = $('#mobile-menu');
  if (!toggle || !menu) return;
  const setOpen = (open) => {
    toggle.classList.toggle('open', open);
    menu.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
  };
  toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
  menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setOpen(false)));
}

async function init() {
  bindMobileMenu();
  syncLanguageTargets();
  try {
    const response = await fetch('data/catalog.json', { cache: 'no-store' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    catalog = await response.json();
    const activeIds = new Set(catalog.activeMetricIds || catalog.metrics.map((metric) => metric.id));
    catalog.metrics = catalog.metrics.filter((metric) => activeIds.has(metric.id) && metric.id !== 'price_eur');
    catalog.coreMetricIds = catalog.metrics.map((metric) => metric.id);
    catalog.categories.forEach((category) => categoryName.set(category.id, category.label));
    const parameters = new URL(window.location.href).searchParams;
    let requestedCurrency = CURRENCIES.includes(parameters.get('currency')) ? parameters.get('currency') : 'USD';
    chartScale = parameters.get('scale') === 'log' ? 'logarithmic' : 'linear';
    cardChangeMode = parameters.get('changeMode') === 'absolute' ? 'absolute' : 'relative';
    cardChangePeriod = COMPARISON_PERIODS.includes(parameters.get('changePeriod')) ? parameters.get('changePeriod') : 'previous';
    $('#card-change-mode').value = cardChangeMode;
    $('#card-change-period').value = cardChangePeriod;
    $('#card-change-description').textContent = changeDescription(cardChangeMode, cardChangePeriod);
    $('#card-change-mode').addEventListener('change', (event) => { cardChangeMode = event.target.value; renderCardChanges(); });
    $('#card-change-period').addEventListener('change', (event) => { cardChangePeriod = event.target.value; renderCardChanges(); });
    $('#display-currency').value = displayCurrency;
    $('#display-currency').disabled = false;
    $('#display-currency').addEventListener('change', (event) => changeCurrency(event.target.value));
    const requestedId = parameters.get('metric');
    if (requestedId === 'price_eur') {
      selectedId = 'price_usd';
      if (!parameters.has('currency') || parameters.get('currency') === 'original') requestedCurrency = 'EUR';
    } else if (catalog.metrics.some((metric) => metric.id === requestedId)) selectedId = requestedId;
    $('#catalog-count').textContent = `${catalog.metrics.length} Kennzahlen`;
    renderOverview();
    renderFilters();
    renderCatalog();
    $('#metric-search').addEventListener('input', renderCatalog);
    document.querySelectorAll('[data-chart-scale]').forEach((button) => button.addEventListener('click', () => {
      if (button.disabled || currentSeries?.metricId !== selectedId) return;
      chartScale = button.dataset.chartScale;
      const selected = catalog.metrics.find((metric) => metric.id === selectedId);
      if (selected) renderChart(selected, currentSeries.stored, currentSeries.data);
    }));
    document.querySelectorAll('.range-controls button').forEach((button) => button.addEventListener('click', () => {
      activeRange = button.dataset.range;
      updateRangeButtons(true);
      const selected = catalog.metrics.find((metric) => metric.id === selectedId);
      if (selected) loadSeries(selected);
    }));
    selectMetric(selectedId);
    await loadStoredMetrics();
    await changeCurrency(requestedCurrency);
  } catch (error) {
    $('#catalog-count').textContent = 'Katalog nicht geladen';
    $('#catalog-groups').textContent = 'Der Kennzahlenkatalog konnte nicht geladen werden. Bitte die Seite über einen Webserver öffnen.';
    console.error('Bitcoin catalog:', error);
  }
}

async function loadStoredMetrics() {
  try {
    const response = await fetch('api/bitcoin/metrics.php', { cache: 'no-store' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    if (!data.success || !Array.isArray(data.metrics)) throw new Error('Ungültige Antwort');
    data.metrics.forEach((metric) => storedMetrics.set(metric.id, metric));
    const visibleIds = new Set(catalog.metrics.map((metric) => metric.id));
    const available = data.metrics.filter((metric) => visibleIds.has(metric.id) && metric.observationCount > 0).length;
    $('#data-status').textContent = available ? `${available} ${available === 1 ? 'Kennzahl' : 'Kennzahlen'} mit Daten` : 'Noch keine Messwerte';
    $('#data-status-detail').textContent = available ? 'Verläufe stammen aus gespeicherten Beobachtungen.' : 'Der Datenimport wird vorbereitet.';
    $('#catalog-note').textContent = available
      ? `${available} von ${catalog.metrics.length} Kennzahlen ${available === 1 ? 'hat' : 'haben'} gespeicherte Beobachtungen. Historische Abdeckung und Quelle unterscheiden sich je Reihe.`
      : 'Noch keine Beobachtungen importiert. Abdeckung und Quellenhinweise werden je Kennzahl angezeigt.';
    renderOverview();
    renderCatalog();
    selectMetric(selectedId);
  } catch (error) {
    console.error('Bitcoin metrics:', error);
    $('#data-status').textContent = 'Daten noch nicht verfügbar';
    $('#data-status-detail').textContent = 'Die gespeicherten Messwerte konnten nicht aus der Datenbank geladen werden.';
    $('#catalog-note').textContent = 'Die Oberfläche ist geladen; die Datenbankantwort fehlt noch.';
    showEmptyChart('Datenbank nicht erreichbar', 'Die Messwerte konnten nicht abgerufen werden. Bitte später erneut laden.');
  }
}

init();
