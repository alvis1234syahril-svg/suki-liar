import '../../css/topology.css';
import {
    TopologyTree,
    assetIdentifier,
    assetType,
    escapeHtml,
    normalizeText,
    relationValue,
} from './tree';
import { TreeZoom } from './zoom';

const root = document.querySelector('[data-topology-app]');

if (root) {
    const one = selector => root.querySelector(selector);
    const all = selector => [...root.querySelectorAll(selector)];
    const field = (source, ...names) => {
        for (const name of names) {
            const candidate = source?.[name];
            if (candidate !== null && candidate !== undefined && String(candidate).trim() !== '') return candidate;
        }
        return '';
    };
    const asArray = value => Array.isArray(value) ? value : [];
    const emptyPayload = () => ({
        locations: [],
        activeLocationId: '',
        location: null,
        metrics: {},
        assets: [],
        topology: { nodes: [], edges: [], mappings: [], summary: {} },
        traffic: {
            available: false,
            analysis: null,
            summary: {},
            unmappedDevices: [],
            ambiguousDevices: [],
        },
        history: [],
    });

    const elements = {
        status: one('[data-page-status]'),
        statusText: one('[data-page-status-text]'),
        retry: one('[data-retry]'),
        locationTitle: one('[data-location-title]'),
        locationSummary: one('[data-location-summary]'),
        locationSelect: one('[data-location-select]'),
        metrics: one('[data-metrics]'),
        trafficPanel: one('[data-traffic-panel]'),
        trafficDescription: one('[data-traffic-description]'),
        trafficContent: one('[data-traffic-content]'),
        trafficAnalysisLink: one('[data-traffic-analysis-link]'),
        tabs: all('[data-tab]'),
        panels: all('[data-tab-panel]'),
        treeCanvas: one('[data-tree-canvas]'),
        treeViewport: one('[data-tree-viewport]'),
        treeState: one('[data-tree-state]'),
        treeSummary: one('[data-tree-summary]'),
        treeSearch: one('[data-tree-search]'),
        treeAnnouncer: one('[data-tree-announcer]'),
        zoomOut: one('[data-zoom-out]'),
        zoomIn: one('[data-zoom-in]'),
        zoomReset: one('[data-zoom-reset]'),
        zoomFit: one('[data-zoom-fit]'),
        zoomValue: one('[data-zoom-value]'),
        inspector: one('[data-inspector]'),
        masterSearch: one('[data-master-search]'),
        masterType: one('[data-master-type-filter]'),
        masterRelation: one('[data-master-relation-filter]'),
        masterCount: one('[data-master-count]'),
        masterBody: one('[data-master-body]'),
        masterEmpty: one('[data-master-empty]'),
        historySearch: one('[data-history-search]'),
        historyCount: one('[data-history-count]'),
        historyList: one('[data-history-list]'),
        historyEmpty: one('[data-history-empty]'),
        exportLink: one('[data-export-link]'),
        locationDialog: one('[data-location-dialog]'),
        locationForm: one('[data-location-form]'),
        locationError: one('[data-location-error]'),
        locationSubmit: one('[data-location-submit]'),
        importDialog: one('[data-import-dialog]'),
        importForm: one('[data-import-form]'),
        importLocation: one('[data-import-location]'),
        importError: one('[data-import-error]'),
        importSubmit: one('[data-import-submit]'),
        portDialog: one('[data-port-dialog]'),
        portForm: one('[data-port-form]'),
        portSummary: one('[data-port-summary]'),
        portTarget: one('[data-port-target]'),
        portAccessPoint: one('[data-port-access-point]'),
        portError: one('[data-port-error]'),
        portSubmit: one('[data-port-submit]'),
        assetDialog: one('[data-asset-dialog]'),
        assetForm: one('[data-asset-form]'),
        assetDialogTitle: one('[data-asset-dialog-title]'),
        assetDialogSummary: one('[data-asset-dialog-summary]'),
        assetError: one('[data-asset-error]'),
        assetSubmit: one('[data-asset-submit]'),
        mutationLocation: one('[data-mutation-location]'),
        toast: one('[data-toast]'),
    };

    const state = {
        payload: emptyPayload(),
        activeLocationId: '',
        selectedNode: null,
        activeTab: 'explorer',
        masterQuery: '',
        masterType: '',
        masterRelation: '',
        historyQuery: '',
        requestSequence: 0,
        toastTimer: 0,
    };

    class RequestError extends Error {
        constructor(message, response, payload) {
            super(message);
            this.name = 'RequestError';
            this.response = response;
            this.payload = payload;
        }
    }

    const endpointUrl = (endpoint, query = {}) => {
        const url = new URL(endpoint, window.location.href);
        if (url.origin !== window.location.origin) throw new Error('Endpoint topologi harus menggunakan origin yang sama.');
        Object.entries(query).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value));
        });
        return url;
    };

    async function fetchJson(endpoint, { method = 'GET', body, query, signal } = {}) {
        const url = endpointUrl(endpoint, query);
        const isFormData = body instanceof FormData;
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (method !== 'GET') {
            if (!isFormData) headers['Content-Type'] = 'application/json';
            headers['X-CSRF-TOKEN'] = root.dataset.csrfToken || '';
        }
        const response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : (isFormData ? body : JSON.stringify(body)),
            signal,
        });
        let payload = null;
        try {
            payload = await response.json();
        } catch {
            payload = null;
        }
        if (!response.ok) {
            const message = field(payload, 'message', 'error') || `Permintaan gagal (${response.status}).`;
            throw new RequestError(String(message), response, payload);
        }
        return payload ?? {};
    }

    const zoom = new TreeZoom({
        viewport: elements.treeViewport,
        canvas: elements.treeCanvas,
        valueElement: elements.zoomValue,
        outButton: elements.zoomOut,
        inButton: elements.zoomIn,
        resetButton: elements.zoomReset,
        fitButton: elements.zoomFit,
        announcer: elements.treeAnnouncer,
    });

    const tree = new TopologyTree({
        canvas: elements.treeCanvas,
        viewport: elements.treeViewport,
        stateElement: elements.treeState,
        summaryElement: elements.treeSummary,
        searchInput: elements.treeSearch,
        announcer: elements.treeAnnouncer,
        onSelect: node => {
            state.selectedNode = node;
            renderInspector();
        },
        onPortSelect: (node, portName) => openPortAssignment(node, portName),
        onRendered: ({ selectedId }) => zoom.afterTreeRender(selectedId),
    });

    const locationId = location => String(field(location, 'slug', 'id', 'uuid', 'code'));
    const locationRecordId = location => field(
        location,
        'recordId',
        'record_id',
        'organizationalUnitId',
        'organizational_unit_id',
    ) || (/^\d+$/.test(String(location?.id ?? '')) ? location.id : '');
    const locationName = location => String(field(location, 'name', 'label', 'site', 'code') || 'Lokasi tanpa nama');
    const activeLocation = () => state.payload.location
        ?? state.payload.locations.find(location => locationId(location) === state.activeLocationId)
        ?? null;
    const assetName = asset => String(field(asset, 'display_name', 'displayName', 'name', 'model', 'asset_id', 'assetId', 'id') || 'Aset tanpa nama');
    const assetLocation = asset => String(field(asset, 'location', 'location_detail', 'locationDetail', 'site', 'site_name') || 'Tidak tercatat');
    const assetModel = asset => [field(asset, 'brand', 'manufacturer'), field(asset, 'model', 'device_model')].filter(Boolean).join(' · ') || 'Tidak tercatat';
    const assetIp = asset => String(field(asset, 'ip_address', 'ipAddress', 'management_ip') || 'Tidak tercatat');
    const canUpdateAsset = asset => {
        const lifecycle = normalizeText(field(asset, 'lifecycle_status', 'lifecycleStatus'));
        const permission = field(asset, 'can_update', 'canUpdate');
        return lifecycle !== 'replaced' && (permission === '' || Boolean(permission));
    };

    const relationLabel = relation => ({
        verified: 'Parent cocok',
        lld: 'Sesuai LLD',
        inferred: 'Parent terduga',
        unconnected: 'Belum terhubung',
        conceptual: 'Konseptual',
        gap: 'Tahap dilewati',
    }[relationValue({ relation })] ?? 'Belum diverifikasi');

    const titleFromKey = key => String(key)
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, character => character.toLocaleUpperCase('id'));

    const formatNumber = number => new Intl.NumberFormat('id-ID').format(Number(number) || 0);

    const formatBytes = bytes => {
        const amount = Number(bytes) || 0;
        if (amount < 1024) return `${formatNumber(amount)} B`;
        const units = ['KB', 'MB', 'GB', 'TB'];
        let value = amount / 1024;
        let unit = units[0];
        for (let index = 1; index < units.length && value >= 1024; index += 1) {
            value /= 1024;
            unit = units[index];
        }
        return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: value >= 10 ? 1 : 2 }).format(value)} ${unit}`;
    };

    function formatDateTime(dateValue) {
        if (!dateValue) return 'Waktu tidak tercatat';
        const date = new Date(dateValue);
        if (Number.isNaN(date.getTime())) return String(dateValue);
        return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }

    const safeSameOriginHref = href => {
        if (!href) return '';
        try {
            const url = new URL(String(href), window.location.href);
            if (url.origin !== window.location.origin) return '';
            return `${url.pathname}${url.search}${url.hash}`;
        } catch {
            return '';
        }
    };

    const trafficObservation = asset => asset?.trafficObservation ?? asset?.traffic_observation ?? null;
    const observationStatus = observation => normalizeText(field(observation, 'matchStatus', 'match_status'));
    const observationIsVisible = observation => Boolean(observation)
        && field(observation, 'observed') !== false
        && ['exact', 'ambiguous'].includes(observationStatus(observation));
    const observationLabel = observation => observationStatus(observation) === 'ambiguous'
        ? 'Cocok ke beberapa aset'
        : 'Terlihat di log';

    function trafficBadgeMarkup(observation, { showTime = true } = {}) {
        if (!state.payload.traffic.available) {
            return '<span class="at-log-status is-empty"><strong>Belum ada log</strong><small>Belum dianalisis</small></span>';
        }
        if (!observationIsVisible(observation)) {
            return '<span class="at-log-status is-empty"><strong>Belum terlihat</strong><small>Di log terbaru</small></span>';
        }
        const ambiguous = observationStatus(observation) === 'ambiguous';
        const lastSeen = field(observation, 'lastSeenAt', 'last_seen_at');
        const candidates = Number(field(observation, 'candidateCount', 'candidate_count')) || 0;
        const detail = ambiguous && candidates > 0
            ? `${formatNumber(candidates)} aset memiliki IP yang sama`
            : showTime
                ? formatDateTime(lastSeen)
                : `${formatNumber(field(observation, 'totalAccess', 'total_access'))} akses`;
        return `<span class="at-log-status ${ambiguous ? 'is-ambiguous' : 'is-observed'}"><strong>${observationLabel(observation)}</strong><small>${escapeHtml(detail)}</small></span>`;
    }

    function metricItems() {
        const source = state.payload.metrics;
        if (Array.isArray(source)) {
            return source.slice(0, 8).map((metric, index) => ({
                key: String(field(metric, 'key', 'id') || `metric-${index}`),
                label: String(field(metric, 'label', 'name') || `Metrik ${index + 1}`),
                value: field(metric, 'value', 'count', 'total'),
                detail: String(field(metric, 'detail', 'description', 'note')),
                tone: String(field(metric, 'tone', 'color') || ''),
            }));
        }
        if (source && typeof source === 'object') {
            const labelMap = {
                total: 'Total aset',
                total_assets: 'Total aset',
                active: 'Aset aktif',
                firewall: 'Firewall',
                firewalls: 'Firewall',
                routers: 'Router',
                router: 'Router',
                switches: 'Switch LAN',
                switch_lan: 'Switch LAN',
                access_points: 'Access Point',
                access_point: 'Access Point',
                accessPoints: 'Access Point',
                lanPorts: 'Port LAN tercatat',
                lan_ports: 'Port LAN tercatat',
                problemPorts: 'Port perlu diperiksa',
                problem_ports: 'Port perlu diperiksa',
                mapped: 'Terhubung',
                mapped_assets: 'Terhubung',
                history: 'Perubahan',
            };
            const scalars = Object.entries(source).filter(([, metric]) => ['string', 'number'].includes(typeof metric));
            if (scalars.length) return scalars.slice(0, 8).map(([key, metric]) => ({ key, label: labelMap[key] ?? titleFromKey(key), value: metric, detail: '', tone: key }));
        }
        const byType = state.payload.assets.reduce((counts, asset) => {
            const type = assetType(asset);
            counts[type] = (counts[type] ?? 0) + 1;
            return counts;
        }, {});
        return [
            { key: 'total', label: 'Total aset', value: state.payload.assets.length, detail: 'Lokasi aktif' },
            { key: 'firewall', label: 'Firewall', value: byType.Firewall ?? 0, detail: '' },
            { key: 'router', label: 'Router', value: byType.Router ?? 0, detail: '' },
            { key: 'switch', label: 'Switch LAN', value: byType['Switch LAN'] ?? 0, detail: '' },
            { key: 'access-point', label: 'Access Point', value: byType['Access Point'] ?? 0, detail: '' },
        ];
    }

    function renderMetrics() {
        const items = metricItems();
        elements.metrics.innerHTML = items.map(metric => `
            <article class="at-metric" data-metric="${escapeHtml(metric.key)}">
                <span>${escapeHtml(metric.label)}</span>
                <strong>${escapeHtml(formatNumber(metric.value))}</strong>
                <small>${escapeHtml(metric.detail || 'Data lokasi aktif')}</small>
            </article>
        `).join('');
    }

    function trafficDeviceList(devices, emptyMessage, tone) {
        if (!devices.length) {
            return `<div class="at-traffic-list-empty">${escapeHtml(emptyMessage)}</div>`;
        }
        const visibleDevices = devices.slice(0, 8);
        const remainder = devices.length - visibleDevices.length;
        return `<ul class="at-traffic-device-list">${visibleDevices.map(device => {
            const ip = String(field(device, 'ip', 'sourceIp', 'source_ip') || 'IP tidak tercatat');
            const identity = String(field(device, 'identity', 'name', 'clientName', 'client_name') || 'Identitas belum dikenal');
            const lastSeen = field(device, 'lastSeen', 'lastSeenAt', 'last_seen', 'last_seen_at');
            const totalAccess = field(device, 'totalAccess', 'total_access', 'events');
            const totalBytes = field(device, 'totalBytes', 'total_bytes');
            const candidateCount = Number(field(device, 'candidateCount', 'candidate_count')) || 0;
            const metadata = [
                `${formatNumber(totalAccess)} akses`,
                formatBytes(totalBytes),
                candidateCount > 0 ? `${formatNumber(candidateCount)} kandidat aset` : '',
            ].filter(Boolean).join(' · ');
            return `
                <li>
                    <span class="at-traffic-device-dot is-${escapeHtml(tone)}" aria-hidden="true"></span>
                    <span class="at-traffic-device-copy">
                        <strong>${escapeHtml(identity)}</strong>
                        <small>${escapeHtml(ip)} · ${escapeHtml(metadata)}</small>
                    </span>
                    <time datetime="${escapeHtml(lastSeen)}">${escapeHtml(formatDateTime(lastSeen))}</time>
                </li>`;
        }).join('')}</ul>${remainder > 0 ? `<p class="at-traffic-list-more">Menampilkan ${formatNumber(visibleDevices.length)} dari ${formatNumber(devices.length)} perangkat</p>` : ''}`;
    }

    function renderTraffic() {
        const traffic = state.payload.traffic;
        const analysis = traffic.analysis;
        const analysisHref = safeSameOriginHref(field(analysis, 'url', 'analysisUrl', 'analysis_url'));
        const analysisLabel = String(field(analysis, 'label', 'name') || 'Analisis log terbaru');
        const completedAt = field(analysis, 'completedAt', 'completed_at');

        elements.trafficAnalysisLink.hidden = !analysisHref;
        if (analysisHref) elements.trafficAnalysisLink.href = analysisHref;
        else elements.trafficAnalysisLink.removeAttribute('href');

        if (!traffic.available) {
            elements.trafficDescription.textContent = analysis
                ? `${analysisLabel} belum menghasilkan korelasi perangkat.`
                : 'Belum ada hasil analisis log untuk lokasi aktif.';
            elements.trafficContent.innerHTML = `
                <div class="at-traffic-empty">
                    <span class="at-traffic-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 5h14v14H5zM8 9h8M8 13h5"/><path d="m15.5 17 2 2 3.5-4"/></svg></span>
                    <div>
                        <strong>Belum ada korelasi log</strong>
                        <p>Upload dan selesaikan analisis log pada lokasi ini. Sistem hanya memberi status deteksi; parent dan jalur fisik tidak diubah otomatis.</p>
                    </div>
                </div>`;
            return;
        }

        const summary = traffic.summary ?? {};
        const unmappedDevices = asArray(traffic.unmappedDevices ?? traffic.unmapped_devices);
        const ambiguousDevices = asArray(traffic.ambiguousDevices ?? traffic.ambiguous_devices);
        const summaryItems = [
            ['Identitas teramati', field(summary, 'observedIdentities', 'observed_identities'), 'Semua sumber pada log'],
            ['Cocok ke aset', field(summary, 'matchedIdentities', 'matched_identities'), `${formatNumber(field(summary, 'matchedAssets', 'matched_assets'))} aset berbeda`],
            ['Cocok ke beberapa aset', field(summary, 'ambiguousIdentities', 'ambiguous_identities'), 'Perlu memilih aset yang benar'],
            ['Belum cocok dengan aset', field(summary, 'unmappedIdentities', 'unmapped_identities'), 'IP log tidak ditemukan di database'],
            ['Total aktivitas', field(summary, 'totalEvents', 'total_events'), formatBytes(field(summary, 'totalBytes', 'total_bytes'))],
        ];

        elements.trafficDescription.textContent = `${analysisLabel}${completedAt ? ` · selesai ${formatDateTime(completedAt)}` : ''}`;
        elements.trafficContent.innerHTML = `
            <div class="at-traffic-summary" aria-label="Ringkasan korelasi log">
                ${summaryItems.map(([label, value, detail]) => `
                    <article>
                        <span>${escapeHtml(label)}</span>
                        <strong>${escapeHtml(formatNumber(value))}</strong>
                        <small>${escapeHtml(detail)}</small>
                    </article>`).join('')}
            </div>
            <div class="at-traffic-detail-grid">
                <section aria-labelledby="at-unmapped-heading">
                    <div class="at-traffic-list-heading">
                        <div><span class="at-traffic-device-dot is-unmapped" aria-hidden="true"></span><h3 id="at-unmapped-heading">Belum cocok dengan aset</h3></div>
                        <strong>${formatNumber(unmappedDevices.length)}</strong>
                    </div>
                    <p class="at-traffic-list-description">IP berikut muncul pada log, tetapi belum ditemukan pada database aset di lokasi ini. Data tersebut tidak mengubah jalur topologi.</p>
                    ${trafficDeviceList(unmappedDevices, 'Semua IP dari log sudah cocok dengan aset pada lokasi ini.', 'unmapped')}
                </section>
                <section aria-labelledby="at-ambiguous-heading">
                    <div class="at-traffic-list-heading">
                        <div><span class="at-traffic-device-dot is-ambiguous" aria-hidden="true"></span><h3 id="at-ambiguous-heading">Cocok ke beberapa aset</h3></div>
                        <strong>${formatNumber(ambiguousDevices.length)}</strong>
                    </div>
                    <p class="at-traffic-list-description">Satu IP ditemukan pada lebih dari satu aset. Pilih atau perbaiki data aset yang benar agar sistem tidak salah menentukan perangkat.</p>
                    ${trafficDeviceList(ambiguousDevices, 'Tidak ada IP yang tercatat pada beberapa aset.', 'ambiguous')}
                </section>
            </div>`;
    }

    function renderLocationContext() {
        const locations = state.payload.locations;
        const current = activeLocation();
        elements.locationSelect.innerHTML = locations.length
            ? locations.map(location => `<option value="${escapeHtml(locationId(location))}">${escapeHtml(locationName(location))}</option>`).join('')
            : '<option value="">Belum ada lokasi</option>';
        elements.locationSelect.disabled = !locations.length;
        elements.locationSelect.value = state.activeLocationId;
        elements.locationTitle.textContent = current ? locationName(current) : 'Lokasi belum tersedia';
        const edgeCount = asArray(state.payload.topology?.edges).length || asArray(state.payload.topology?.links).length;
        elements.locationSummary.textContent = current
            ? `${formatNumber(state.payload.assets.length)} aset · ${formatNumber(edgeCount)} koneksi topologi`
            : 'Tambahkan lokasi untuk mulai membangun topologi.';

        const exportUrl = endpointUrl(root.dataset.exportEndpoint, state.activeLocationId ? { location: state.activeLocationId } : {});
        elements.exportLink.href = exportUrl.href;
        elements.exportLink.toggleAttribute('aria-disabled', !state.activeLocationId);
    }

    function populateMasterTypes() {
        const current = elements.masterType.value;
        const types = [...new Set(state.payload.assets.map(assetType).filter(Boolean))].sort((left, right) => left.localeCompare(right, 'id'));
        elements.masterType.innerHTML = `<option value="">Semua jenis</option>${types.map(type => `<option value="${escapeHtml(type)}">${escapeHtml(type)}</option>`).join('')}`;
        elements.masterType.value = types.includes(current) ? current : '';
        state.masterType = elements.masterType.value;
    }

    function assetSearchText(asset) {
        return normalizeText([
            assetIdentifier(asset),
            assetName(asset),
            assetType(asset),
            assetModel(asset),
            assetLocation(asset),
            assetIp(asset),
            field(asset, 'serial_number', 'serialNumber'),
        ].join(' '));
    }

    function filteredAssets() {
        return state.payload.assets.filter(asset => {
            const queryMatches = !state.masterQuery || assetSearchText(asset).includes(normalizeText(state.masterQuery));
            const typeMatches = !state.masterType || assetType(asset) === state.masterType;
            const relationMatches = !state.masterRelation || relationValue(asset) === state.masterRelation;
            return queryMatches && typeMatches && relationMatches;
        });
    }

    function renderMaster() {
        const assets = filteredAssets();
        elements.masterCount.textContent = `Menampilkan ${formatNumber(assets.length)} dari ${formatNumber(state.payload.assets.length)} aset`;
        elements.masterEmpty.hidden = assets.length > 0;
        elements.masterBody.innerHTML = assets.map(asset => {
            const id = assetIdentifier(asset);
            const relation = relationValue(asset);
            const condition = String(field(asset, 'condition', 'operational', 'status') || 'Tidak diketahui');
            return `
                <tr>
                    <td><button class="at-asset-link" type="button" data-inspect-asset="${escapeHtml(id)}"><strong>${escapeHtml(assetName(asset))}</strong><small>${escapeHtml(id)}</small></button></td>
                    <td><span class="at-type-badge">${escapeHtml(assetType(asset))}</span></td>
                    <td>${escapeHtml(assetLocation(asset))}</td>
                    <td><strong>${escapeHtml(assetModel(asset))}</strong><small>${escapeHtml(assetIp(asset))}</small></td>
                    <td><span class="at-relation-badge is-${escapeHtml(relation)}">${escapeHtml(relationLabel(relation))}</span></td>
                    <td>${trafficBadgeMarkup(trafficObservation(asset))}</td>
                    <td>${escapeHtml(condition)}</td>
                    <td>${canUpdateAsset(asset) ? `<button class="at-icon-button" type="button" data-open-asset-dialog="${escapeHtml(id)}" aria-label="Kelola ${escapeHtml(assetName(asset))}">⋯</button>` : ''}</td>
                </tr>`;
        }).join('');
    }

    function inspectorField(label, content) {
        return `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(content || 'Tidak tercatat')}</dd></div>`;
    }

    function inspectorTrafficMarkup(asset) {
        const observation = trafficObservation(asset);
        const analysisHref = safeSameOriginHref(
            field(observation, 'analysisUrl', 'analysis_url')
            || field(state.payload.traffic.analysis, 'url', 'analysisUrl', 'analysis_url'),
        );
        if (!state.payload.traffic.available) {
            return `
                <section class="at-inspector-traffic is-empty" aria-label="Korelasi log">
                    <strong>Belum ada korelasi log</strong>
                    <p>Selesaikan analisis log lokasi ini untuk melihat status perangkat.</p>
                </section>`;
        }
        if (!observationIsVisible(observation)) {
            return `
                <section class="at-inspector-traffic is-empty" aria-label="Korelasi log">
                    <strong>Belum terlihat di log terbaru</strong>
                    <p>Perangkat ini tidak memiliki kecocokan IP pada analisis terakhir.</p>
                    ${analysisHref ? `<a href="${escapeHtml(analysisHref)}">Buka hasil analisis →</a>` : ''}
                </section>`;
        }

        const ambiguous = observationStatus(observation) === 'ambiguous';
        const sourceIpsRaw = observation.sourceIps ?? observation.source_ips ?? [];
        const sourceIps = Array.isArray(sourceIpsRaw)
            ? sourceIpsRaw
            : String(sourceIpsRaw || '').split(',').map(ip => ip.trim()).filter(Boolean);
        const candidateCount = Number(field(observation, 'candidateCount', 'candidate_count')) || 0;
        const riskLevel = String(field(observation, 'riskLevel', 'risk_level') || 'Tidak tercatat');
        return `
            <section class="at-inspector-traffic ${ambiguous ? 'is-ambiguous' : 'is-observed'}" aria-label="Korelasi log">
                <div class="at-inspector-traffic-heading">
                    <div><span class="at-traffic-device-dot ${ambiguous ? 'is-ambiguous' : 'is-observed'}" aria-hidden="true"></span><strong>${observationLabel(observation)}</strong></div>
                    <span>${escapeHtml(riskLevel)}</span>
                </div>
                ${ambiguous ? `<p>IP yang sama cocok dengan ${formatNumber(candidateCount)} kandidat aset. Verifikasi manual sebelum mengubah data.</p>` : '<p>Kecocokan memakai IP utama atau IP terkait. Nilai traffic adalah total identity sehingga dapat mencakup IP lain milik identity yang sama.</p>'}
                <dl class="at-inspector-traffic-grid">
                    ${inspectorField('IP sumber', sourceIps.join(', ') || assetIp(asset))}
                    ${inspectorField('Identitas', formatNumber(field(observation, 'identityCount', 'identity_count')))}
                    ${inspectorField('Total akses', formatNumber(field(observation, 'totalAccess', 'total_access')))}
                    ${inspectorField('Traffic identity', formatBytes(field(observation, 'totalBytes', 'total_bytes')))}
                    ${inspectorField('Upload identity', formatBytes(field(observation, 'totalUpload', 'total_upload')))}
                    ${inspectorField('Download identity', formatBytes(field(observation, 'totalDownload', 'total_download')))}
                    ${inspectorField('Deny / berisiko', `${formatNumber(field(observation, 'deniedCount', 'denied_count'))} / ${formatNumber(field(observation, 'riskyCount', 'risky_count'))}`)}
                    ${inspectorField('Terakhir terlihat', formatDateTime(field(observation, 'lastSeenAt', 'last_seen_at')))}
                </dl>
                ${analysisHref ? `<a class="at-inspector-analysis-link" href="${escapeHtml(analysisHref)}">Buka hasil analisis <span aria-hidden="true">→</span></a>` : ''}
            </section>`;
    }

    function inspectorPortsMarkup(node) {
        if (node.type !== 'Switch LAN') return '';
        const ports = asArray(node.ports ?? node.raw?.ports);
        const summary = node.portSummary ?? node.raw?.portSummary ?? node.raw?.port_summary ?? {};
        const statusLabels = {
            healthy: 'Normal',
            problematic: 'Perlu diperiksa',
            disabled: 'Dinonaktifkan',
            unknown: 'Status belum pasti',
            unmonitored: 'Belum dipantau',
        };
        const total = Number(field(summary, 'total')) || ports.length;
        const monitored = Number(field(summary, 'monitored')) || 0;
        const problematic = Number(field(summary, 'problematic')) || 0;
        const telemetryConfigured = Boolean(field(summary, 'telemetryConfigured', 'telemetry_configured'));
        const telemetryAvailable = Boolean(field(summary, 'telemetryAvailable', 'telemetry_available'));
        const pollError = String(field(summary, 'lastPollError', 'last_poll_error'));
        const monitoringMessage = pollError
            ? `Polling terakhir gagal: ${pollError}`
            : !telemetryConfigured
                ? 'Nomor port berasal dari Excel/LLD. Pemantauan SNMP belum dikonfigurasi.'
                : !telemetryAvailable
                    ? 'Konfigurasi tersedia, tetapi ekstensi SNMP Laragon belum aktif.'
                    : monitored === 0
                        ? 'SNMP siap, tetapi switch ini belum memberikan hasil pemantauan.'
                        : `Status terakhir diperbarui ${formatDateTime(field(summary, 'lastObservedAt', 'last_observed_at'))}.`;

        if (!ports.length) {
            return `
                <section class="at-inspector-ports is-empty" aria-label="Status port LAN">
                    <div class="at-inspector-ports-heading">
                        <div><strong>Port LAN</strong><span>0 tercatat</span></div>
                    </div>
                    <p>Nomor atau jumlah port belum tersedia pada Excel lokasi ini. Sistem tidak membuat nomor port berdasarkan unit lain.</p>
                    <small>${escapeHtml(monitoringMessage)}</small>
                </section>`;
        }

        return `
            <section class="at-inspector-ports" aria-label="Status port LAN">
                <div class="at-inspector-ports-heading">
                    <div><strong>Port LAN</strong><span>${formatNumber(total)} tercatat</span></div>
                    <span class="${problematic > 0 ? 'is-problematic' : ''}">${problematic > 0 ? `${formatNumber(problematic)} masalah` : `${formatNumber(monitored)} dipantau`}</span>
                </div>
                <p>${escapeHtml(monitoringMessage)}</p>
                <ul class="at-port-grid">${ports.map(port => {
                    const health = String(field(port, 'healthStatus', 'health_status') || 'unmonitored');
                    const connections = asArray(port.connections);
                    const connection = connections.map(item => String(field(item, 'name', 'assetId', 'asset_id'))).filter(Boolean).join(', ');
                    const speed = Number(field(port, 'speedMbps', 'speed_mbps'));
                    const detail = connection || String(field(port, 'description')) || 'Belum ada perangkat terhubung';
                    const source = asArray(port.inventorySources ?? port.inventory_sources).join(' + ');
                    return `<li class="at-port-card is-${escapeHtml(health)}" title="${escapeHtml(String(field(port, 'failureReason', 'failure_reason') || statusLabels[health] || 'Status belum tersedia'))}">
                        <span class="at-port-indicator" aria-hidden="true"></span>
                        <div><strong>${escapeHtml(String(field(port, 'name', 'key') || 'Port'))}</strong><small>${escapeHtml(statusLabels[health] || 'Status belum tersedia')}</small></div>
                        <p>${escapeHtml(detail)}</p>
                        <span>${escapeHtml([speed > 0 ? `${formatNumber(speed)} Mbps` : '', source].filter(Boolean).join(' · ') || 'Sumber belum tercatat')}</span>
                    </li>`;
                }).join('')}</ul>
                <small class="at-port-disclaimer">Status “perlu diperiksa” berarti port sudah diaktifkan, tetapi koneksi perangkat tidak terdeteksi. Periksa kabel, perangkat tujuan, sumber daya, dan kondisi fisik port sebelum menyimpulkan kerusakan.</small>
            </section>`;
    }

    function renderInspector() {
        const node = state.selectedNode;
        if (!node) {
            elements.inspector.innerHTML = `
                <div class="at-inspector-empty">
                    <span aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M8 9h8M8 13h5"/></svg></span>
                    <strong>Pilih sebuah node</strong>
                    <p>Informasi aset dan tindakan akan muncul di sini.</p>
                </div>`;
            return;
        }
        const asset = node.asset;
        const id = node.assetId || assetIdentifier(asset);
        const relation = relationValue(asset ?? node);
        const status = String(field(asset, 'condition', 'operational', 'status') || 'Tidak diketahui');
        elements.inspector.innerHTML = `
            <div class="at-inspector-cover">
                <span class="at-inspector-type">${escapeHtml(node.type)}</span>
                <h4>${escapeHtml(node.label)}</h4>
                <p>${escapeHtml(id || 'Node non-inventaris')}</p>
            </div>
            <div class="at-inspector-body">
                <div class="at-inspector-badges">
                    <span class="at-relation-badge is-${escapeHtml(relation)}">${escapeHtml(relationLabel(relation))}</span>
                    <span class="at-condition-badge">${escapeHtml(status)}</span>
                </div>
                <dl class="at-inspector-fields">
                    ${inspectorField('Merk / model', asset ? assetModel(asset) : field(node.raw, 'model', 'role'))}
                    ${inspectorField('Lokasi', asset ? assetLocation(asset) : field(node.raw, 'location', 'site'))}
                    ${inspectorField('IP address', asset ? assetIp(asset) : field(node.raw, 'ip_address', 'address'))}
                    ${inspectorField('Serial number', field(asset, 'serial_number', 'serialNumber'))}
                    ${inspectorField('Firmware / OS', field(asset, 'firmware', 'operating_system', 'os'))}
                    ${inspectorField('Parent asset', field(asset, 'parent_asset_id', 'parentAssetId', 'parent_asset', 'parentAsset'))}
                </dl>
                ${inspectorPortsMarkup(node)}
                ${asset ? inspectorTrafficMarkup(asset) : ''}
                ${asset && id && canUpdateAsset(asset) ? `<button class="at-button at-button-primary at-inspector-action" type="button" data-open-asset-dialog="${escapeHtml(id)}">Kelola aset</button>` : ''}
            </div>`;
    }

    const historyAction = entry => String(field(entry, 'action', 'type', 'event') || 'change');
    const historyActionLabel = action => ({
        replace: 'Penggantian aset',
        replacement: 'Penggantian aset',
        mutate: 'Mutasi lokasi',
        mutation: 'Mutasi lokasi',
        'location-added': 'Lokasi baru',
        location_added: 'Lokasi baru',
        import: 'Impor lokasi',
    }[action] ?? titleFromKey(action));

    function filteredHistory() {
        const query = normalizeText(state.historyQuery);
        if (!query) return state.payload.history;
        return state.payload.history.filter(entry => normalizeText([
            field(entry, 'summary', 'title'),
            field(entry, 'reason', 'description'),
            field(entry, 'asset_id', 'assetId'),
            field(entry, 'related_asset_id', 'relatedAssetId'),
            field(entry, 'from_location', 'fromLocation'),
            field(entry, 'to_location', 'toLocation'),
            field(entry, 'actor', 'user_name'),
        ].join(' ')).includes(query));
    }

    function renderHistory() {
        const entries = filteredHistory();
        elements.historyCount.textContent = `Menampilkan ${formatNumber(entries.length)} perubahan`;
        elements.historyEmpty.hidden = entries.length > 0;
        elements.historyList.hidden = entries.length === 0;
        elements.historyList.innerHTML = entries.map(entry => {
            const action = historyAction(entry);
            const summary = String(field(entry, 'summary', 'title') || historyActionLabel(action));
            const reason = String(field(entry, 'reason', 'description') || 'Tanpa catatan tambahan.');
            const assetId = String(field(entry, 'asset_id', 'assetId'));
            return `
                <li class="at-history-card">
                    <span class="at-history-marker" aria-hidden="true"></span>
                    <div>
                        <span class="at-history-action">${escapeHtml(historyActionLabel(action))}</span>
                        <h3>${escapeHtml(summary)}</h3>
                        <p>${escapeHtml(reason)}</p>
                        ${assetId ? `<small>Aset: ${escapeHtml(assetId)}</small>` : ''}
                    </div>
                    <time datetime="${escapeHtml(field(entry, 'created_at', 'createdAt', 'date'))}">${escapeHtml(formatDateTime(field(entry, 'created_at', 'createdAt', 'date')))}</time>
                </li>`;
        }).join('');
    }

    function normalizePayload(response) {
        const payload = response?.data && response.data.topology ? response.data : response;
        const traffic = payload?.traffic && typeof payload.traffic === 'object' ? payload.traffic : {};
        return {
            locations: asArray(payload?.locations),
            activeLocationId: String(field(payload, 'activeLocationId', 'active_location_id')),
            location: payload?.location ?? null,
            metrics: payload?.metrics ?? {},
            assets: asArray(payload?.assets?.data ?? payload?.assets),
            topology: payload?.topology && typeof payload.topology === 'object'
                ? payload.topology
                : { nodes: [], edges: [], mappings: [], summary: {} },
            traffic: {
                available: Boolean(traffic.available),
                analysis: traffic.analysis ?? null,
                summary: traffic.summary && typeof traffic.summary === 'object' ? traffic.summary : {},
                unmappedDevices: asArray(traffic.unmappedDevices ?? traffic.unmapped_devices),
                ambiguousDevices: asArray(traffic.ambiguousDevices ?? traffic.ambiguous_devices),
            },
            history: asArray(payload?.history?.data ?? payload?.history),
        };
    }

    function syncLocationQuery(location) {
        const url = new URL(window.location.href);
        if (location) url.searchParams.set('location', location);
        else url.searchParams.delete('location');
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    }

    function setLoading(loading, message = 'Memuat data topologi…') {
        root.setAttribute('aria-busy', String(loading));
        elements.locationSelect.disabled = loading || !state.payload.locations.length;
        elements.status.hidden = !loading;
        elements.status.classList.remove('is-error');
        elements.statusText.textContent = message;
        elements.retry.hidden = true;
    }

    function showPageError(error) {
        root.setAttribute('aria-busy', 'false');
        elements.status.hidden = false;
        elements.status.classList.add('is-error');
        elements.statusText.textContent = error?.message || 'Data topologi tidak dapat dimuat.';
        elements.retry.hidden = false;
    }

    async function loadData(requestedLocationId = '') {
        const sequence = ++state.requestSequence;
        setLoading(true, requestedLocationId ? 'Mengganti lokasi…' : 'Memuat data topologi…');
        try {
            const response = await fetchJson(root.dataset.endpoint, {
                query: requestedLocationId ? { location: requestedLocationId } : {},
            });
            if (sequence !== state.requestSequence) return;
            state.payload = normalizePayload(response);
            state.activeLocationId = state.payload.activeLocationId
                || locationId(state.payload.location)
                || requestedLocationId
                || locationId(state.payload.locations[0]);
            syncLocationQuery(state.activeLocationId);
            renderLocationContext();
            renderMetrics();
            renderTraffic();
            populateMasterTypes();
            renderMaster();
            renderHistory();
            tree.setData(state.payload);
            if (!tree.selectedNode()) {
                state.selectedNode = null;
                renderInspector();
            }
            root.setAttribute('aria-busy', 'false');
            elements.status.hidden = true;
        } catch (error) {
            if (sequence !== state.requestSequence) return;
            console.error('Gagal memuat topologi:', error);
            showPageError(error);
            state.payload = emptyPayload();
            renderTraffic();
            tree.setData(emptyPayload());
        }
    }

    function activateTab(name, focus = false) {
        if (!['explorer', 'master', 'history'].includes(name)) return;
        state.activeTab = name;
        elements.tabs.forEach(tab => {
            const active = tab.dataset.tab === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        elements.panels.forEach(panel => { panel.hidden = panel.dataset.tabPanel !== name; });
        if (name === 'master') renderMaster();
        if (name === 'history') renderHistory();
        if (name === 'explorer') zoom.afterTreeRender(tree.selectedNode()?.id ?? '');
    }

    function showToast(message) {
        clearTimeout(state.toastTimer);
        elements.toast.textContent = message;
        elements.toast.classList.add('is-visible');
        state.toastTimer = window.setTimeout(() => elements.toast.classList.remove('is-visible'), 3200);
    }

    function validationMessage(error) {
        const errors = error?.payload?.errors;
        if (errors && typeof errors === 'object') {
            const first = Object.values(errors).flat().find(Boolean);
            if (first) return String(first);
        }
        return error?.message || 'Perubahan tidak dapat disimpan.';
    }

    function openDialog(dialog) {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    }

    function closeDialog(dialog) {
        if (!dialog) return;
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }

    function assetById(id) {
        return state.payload.assets.find(asset => assetIdentifier(asset) === String(id)) ?? null;
    }

    function updateActionSections() {
        const action = elements.assetForm.elements.action.value;
        all('[data-action-section]').forEach(section => { section.hidden = section.dataset.actionSection !== action; });
        const replacementId = elements.assetForm.elements.replacement_asset_id;
        const replacementModel = elements.assetForm.elements.model;
        const targetLocation = elements.assetForm.elements.target_organizational_unit_id;
        const mutationDetail = elements.assetForm.elements.location;
        replacementId.required = action === 'replace';
        replacementModel.required = action === 'replace';
        targetLocation.required = action === 'mutate';
        mutationDetail.required = action === 'mutate';
    }

    function populateMutationLocations() {
        elements.mutationLocation.innerHTML = `<option value="">Pilih lokasi tujuan</option>${state.payload.locations
            .filter(location => locationId(location) !== state.activeLocationId)
            .map(location => `<option value="${escapeHtml(locationRecordId(location))}" ${locationRecordId(location) === '' ? 'disabled' : ''}>${escapeHtml(locationName(location))}</option>`)
            .join('')}`;
    }

    function openAssetDialog(id) {
        const asset = assetById(id);
        if (!asset) {
            showToast('Aset tidak ditemukan pada lokasi aktif.');
            return;
        }
        elements.assetForm.reset();
        elements.assetForm.dataset.assetId = id;
        elements.assetDialogTitle.textContent = assetName(asset);
        elements.assetDialogSummary.textContent = `${assetType(asset)} · ${id}`;
        elements.assetForm.elements.effective_date.value = new Date().toISOString().slice(0, 10);
        populateMutationLocations();
        elements.assetError.hidden = true;
        updateActionSections();
        openDialog(elements.assetDialog);
    }

    function openImportDialog() {
        const location = activeLocation();
        if (!location) {
            showToast('Pilih lokasi yang akan diperbarui terlebih dahulu.');
            return;
        }
        elements.importForm.reset();
        elements.importLocation.textContent = locationName(location);
        elements.importError.hidden = true;
        openDialog(elements.importDialog);
        requestAnimationFrame(() => elements.importForm.elements.workbook.focus());
    }

    function openPortAssignment(node, portName) {
        const switchRecordId = field(node?.asset, 'recordId', 'record_id')
            || field(node?.raw, 'assetRecordId', 'asset_record_id');
        if (!switchRecordId) {
            showToast('Switch ini belum terhubung ke master aset. Import Excel terlebih dahulu.');
            return;
        }

        const accessPoints = state.payload.assets.filter(asset => assetType(asset) === 'Access Point' && canUpdateAsset(asset));
        const selectedPort = asArray(node.ports).find(port => normalizeText(field(port, 'name', 'key')) === normalizeText(portName));
        const currentAssetId = field(asArray(selectedPort?.connections)[0], 'assetId', 'asset_id');
        elements.portForm.reset();
        elements.portForm.dataset.switchRecordId = String(switchRecordId);
        elements.portForm.dataset.portName = String(portName);
        elements.portSummary.textContent = `${node.label} · port ${portName}. Data AP diambil dari database lokasi aktif.`;
        elements.portTarget.textContent = `${node.label} / port ${portName}`;
        elements.portAccessPoint.innerHTML = `<option value="">${accessPoints.length ? 'Pilih access point' : 'Belum ada AP pada database lokasi ini'}</option>${accessPoints.map(asset => {
            const recordId = field(asset, 'recordId', 'record_id');
            const id = assetIdentifier(asset);
            const description = [id, assetModel(asset), assetIp(asset), assetLocation(asset)].filter(Boolean).join(' · ');
            return `<option value="${escapeHtml(recordId)}" ${id === currentAssetId ? 'selected' : ''}>${escapeHtml(description)}</option>`;
        }).join('')}`;
        elements.portAccessPoint.disabled = accessPoints.length === 0;
        elements.portSubmit.disabled = accessPoints.length === 0;
        elements.portError.hidden = true;
        openDialog(elements.portDialog);
        if (accessPoints.length) requestAnimationFrame(() => elements.portAccessPoint.focus());
    }

    async function submitLocation(event) {
        event.preventDefault();
        const name = String(new FormData(elements.locationForm).get('name') || '').trim();
        if (!name) return;
        elements.locationError.hidden = true;
        elements.locationSubmit.disabled = true;
        elements.locationSubmit.setAttribute('aria-busy', 'true');
        try {
            const response = await fetchJson(root.dataset.locationEndpoint, { method: 'POST', body: { name } });
            const created = response?.location ?? response?.data?.location ?? response?.data ?? response;
            closeDialog(elements.locationDialog);
            elements.locationForm.reset();
            showToast(`Lokasi ${locationName(created) || name} berhasil ditambahkan.`);
            await loadData(locationId(created));
        } catch (error) {
            elements.locationError.textContent = validationMessage(error);
            elements.locationError.hidden = false;
        } finally {
            elements.locationSubmit.disabled = false;
            elements.locationSubmit.removeAttribute('aria-busy');
        }
    }

    async function submitWorkbook(event) {
        event.preventDefault();
        const workbook = elements.importForm.elements.workbook.files?.[0];
        if (!workbook || !state.activeLocationId) return;
        const body = new FormData();
        body.append('location', state.activeLocationId);
        body.append('workbook', workbook);
        elements.importError.hidden = true;
        elements.importSubmit.disabled = true;
        elements.importSubmit.setAttribute('aria-busy', 'true');
        try {
            const response = await fetchJson(root.dataset.importEndpoint, { method: 'POST', body });
            closeDialog(elements.importDialog);
            elements.importForm.reset();
            showToast(field(response, 'message') || 'Excel berhasil diimport dan topologi diperbarui.');
            await loadData(state.activeLocationId);
        } catch (error) {
            elements.importError.textContent = validationMessage(error);
            elements.importError.hidden = false;
        } finally {
            elements.importSubmit.disabled = false;
            elements.importSubmit.removeAttribute('aria-busy');
        }
    }

    async function submitPortAssignment(event) {
        event.preventDefault();
        const switchAssetId = Number(elements.portForm.dataset.switchRecordId);
        const port = String(elements.portForm.dataset.portName || '');
        const accessPointAssetId = Number(elements.portAccessPoint.value);
        if (!switchAssetId || !accessPointAssetId || !port) return;
        elements.portError.hidden = true;
        elements.portSubmit.disabled = true;
        elements.portSubmit.setAttribute('aria-busy', 'true');
        try {
            const response = await fetchJson(root.dataset.portEndpoint, {
                method: 'POST',
                body: {
                    location: state.activeLocationId,
                    switchAssetId,
                    port,
                    accessPointAssetId,
                },
            });
            closeDialog(elements.portDialog);
            showToast(field(response, 'message') || 'Access point berhasil dihubungkan.');
            await loadData(state.activeLocationId);
            const selectedAssetId = field(response, 'selectedAssetId', 'selected_asset_id');
            if (selectedAssetId) tree.selectByAssetId(selectedAssetId);
        } catch (error) {
            elements.portError.textContent = validationMessage(error);
            elements.portError.hidden = false;
        } finally {
            elements.portSubmit.disabled = false;
            elements.portSubmit.removeAttribute('aria-busy');
        }
    }

    async function submitAssetChange(event) {
        event.preventDefault();
        const assetId = elements.assetForm.dataset.assetId;
        if (!assetId) return;
        const data = new FormData(elements.assetForm);
        const action = String(data.get('action') || 'replace');
        const body = action === 'replace'
            ? {
                action,
                replacement: {
                    assetId: String(data.get('replacement_asset_id') || '').trim(),
                    brand: String(data.get('brand') || '').trim(),
                    model: String(data.get('model') || '').trim(),
                    serialNumber: String(data.get('serial_number') || '').trim(),
                    firmware: String(data.get('firmware') || '').trim(),
                    condition: String(data.get('condition') || '').trim(),
                    operational: String(data.get('operational') || '').trim(),
                    effectiveDate: String(data.get('effective_date') || ''),
                    reason: String(data.get('reason') || '').trim(),
                    notes: String(data.get('notes') || '').trim(),
                },
            }
            : {
                action,
                mutation: {
                    organizationalUnitId: String(data.get('target_organizational_unit_id') || ''),
                    location: String(data.get('location') || '').trim(),
                    parentAsset: String(data.get('parent_asset') || '').trim(),
                    effectiveDate: String(data.get('effective_date') || ''),
                    reason: String(data.get('reason') || '').trim(),
                },
            };
        elements.assetError.hidden = true;
        elements.assetSubmit.disabled = true;
        elements.assetSubmit.setAttribute('aria-busy', 'true');
        try {
            const template = root.dataset.assetEndpointTemplate;
            const endpoint = template.replace('__ASSET__', encodeURIComponent(assetId));
            await fetchJson(endpoint, { method: 'PATCH', body });
            closeDialog(elements.assetDialog);
            showToast(action === 'replace' ? 'Aset berhasil diganti.' : 'Aset berhasil dimutasi.');
            await loadData(state.activeLocationId);
        } catch (error) {
            elements.assetError.textContent = validationMessage(error);
            elements.assetError.hidden = false;
        } finally {
            elements.assetSubmit.disabled = false;
            elements.assetSubmit.removeAttribute('aria-busy');
        }
    }

    elements.tabs.forEach(tab => tab.addEventListener('click', () => activateTab(tab.dataset.tab)));
    root.querySelector('.at-tabs')?.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const current = elements.tabs.indexOf(document.activeElement);
        if (current < 0) return;
        event.preventDefault();
        const next = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? elements.tabs.length - 1
                : event.key === 'ArrowRight'
                    ? (current + 1) % elements.tabs.length
                    : (current - 1 + elements.tabs.length) % elements.tabs.length;
        activateTab(elements.tabs[next].dataset.tab, true);
    });

    elements.locationSelect.addEventListener('change', event => loadData(event.target.value));
    elements.retry.addEventListener('click', () => loadData(state.activeLocationId));
    one('[data-reset-tree]')?.addEventListener('click', () => tree.reset());
    elements.masterSearch.addEventListener('input', event => { state.masterQuery = event.target.value; renderMaster(); });
    elements.masterType.addEventListener('change', event => { state.masterType = event.target.value; renderMaster(); });
    elements.masterRelation.addEventListener('change', event => { state.masterRelation = event.target.value; renderMaster(); });
    one('[data-clear-master-filter]')?.addEventListener('click', () => {
        state.masterQuery = '';
        state.masterType = '';
        state.masterRelation = '';
        elements.masterSearch.value = '';
        elements.masterType.value = '';
        elements.masterRelation.value = '';
        renderMaster();
    });
    elements.historySearch.addEventListener('input', event => { state.historyQuery = event.target.value; renderHistory(); });
    elements.locationForm.addEventListener('submit', submitLocation);
    elements.importForm.addEventListener('submit', submitWorkbook);
    elements.portForm.addEventListener('submit', submitPortAssignment);
    elements.assetForm.addEventListener('submit', submitAssetChange);
    [...elements.assetForm.querySelectorAll('[name="action"]')].forEach(radio => radio.addEventListener('change', updateActionSections));

    root.addEventListener('click', event => {
        if (event.target.closest('[data-open-location-dialog]')) {
            elements.locationError.hidden = true;
            openDialog(elements.locationDialog);
            requestAnimationFrame(() => elements.locationForm.elements.name.focus());
            return;
        }
        if (event.target.closest('[data-open-import-dialog]')) {
            openImportDialog();
            return;
        }
        const assetAction = event.target.closest('[data-open-asset-dialog]');
        if (assetAction) {
            openAssetDialog(assetAction.dataset.openAssetDialog);
            return;
        }
        const inspectAsset = event.target.closest('[data-inspect-asset]');
        if (inspectAsset) {
            activateTab('explorer');
            if (!tree.selectByAssetId(inspectAsset.dataset.inspectAsset)) showToast('Aset belum dipetakan pada tree.');
            return;
        }
        const close = event.target.closest('[data-close-dialog]');
        if (close) closeDialog(close.closest('dialog'));
    });

    [elements.locationDialog, elements.importDialog, elements.portDialog, elements.assetDialog].forEach(dialog => {
        dialog?.addEventListener('click', event => {
            if (event.target === dialog) closeDialog(dialog);
        });
    });

    activateTab('explorer');
    const initialParameters = new URLSearchParams(window.location.search);
    const initialLocation = initialParameters.get('location') || '';
    const initialAsset = initialParameters.get('asset') || '';
    loadData(initialLocation).then(() => {
        if (initialAsset && !tree.selectByAssetId(initialAsset)) {
            showToast('Aset dari tautan tidak ditemukan pada tree lokasi ini.');
        }
    });
}
