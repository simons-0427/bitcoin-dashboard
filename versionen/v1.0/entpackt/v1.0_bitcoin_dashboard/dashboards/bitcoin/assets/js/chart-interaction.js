function renderChartTooltip({ chart, tooltip }) {
  const area = document.querySelector('#chart-area');
  let element = area.querySelector('#chart-tooltip');
  if (!element) {
    element = document.createElement('div');
    element.id = 'chart-tooltip';
    element.className = 'chart-tooltip';
    element.setAttribute('role', 'status');
    element.hidden = true;
    area.append(element);
  }
  if (!tooltip || tooltip.opacity === 0 || !tooltip.dataPoints?.length) {
    element.hidden = true;
    return;
  }
  element.replaceChildren();
  const title = document.createElement('strong');
  title.textContent = (tooltip.title || []).join(' ');
  element.append(title);
  for (const item of tooltip.dataPoints) {
    const body = tooltip.body?.[tooltip.dataPoints.indexOf(item)];
    const line = document.createElement('span');
    line.textContent = [...(body?.before || []), ...(body?.lines || []), ...(body?.after || [])].join(' | ');
    element.append(line);
  }
  for (const text of tooltip.footer || []) {
    const line = document.createElement('small');
    line.textContent = text;
    element.append(line);
  }
  element.hidden = false;
  const canvasRect = chart.canvas.getBoundingClientRect();
  const areaRect = area.getBoundingClientRect();
  const caretX = canvasRect.left - areaRect.left + tooltip.caretX;
  const caretY = canvasRect.top - areaRect.top + tooltip.caretY;
  const left = Math.min(Math.max(0, caretX + 14), Math.max(0, area.clientWidth - element.offsetWidth));
  const top = Math.min(Math.max(0, caretY - element.offsetHeight - 12), Math.max(0, area.clientHeight - element.offsetHeight));
  element.style.left = `${left}px`;
  element.style.top = `${top}px`;
}

const bitcoinCrosshair = {
  id: 'bitcoinCrosshair',
  afterDatasetsDraw(chart) {
    if (chart.config.type !== 'line') return;
    const active = chart.tooltip?.getActiveElements() || [];
    if (!active.length || chart.tooltip.opacity === 0) return;
    const x = active[0].element.x;
    const { ctx, chartArea } = chart;
    ctx.save();
    ctx.strokeStyle = 'rgba(203, 208, 223, .5)';
    ctx.lineWidth = 1;
    ctx.setLineDash([3, 3]);
    ctx.beginPath();
    ctx.moveTo(x, chartArea.top);
    ctx.lineTo(x, chartArea.bottom);
    ctx.stroke();
    ctx.restore();
  },
};

function bindChartKeyboard(chart) {
  let selected = -1;
  chart.canvas.onkeydown = (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Escape') {
      chart.setActiveElements([]);
      chart.tooltip.setActiveElements([], { x: 0, y: 0 });
      chart.update('none');
      return;
    }
    const available = chart.data.datasets[0].data.map((value, index) => value === null ? null : index).filter((index) => index !== null);
    if (!available.length) return;
    if (event.key === 'Home') selected = 0;
    else if (event.key === 'End') selected = available.length - 1;
    else selected = Math.min(available.length - 1, Math.max(0, (selected < 0 ? available.length - 1 : selected) + (event.key === 'ArrowRight' ? 1 : -1)));
    const index = available[selected];
    const point = chart.getDatasetMeta(0).data[index];
    const active = chart.data.datasets.map((dataset, datasetIndex) => ({ datasetIndex, index }))
      .filter(({ datasetIndex }) => chart.data.datasets[datasetIndex].data[index] !== null);
    chart.setActiveElements(active);
    chart.tooltip.setActiveElements(active, { x: point.x, y: point.y });
    chart.update('none');
  };
}

export { renderChartTooltip, bitcoinCrosshair, bindChartKeyboard };
