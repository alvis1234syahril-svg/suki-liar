const palette = ['#ffd400', '#005b96', '#a3262e', '#e7ad00', '#287fb5', '#7e1d25', '#9c9782'];
const number = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });
const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
const safe = value => Number.isFinite(Number(value)) && Number(value) >= 0 ? Number(value) : 0;
const seriesSummaryValue = (values, unit) => {
    const normalized = Array.isArray(values) ? values.map(safe) : [];
    if (!normalized.length) return 0;
    if (unit === 'score' || unit === 'akses/client') {
        const average = normalized.reduce((sum, value) => sum + value, 0) / normalized.length;
        return unit === 'score' ? Math.min(100, average) : average;
    }
    return normalized.reduce((sum, value) => sum + value, 0);
};

const formatValue = (value, unit, short = false) => {
    const numeric = safe(value);
    if (unit === 'byte') {
        if (numeric >= 1099511627776) return `${number.format(numeric / 1099511627776)} TB`;
        if (numeric >= 1073741824) return `${number.format(numeric / 1073741824)} GB`;
        if (numeric >= 1048576) return `${number.format(numeric / 1048576)} MB`;
        if (numeric >= 1024) return `${number.format(numeric / 1024)} KB`;
        return `${number.format(numeric)} B`;
    }
    if (unit === 'score') return `${number.format(numeric)}/100`;
    if (short) return compact.format(numeric);
    if (unit === 'akses/client') return `${number.format(numeric)} akses/user`;
    if (['client', 'user'].includes(unit)) return `${number.format(numeric)} user`;
    return number.format(numeric);
};

const svgNode = (name, attrs = {}) => {
    const node = document.createElementNS('http://www.w3.org/2000/svg', name);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
};

const normalize = raw => {
    const labels = Array.isArray(raw?.labels) ? raw.labels.map(value => String(value ?? '-')) : [];
    return {
        type: ['bar', 'line', 'donut'].includes(raw?.type) ? raw.type : 'bar',
        unit: String(raw?.unit ?? ''),
        labels,
        series: (Array.isArray(raw?.series) ? raw.series : []).map((item, index) => ({
            name: String(item?.name ?? `Seri ${index + 1}`),
            color: item?.color || palette[index % palette.length],
            values: labels.map((_, valueIndex) => safe(item?.values?.[valueIndex])),
        })),
    };
};

const tooltip = (chart, event, title, detail) => {
    const box = chart.querySelector('[data-chart-tooltip]');
    const bounds = chart.getBoundingClientRect();
    const targetBounds = event.currentTarget?.getBoundingClientRect?.();
    const pointerX = Number.isFinite(event.clientX)
        ? event.clientX
        : ((targetBounds?.left ?? bounds.left) + (targetBounds?.width ?? 0) / 2);
    const pointerY = Number.isFinite(event.clientY)
        ? event.clientY
        : (targetBounds?.top ?? bounds.top);
    box.querySelector('[data-tooltip-title]').textContent = title;
    box.querySelector('[data-tooltip-detail]').textContent = detail;
    box.style.left = `${Math.min(Math.max(8, bounds.width - 190), Math.max(8, pointerX - bounds.left + 10))}px`;
    box.style.top = `${Math.max(8, pointerY - bounds.top - 58)}px`;
    box.hidden = false;
};

const bind = (mark, chart, label, series, value) => {
    const detail = `${series}: ${formatValue(value, chart.dataset.chartUnit)}`;
    ['mouseenter', 'mousemove', 'focus'].forEach(name => mark.addEventListener(name, event => tooltip(chart, event, label, detail)));
    ['mouseleave', 'blur'].forEach(name => mark.addEventListener(name, () => chart.querySelector('[data-chart-tooltip]').hidden = true));
    mark.setAttribute('tabindex', '0');
    mark.setAttribute('role', 'img');
    mark.setAttribute('aria-label', `${label}, ${detail}`);
};

const renderBars = (chart, svg, config, active) => {
    const width = 720;
    const series = config.series.filter((_, index) => active.has(index));
    const rowHeight = Math.max(34, 18 + series.length * 12);
    const height = Math.max(220, 50 + config.labels.length * rowHeight);
    const left = config.labels.some(label => label.length > 14) ? 145 : 112;
    const plot = width - left - 20;
    const max = Math.max(1, ...series.flatMap(item => item.values));
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);

    config.labels.forEach((label, labelIndex) => {
        const y = 25 + labelIndex * rowHeight;
        const text = svgNode('text', { x: left - 9, y: y + 12, fill: '#b7b29f', 'font-size': 11, 'text-anchor': 'end' });
        text.textContent = label.length > 20 ? `${label.slice(0, 18)}…` : label;
        const full = svgNode('title');
        full.textContent = label;
        text.append(full);
        svg.append(text);
        const barHeight = Math.min(11, 22 / Math.max(1, series.length));
        series.forEach((item, index) => {
            const value = item.values[labelIndex] ?? 0;
            const bar = svgNode('rect', {
                x: left, y: y + index * (barHeight + 2),
                width: Math.max(value > 0 ? 2 : 0, value / max * plot),
                height: barHeight, rx: 3, fill: item.color,
            });
            bind(bar, chart, label, item.name, value);
            svg.append(bar);
        });
    });
};

const renderLine = (chart, svg, config, active) => {
    const width = 720, height = 270, left = 52, right = 18, top = 20, bottom = 42;
    const plotWidth = width - left - right, plotHeight = height - top - bottom;
    const series = config.series.filter((_, index) => active.has(index));
    const max = Math.max(1, ...series.flatMap(item => item.values));
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);

    [0, .25, .5, .75, 1].forEach(ratio => {
        const y = top + (1 - ratio) * plotHeight;
        svg.append(svgNode('line', { x1: left, y1: y, x2: width - right, y2: y, stroke: '#302a12' }));
        const text = svgNode('text', { x: left - 7, y: y + 4, fill: '#999580', 'font-size': 10, 'text-anchor': 'end' });
        text.textContent = formatValue(max * ratio, config.unit, true);
        svg.append(text);
    });
    config.labels.forEach((label, index) => {
        if (index % Math.max(1, Math.ceil(config.labels.length / 6))) return;
        const x = config.labels.length === 1 ? left + plotWidth / 2 : left + index / (config.labels.length - 1) * plotWidth;
        const text = svgNode('text', { x, y: height - 14, fill: '#999580', 'font-size': 10, 'text-anchor': 'middle' });
        text.textContent = label.length > 12 ? `${label.slice(0, 10)}…` : label;
        svg.append(text);
    });
    series.forEach(item => {
        const points = item.values.map((value, index) => ({
            x: config.labels.length === 1 ? left + plotWidth / 2 : left + index / Math.max(1, config.labels.length - 1) * plotWidth,
            y: top + (1 - value / max) * plotHeight, value,
        }));
        svg.append(svgNode('path', {
            d: points.map((point, index) => `${index ? 'L' : 'M'} ${point.x} ${point.y}`).join(' '),
            fill: 'none', stroke: item.color, 'stroke-width': 3, 'stroke-linejoin': 'round',
        }));
        points.forEach((point, index) => {
            const mark = svgNode('circle', { cx: point.x, cy: point.y, r: 4.5, fill: item.color, stroke: '#100e05', 'stroke-width': 2 });
            bind(mark, chart, config.labels[index], item.name, point.value);
            svg.append(mark);
        });
    });
};

const renderDonut = (chart, svg, config, active) => {
    const width = 440, height = 250, cx = 220, cy = 119, radius = 78, stroke = 28;
    const values = config.series.map(item => item.values[0] ?? 0);
    const total = values.reduce((sum, value, index) => sum + (active.has(index) ? value : 0), 0);
    const circumference = 2 * Math.PI * radius;
    let offset = 0;
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);

    svg.append(svgNode('circle', {
        cx, cy, r: radius, fill: 'none', stroke: '#302a12', 'stroke-width': stroke,
    }));

    const segments = svgNode('g', { transform: `rotate(-90 ${cx} ${cy})` });
    config.series.forEach((item, index) => {
        if (!active.has(index) || values[index] <= 0) return;
        const length = values[index] / total * circumference;
        const mark = svgNode('circle', {
            cx, cy, r: radius, fill: 'none', stroke: item.color, 'stroke-width': stroke,
            'stroke-dasharray': `${length} ${circumference - length}`, 'stroke-dashoffset': -offset,
            'stroke-linecap': length < circumference * .985 ? 'round' : 'butt',
            class: 'chart-donut-segment',
        });
        bind(mark, chart, item.name, 'Jumlah', values[index]);
        segments.append(mark);
        offset += length;
    });
    svg.append(segments);

    svg.append(svgNode('circle', {
        cx, cy, r: radius - stroke / 2 - 6, fill: '#110f06', stroke: '#302a12', 'stroke-width': 2,
    }));

    const totalNode = svgNode('text', { x: cx, y: cy, fill: '#fffdf2', 'font-size': 25, 'font-weight': 700, 'text-anchor': 'middle' });
    totalNode.textContent = number.format(total);
    svg.append(totalNode);
    const unit = svgNode('text', { x: cx, y: cy + 20, fill: '#999580', 'font-size': 11, 'text-anchor': 'middle' });
    unit.textContent = config.unit || 'total';
    svg.append(unit);
};

const initialize = chart => {
    const empty = chart.querySelector('[data-chart-empty]');
    try {
        const baseConfig = normalize(JSON.parse(chart.querySelector('script[type="application/json"]')?.textContent || '{}'));
        let config = baseConfig;
        const svg = chart.querySelector('svg');
        const legend = chart.querySelector('[data-chart-legend]');
        const fallback = chart.querySelector('[data-chart-fallback]');
        const active = new Set(config.series.map((_, index) => index));
        chart.dataset.chartUnit = config.unit;

        if (!config.labels.length || !config.series.some(item => item.values.some(value => value > 0))) {
            svg.hidden = true;
            legend.hidden = true;
            fallback.hidden = true;
            empty.hidden = false;
            return;
        }

        config.series.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-[11px] font-bold text-slate-600';
            button.setAttribute('aria-pressed', 'true');
            const dot = document.createElement('span');
            dot.className = 'h-2 w-2 rounded-full';
            dot.style.background = item.color;
            button.append(dot, document.createTextNode(item.name));
            button.addEventListener('click', () => {
                if (active.has(index) && active.size > 1) active.delete(index); else active.add(index);
                button.classList.toggle('opacity-40', !active.has(index));
                button.setAttribute('aria-pressed', active.has(index) ? 'true' : 'false');
                render();
            });
            legend.append(button);

            const itemTotal = seriesSummaryValue(item.values, config.unit);
            if (config.type === 'donut') {
                fallback.className = 'mt-4 grid gap-2 sm:grid-cols-2';
                const totalAll = config.series.reduce(
                    (sum, current) => sum + current.values.reduce((seriesSum, value) => seriesSum + value, 0),
                    0
                );
                const summary = document.createElement('button');
                summary.type = 'button';
                summary.className = 'group rounded-xl border border-slate-200 bg-slate-50/80 p-3 text-left transition hover:-translate-y-0.5 hover:border-slate-300 hover:bg-white hover:shadow-md';
                const heading = document.createElement('span');
                heading.className = 'flex items-center justify-between gap-3 text-[11px] font-bold text-slate-600';
                const name = document.createElement('span');
                name.className = 'flex min-w-0 items-center gap-2';
                const summaryDot = dot.cloneNode();
                const summaryName = document.createElement('span');
                summaryName.className = 'truncate';
                summaryName.textContent = item.name;
                name.append(summaryDot, summaryName);
                const percentage = document.createElement('strong');
                percentage.className = 'text-slate-900';
                percentage.textContent = `${number.format(totalAll > 0 ? itemTotal / totalAll * 100 : 0)}%`;
                heading.append(name, percentage);
                const value = document.createElement('strong');
                value.className = 'mt-2 block text-base font-extrabold text-slate-900';
                value.textContent = formatValue(itemTotal, config.unit);
                const track = document.createElement('span');
                track.className = 'mt-2 block h-1.5 overflow-hidden rounded-full bg-slate-200';
                const fill = document.createElement('span');
                fill.className = 'block h-full rounded-full transition-all';
                fill.style.background = item.color;
                fill.style.width = `${totalAll > 0 ? Math.max(2, itemTotal / totalAll * 100) : 0}%`;
                track.append(fill);
                summary.append(heading, value, track);
                summary.addEventListener('click', () => {
                    button.click();
                    summary.classList.toggle('opacity-40', !active.has(index));
                });
                fallback.append(summary);
            } else {
                const value = document.createElement('span');
                value.className = 'rounded-lg bg-slate-50 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600';
                value.dataset.chartSeriesIndex = String(index);
                value.textContent = `${['score', 'akses/client'].includes(config.unit) ? 'Rata-rata ' : ''}${item.name}: ${formatValue(itemTotal, config.unit)}`;
                fallback.append(value);
            }
        });

        const render = () => {
            svg.replaceChildren();
            if (config.type === 'donut') renderDonut(chart, svg, config, active);
            else if (config.type === 'line') renderLine(chart, svg, config, active);
            else renderBars(chart, svg, config, active);
        };
        render();

        chart.addEventListener('traffic-chart:filter-labels', event => {
            const visibleLabels = new Set(
                Array.isArray(event.detail?.visibleLabels)
                    ? event.detail.visibleLabels.map(label => String(label))
                    : baseConfig.labels
            );
            const visibleIndexes = baseConfig.labels
                .map((label, index) => visibleLabels.has(label) ? index : -1)
                .filter(index => index >= 0);

            config = {
                ...baseConfig,
                labels: visibleIndexes.map(index => baseConfig.labels[index]),
                series: baseConfig.series.map(item => ({
                    ...item,
                    values: visibleIndexes.map(index => item.values[index]),
                })),
            };

            if (!config.labels.length) {
                svg.hidden = true;
                legend.hidden = true;
                fallback.hidden = true;
                empty.hidden = false;
                empty.textContent = 'Semua unit sedang disembunyikan. Aktifkan tombol mata pada unit yang ingin dibandingkan.';
                return;
            }

            empty.hidden = true;
            svg.hidden = false;
            legend.hidden = false;
            fallback.hidden = false;
            fallback.querySelectorAll('[data-chart-series-index]').forEach(node => {
                const index = Number(node.dataset.chartSeriesIndex);
                const item = config.series[index];
                const itemTotal = seriesSummaryValue(item?.values, config.unit);
                node.textContent = `${['score', 'akses/client'].includes(config.unit) ? 'Rata-rata ' : ''}${item?.name ?? ''}: ${formatValue(itemTotal, config.unit)}`;
            });
            render();
        });

        let width = chart.clientWidth;
        new ResizeObserver(() => {
            if (Math.abs(chart.clientWidth - width) < 8) return;
            width = chart.clientWidth;
            requestAnimationFrame(render);
        }).observe(chart);
        document.addEventListener('visibilitychange', () => !document.hidden && requestAnimationFrame(render));
    } catch {
        empty.hidden = false;
        empty.textContent = 'Grafik belum dapat ditampilkan.';
    }
};

document.querySelectorAll('[data-traffic-chart]').forEach(initialize);
