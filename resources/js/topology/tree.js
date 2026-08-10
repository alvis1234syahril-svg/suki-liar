const STAGES = ['Firewall', 'Router', 'Switch LAN', 'Access Point'];

const value = (...candidates) => {
    const match = candidates.find(candidate => candidate !== null && candidate !== undefined && String(candidate).trim() !== '');
    return match === undefined ? '' : String(match).trim();
};

const asArray = input => Array.isArray(input) ? input : [];

export const escapeHtml = (input) => String(input ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

export const normalizeText = input => String(input ?? '')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .toLocaleLowerCase('id');

export const assetIdentifier = asset => value(
    asset?.id,
    asset?.asset_id,
    asset?.assetId,
    asset?.code,
    asset?.uuid,
);

export const assetType = asset => normalizeType(value(
    asset?.type,
    asset?.asset_type,
    asset?.assetType,
    asset?.kind,
    asset?.category,
));

export const relationValue = source => normalizeRelation(value(
    source?.isUnconnected ? 'unconnected' : '',
    source?.isInferred ? 'inferred' : '',
    source?.isExplicit ? 'verified' : '',
    source?.relation,
    source?.relation_status,
    source?.relationStatus,
    source?.relation_type,
    source?.relationType,
    source?.status,
    source?.source_type,
));

export function normalizeType(input) {
    const normalized = normalizeText(input).replace(/[_-]+/g, ' ');
    if (!normalized) return 'Perangkat';
    if (normalized.includes('firewall') || normalized === 'fw') return 'Firewall';
    if (normalized.includes('router') || normalized === 'cpe') return 'Router';
    if (normalized.includes('switch')) return 'Switch LAN';
    if (normalized.includes('access point') || normalized === 'ap' || normalized.includes('wifi')) return 'Access Point';
    if (
        normalized.includes('source') ||
        normalized.includes('sumber') ||
        normalized.includes('wan') ||
        normalized.includes('internet') ||
        normalized.includes('pln net') ||
        normalized.includes('external')
    ) return 'Sumber';
    return value(input, 'Perangkat');
}

export function normalizeRelation(input) {
    const normalized = normalizeText(input).replace(/[_\s]+/g, '-');
    if (['verified', 'parent-cocok', 'excel', 'mapped'].includes(normalized)) return 'verified';
    if (['lld', 'drawio', 'draw-io', 'diagram'].includes(normalized)) return 'lld';
    if (['inferred', 'suspected', 'parent-terduga', 'predicted'].includes(normalized)) return 'inferred';
    if (['unconnected', 'unverified', 'unmapped', 'disconnected'].includes(normalized)) return 'unconnected';
    if (['external', 'conceptual', 'konseptual'].includes(normalized)) return 'conceptual';
    if (['gap', 'skipped', 'skip', 'missing'].includes(normalized)) return 'gap';
    return normalized || 'conceptual';
}

function mappingEntries(mappings) {
    if (Array.isArray(mappings)) return mappings;
    if (!mappings || typeof mappings !== 'object') return [];
    return Object.entries(mappings).map(([nodeId, assetId]) => (
        typeof assetId === 'object'
            ? { node_id: nodeId, ...assetId }
            : { node_id: nodeId, asset_id: assetId }
    ));
}

function edgeEndpoints(edge) {
    return {
        from: value(edge?.from, edge?.source, edge?.source_id, edge?.parent, edge?.parent_id, edge?.parentId),
        to: value(edge?.to, edge?.target, edge?.target_id, edge?.child, edge?.child_id, edge?.childId),
    };
}

function explicitParentId(asset) {
    return value(
        asset?.parent_id,
        asset?.parentId,
        asset?.parent_asset_id,
        asset?.parentAssetId,
        asset?.parent_asset,
        asset?.parentAsset,
    );
}

function assetLabel(asset) {
    return value(
        asset?.display_name,
        asset?.displayName,
        asset?.name,
        asset?.model,
        asset?.site,
        assetIdentifier(asset),
        'Aset tanpa nama',
    );
}

function buildGraph(payload = {}) {
    const assets = Array.isArray(payload.assets) ? payload.assets : [];
    const topology = payload.topology && typeof payload.topology === 'object' ? payload.topology : {};
    const assetById = new Map();
    assets.forEach(asset => {
        const id = assetIdentifier(asset);
        if (id) assetById.set(id, asset);
    });

    const assetIdByNode = new Map();
    mappingEntries(topology.mappings).forEach(mapping => {
        const nodeId = value(mapping.node_id, mapping.nodeId, mapping.topology_node_id, mapping.topologyNodeId, mapping.id);
        const assetId = value(mapping.asset_id, mapping.assetId, mapping.asset, mapping.inventory_id);
        if (nodeId && assetId) assetIdByNode.set(nodeId, assetId);
    });

    const rawNodes = Array.isArray(topology.nodes) ? topology.nodes : [];
    const nodes = [];
    const nodeById = new Map();
    const addNode = node => {
        if (!node.id || nodeById.has(node.id)) return nodeById.get(node.id);
        nodeById.set(node.id, node);
        nodes.push(node);
        return node;
    };

    rawNodes.forEach((rawNode, index) => {
        const id = value(rawNode.id, rawNode.node_id, rawNode.key, `node-${index + 1}`);
        const mappedAssetId = value(
            rawNode.asset_id,
            rawNode.assetId,
            assetIdByNode.get(id),
        );
        const asset = mappedAssetId ? assetById.get(mappedAssetId) ?? null : null;
        addNode({
            id,
            assetId: mappedAssetId || assetIdentifier(asset),
            asset,
            type: normalizeType(value(rawNode.type, rawNode.kind, rawNode.role, assetType(asset))),
            label: value(rawNode.label, rawNode.name, rawNode.title, assetLabel(asset), `Node ${index + 1}`),
            relation: relationValue(rawNode),
            ports: asArray(rawNode.ports),
            portSummary: rawNode.portSummary ?? rawNode.port_summary ?? null,
            raw: rawNode,
            virtual: false,
        });
    });

    const rawEdges = Array.isArray(topology.edges)
        ? topology.edges
        : Array.isArray(topology.links)
            ? topology.links
            : [];
    const edges = [];
    const edgeKeys = new Set();
    const addEdge = edge => {
        if (!edge.from || !edge.to || edge.from === edge.to || !nodeById.has(edge.from) || !nodeById.has(edge.to)) return;
        const key = `${edge.from}\u0000${edge.to}`;
        if (edgeKeys.has(key)) return;
        edgeKeys.add(key);
        edges.push(edge);
    };

    rawEdges.forEach((rawEdge, index) => {
        const { from, to } = edgeEndpoints(rawEdge);
        addEdge({
            id: value(rawEdge.id, `edge-${index + 1}`),
            from,
            to,
            relation: relationValue(rawEdge),
            label: value(rawEdge.label, rawEdge.name),
            fromPort: value(rawEdge.from_port, rawEdge.fromPort, rawEdge.source_port, rawEdge.sourcePort),
            toPort: value(rawEdge.to_port, rawEdge.toPort, rawEdge.target_port, rawEdge.targetPort),
            raw: rawEdge,
        });
    });

    const incoming = new Map(nodes.map(node => [node.id, 0]));
    edges.forEach(edge => incoming.set(edge.to, (incoming.get(edge.to) ?? 0) + 1));
    let roots = nodes.filter(node => (incoming.get(node.id) ?? 0) === 0);
    if (!roots.length && nodes.length) roots = [nodes[0]];

    const sourceRoots = roots.filter(root => root.type === 'Sumber');
    const orphanRoots = roots.filter(root => root.type !== 'Sumber');
    const requiresPresentationRoot = roots.length > 1 || (roots[0] && roots[0].type !== 'Sumber');
    if (requiresPresentationRoot && roots.length) {
        const presentationRoot = sourceRoots.length === 1
            ? sourceRoots[0]
            : addNode({
                id: '__topology_source__',
                assetId: '',
                asset: null,
                type: 'Sumber',
                label: value(topology.summary?.source, payload.location?.source, 'Sumber jaringan'),
                relation: 'conceptual',
                ports: [],
                portSummary: null,
                raw: {},
                virtual: true,
            });

        sourceRoots.filter(root => root.id !== presentationRoot.id).forEach((root, index) => addEdge({
            id: `virtual-source-edge-${index + 1}`,
            from: presentationRoot.id,
            to: root.id,
            relation: root.relation || 'conceptual',
            label: '',
            raw: {},
        }));

        const orphanGroups = new Map();
        orphanRoots.forEach(root => {
            if (!orphanGroups.has(root.type)) orphanGroups.set(root.type, []);
            orphanGroups.get(root.type).push(root);
        });
        [...orphanGroups.entries()].sort(([left], [right]) => left.localeCompare(right, 'id')).forEach(([type, members], groupIndex) => {
            if (members.length === 1) {
                addEdge({
                    id: `virtual-orphan-edge-${groupIndex + 1}`,
                    from: presentationRoot.id,
                    to: members[0].id,
                    relation: 'unconnected',
                    label: 'Belum memiliki parent',
                    raw: {},
                });
                return;
            }

            const groupNode = addNode({
                id: `__unconnected_group_${groupIndex + 1}`,
                assetId: '',
                asset: null,
                type: 'Kelompok',
                label: `${type} belum terhubung (${members.length})`,
                relation: 'unconnected',
                ports: [],
                portSummary: null,
                raw: { memberType: type, memberCount: members.length },
                virtual: true,
            });
            addEdge({
                id: `virtual-group-edge-${groupIndex + 1}`,
                from: presentationRoot.id,
                to: groupNode.id,
                relation: 'unconnected',
                label: 'Kelompok aset tanpa parent',
                raw: {},
            });
            members.forEach((member, memberIndex) => addEdge({
                id: `virtual-group-member-${groupIndex + 1}-${memberIndex + 1}`,
                from: groupNode.id,
                to: member.id,
                relation: 'unconnected',
                label: 'Parent belum tercatat',
                raw: {},
            }));
        });
        roots = [presentationRoot];
    }

    const childrenById = new Map(nodes.map(node => [node.id, []]));
    const parentsById = new Map(nodes.map(node => [node.id, []]));
    edges.forEach(edge => {
        childrenById.get(edge.from)?.push({ node: nodeById.get(edge.to), edge });
        parentsById.get(edge.to)?.push({ node: nodeById.get(edge.from), edge });
    });
    const typeIndex = type => {
        if (type === 'Sumber') return -1;
        const index = STAGES.indexOf(type);
        return index < 0 ? STAGES.length : index;
    };
    childrenById.forEach((children, parentId) => children.sort((left, right) => {
        const parent = nodeById.get(parentId);
        if (parent?.type === 'Switch LAN') {
            const leftPort = value(left.edge.fromPort, left.edge.raw?.fromPort, left.edge.raw?.from_port);
            const rightPort = value(right.edge.fromPort, right.edge.raw?.fromPort, right.edge.raw?.from_port);
            if (leftPort || rightPort) {
                const portOrder = leftPort.localeCompare(rightPort, 'id', { numeric: true, sensitivity: 'base' });
                if (portOrder !== 0) return portOrder;
            }
        }
        return typeIndex(left.node.type) - typeIndex(right.node.type)
            || left.node.label.localeCompare(right.node.label, 'id');
    }));

    return { nodes, edges, roots, nodeById, childrenById, parentsById, summary: topology.summary ?? {} };
}

function iconForType(type) {
    if (type === 'Firewall') return '<svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM4 10h16M9 5v5m6 0v5M4 15h5m6 0h5"/></svg>';
    if (type === 'Router') return '<svg viewBox="0 0 24 24"><rect x="4" y="9" width="16" height="9" rx="2"/><path d="M8 13h.01M12 13h.01M16 13h.01M12 9V5m-3 2 3-2 3 2"/></svg>';
    if (type === 'Switch LAN') return '<svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h2m2 0h2m2 0h2M7 14h10"/></svg>';
    if (type === 'Access Point') return '<svg viewBox="0 0 24 24"><path d="M5 9a10 10 0 0 1 14 0M8 12a6 6 0 0 1 8 0m-5 4a1.5 1.5 0 1 1 2 0"/></svg>';
    if (type === 'Sumber') return '<svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 16 0M7 12a5 5 0 0 1 10 0m-7 0a2 2 0 0 1 4 0M12 14v5"/></svg>';
    return '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h5"/></svg>';
}

function stageGap(parentType, childType) {
    const parentIndex = parentType === 'Sumber' ? -1 : STAGES.indexOf(parentType);
    const childIndex = STAGES.indexOf(childType);
    if (childIndex < 0 || (parentType !== 'Sumber' && parentIndex < 0) || childIndex - parentIndex <= 1) return [];
    return STAGES.slice(parentIndex + 1, childIndex);
}

function displayAssetDetail(node) {
    const asset = node.asset ?? {};
    return value(
        asset.location,
        asset.site,
        asset.model,
        node.raw?.role,
        node.virtual ? 'Root topologi' : '',
    );
}

function nodeTrafficState(asset) {
    const observation = asset?.trafficObservation ?? asset?.traffic_observation;
    if (!observation || observation.observed === false) return null;
    const status = normalizeText(value(observation.matchStatus, observation.match_status));
    if (!['exact', 'ambiguous'].includes(status)) return null;
    return {
        status,
        label: status === 'ambiguous' ? 'Cocok ke beberapa aset' : 'Terlihat di log',
    };
}

function nodePortState(node) {
    if (node.type !== 'Switch LAN') return null;
    const summary = node.portSummary ?? {};
    const total = Number(summary.total) || node.ports.length;
    const problematic = Number(summary.problematic) || 0;
    const monitored = Number(summary.monitored) || 0;
    return {
        total,
        problematic,
        monitored,
        tone: problematic > 0 ? 'problematic' : monitored > 0 ? 'healthy' : 'unmonitored',
        label: problematic > 0
            ? `${problematic} port perlu diperiksa`
            : `${total} port tercatat`,
    };
}

const PORT_HEALTH_LABELS = {
    healthy: 'Normal',
    problematic: 'Perlu diperiksa',
    disabled: 'Dinonaktifkan',
    unknown: 'Status belum pasti',
    unmonitored: 'Belum dipantau',
};

function physicalPortLabel(port, index) {
    const name = value(port?.name, port?.key, index + 1);
    if (/^\d{1,3}$/.test(name)) return name;
    const ifIndex = Number(port?.ifIndex ?? port?.if_index);
    if (ifIndex > 0) return String(ifIndex);
    const suffix = name.match(/(?:^|[\/\s-])(\d{1,3})$/)?.[1];
    return suffix || String(index + 1);
}

function nodePortPanelMarkup(node, open = false) {
    if (node.type !== 'Switch LAN') return '';
    const ports = asArray(node.ports);
    if (!ports.length) return '';

    const connectedPorts = ports.filter(port => asArray(port.connections).length > 0);
    const connected = connectedPorts.length;
    const problematic = ports.filter(port => normalizeText(value(port.healthStatus, port.health_status)) === 'problematic').length;
    const connectedLabels = connectedPorts
        .slice(0, 6)
        .map((port, index) => `P${physicalPortLabel(port, index)}`)
        .join(', ');
    const summary = problematic > 0
        ? `${problematic} bermasalah · ${connected} terhubung`
        : connectedLabels
            ? `Terhubung: ${connectedLabels}${connected > 6 ? ` +${connected - 6}` : ''}`
            : `${ports.length} port · belum ada kabel tercatat`;

    return `<div class="at-node-port-panel ${open ? 'is-open' : 'is-collapsed'}">
        <button class="at-node-port-disclosure" type="button" data-node-port-toggle="${escapeHtml(node.id)}" aria-expanded="${open}">
            <span><strong>${open ? 'Tutup port LAN' : `Lihat ${ports.length} port LAN`}</strong><small>${escapeHtml(summary)}</small></span>
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
        </button>
        ${open ? `<span class="at-physical-port-grid" aria-label="${escapeHtml(`${ports.length} port LAN pada ${node.label}`)}">
            ${ports.map((port, index) => {
                const rawHealth = normalizeText(value(port.healthStatus, port.health_status, 'unmonitored'));
                const health = Object.hasOwn(PORT_HEALTH_LABELS, rawHealth) ? rawHealth : 'unknown';
                const connections = asArray(port.connections)
                    .map(connection => value(connection.name, connection.assetId, connection.asset_id))
                    .filter(Boolean);
                const name = value(port.name, port.key, `Port ${index + 1}`);
                const detail = connections.length ? `Terhubung ke ${connections.join(', ')}` : 'Belum ada perangkat tercatat';
                const title = `Port ${name}: ${PORT_HEALTH_LABELS[health]}. ${detail}`;
                return `<button type="button" class="at-physical-port is-${escapeHtml(health)} ${connections.length ? 'is-connected' : ''}" data-port-assign-node="${escapeHtml(node.id)}" data-port-name="${escapeHtml(name)}" title="${escapeHtml(`${title}. Klik untuk menetapkan access point.`)}" aria-label="${escapeHtml(`${title}. Klik untuk menetapkan access point.`)}">
                    <span class="at-physical-port-jack" aria-hidden="true"><i></i></span>
                    <small>${escapeHtml(physicalPortLabel(port, index))}</small>
                </button>`;
            }).join('')}
        </span>` : ''}
    </div>`;
}

function nodeSearchText(node) {
    const asset = node.asset ?? {};
    return normalizeText([
        node.label,
        node.type,
        node.assetId,
        asset.model,
        asset.brand,
        asset.location,
        asset.site,
        asset.ip_address,
        asset.ipAddress,
        ...node.ports.flatMap(port => [port.name, port.description, ...asArray(port.connections).map(connection => connection.name)]),
    ].join(' '));
}

export class TopologyTree {
    constructor({ canvas, viewport, stateElement, summaryElement, searchInput, announcer, onSelect, onPortSelect, onRendered }) {
        this.canvas = canvas;
        this.viewport = viewport;
        this.stateElement = stateElement;
        this.summaryElement = summaryElement;
        this.searchInput = searchInput;
        this.announcer = announcer;
        this.onSelect = onSelect;
        this.onPortSelect = onPortSelect;
        this.onRendered = onRendered;
        this.graph = buildGraph();
        this.expanded = new Set();
        this.portExpanded = new Set();
        this.selectedId = '';
        this.query = '';
        this.bindEvents();
    }

    setData(payload) {
        const previousSelection = this.selectedId;
        this.graph = buildGraph(payload);
        this.expanded = new Set([...this.expanded].filter(id => this.graph.nodeById.has(id)));
        this.portExpanded = new Set([...this.portExpanded].filter(id => this.graph.nodeById.has(id)));
        this.graph.roots.forEach(root => this.expandSourceChain(root));
        this.selectedId = this.graph.nodeById.has(previousSelection) ? previousSelection : '';
        this.render();
        if (this.selectedId) this.onSelect?.(this.graph.nodeById.get(this.selectedId));
    }

    reset() {
        this.selectedId = '';
        this.query = '';
        if (this.searchInput) this.searchInput.value = '';
        this.expanded.clear();
        this.portExpanded.clear();
        this.graph.roots.forEach(root => this.expandSourceChain(root));
        this.render();
        this.onSelect?.(null);
        this.announce('Pilihan tree direset.');
    }

    selectedNode() {
        return this.graph.nodeById.get(this.selectedId) ?? null;
    }

    expandSourceChain(node, visited = new Set()) {
        if (!node || visited.has(node.id)) return;
        visited.add(node.id);
        this.expanded.add(node.id);
        (this.graph.childrenById.get(node.id) ?? []).forEach(child => {
            if (child.node.type === 'Sumber') this.expandSourceChain(child.node, visited);
        });
    }

    selectByAssetId(assetId) {
        const node = this.graph.nodes.find(candidate => candidate.assetId === String(assetId));
        if (!node) return false;
        this.selectNode(node.id, true);
        return true;
    }

    bindEvents() {
        this.searchInput?.addEventListener('input', event => {
            this.query = normalizeText(event.target.value);
            this.render();
        });

        this.canvas?.addEventListener('click', event => {
            const port = event.target.closest('[data-port-assign-node]');
            if (port) {
                const node = this.graph.nodeById.get(port.dataset.portAssignNode);
                if (node) {
                    this.selectNode(node.id, false);
                    this.onPortSelect?.(node, port.dataset.portName);
                }
                return;
            }
            const portToggle = event.target.closest('[data-node-port-toggle]');
            if (portToggle) {
                this.togglePortPanel(portToggle.dataset.nodePortToggle, true);
                return;
            }
            const toggle = event.target.closest('[data-node-toggle]');
            if (toggle) {
                this.toggleNode(toggle.dataset.nodeToggle, true);
                return;
            }
            const card = event.target.closest('[data-node-card]');
            if (card) this.selectNode(card.dataset.nodeCard, true);
        });

        this.canvas?.addEventListener('keydown', event => {
            const card = event.target.closest('[data-node-card]');
            if (!card) return;
            const id = card.dataset.nodeCard;
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                const children = this.graph.childrenById.get(id) ?? [];
                if (!this.expanded.has(id) && children.length) this.toggleNode(id, true);
                else if (children[0]) this.focusNode(children[0].node.id);
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                if (this.expanded.has(id) && (this.graph.childrenById.get(id)?.length ?? 0)) this.toggleNode(id, false);
                else this.focusNode(this.graph.parentsById.get(id)?.[0]?.node.id);
            } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                const cards = [...this.canvas.querySelectorAll('[data-node-card]')];
                const current = cards.indexOf(card);
                const next = event.key === 'Home'
                    ? cards[0]
                    : event.key === 'End'
                        ? cards.at(-1)
                        : cards[event.key === 'ArrowDown' ? Math.min(cards.length - 1, current + 1) : Math.max(0, current - 1)];
                next?.focus();
            }
        });
    }

    selectNode(id, restoreFocus = false) {
        const node = this.graph.nodeById.get(id);
        if (!node) return;
        this.selectedId = id;
        if ((this.graph.childrenById.get(id)?.length ?? 0) > 0) this.expanded.add(id);
        this.expandAncestors(id);
        this.render();
        this.onSelect?.(node);
        this.announce(`${node.type} ${node.label} dipilih.`);
        if (restoreFocus) requestAnimationFrame(() => this.focusNode(id));
    }

    toggleNode(id, restoreFocus = false) {
        if (!this.graph.nodeById.has(id)) return;
        if (this.expanded.has(id)) this.expanded.delete(id);
        else {
            this.expanded.add(id);
            this.expandAncestors(id);
        }
        this.render();
        if (restoreFocus) requestAnimationFrame(() => this.focusToggle(id));
    }

    togglePortPanel(id, restoreFocus = false) {
        if (!this.graph.nodeById.has(id)) return;
        if (this.portExpanded.has(id)) this.portExpanded.delete(id);
        else this.portExpanded.add(id);
        this.render();
        this.announce(`${this.portExpanded.has(id) ? 'Daftar port dibuka' : 'Daftar port ditutup'}.`);
        if (restoreFocus) requestAnimationFrame(() => {
            [...this.canvas.querySelectorAll('[data-node-port-toggle]')]
                .find(button => button.dataset.nodePortToggle === id)?.focus();
        });
    }

    expandAncestors(id) {
        const visited = new Set();
        let current = id;
        while (current && !visited.has(current)) {
            visited.add(current);
            const parent = this.graph.parentsById.get(current)?.[0]?.node;
            if (!parent) break;
            this.expanded.add(parent.id);
            current = parent.id;
        }
    }

    focusNode(id) {
        if (!id) return;
        [...this.canvas.querySelectorAll('[data-node-card]')].find(card => card.dataset.nodeCard === id)?.focus();
    }

    focusToggle(id) {
        [...this.canvas.querySelectorAll('[data-node-toggle]')].find(button => button.dataset.nodeToggle === id)?.focus();
    }

    searchRelevantIds() {
        if (!this.query) return null;
        const relevant = new Set();
        this.graph.nodes.filter(node => nodeSearchText(node).includes(this.query)).forEach(node => {
            relevant.add(node.id);
            const queue = [node.id];
            const visited = new Set();
            while (queue.length) {
                const childId = queue.shift();
                if (visited.has(childId)) continue;
                visited.add(childId);
                (this.graph.parentsById.get(childId) ?? []).forEach(parent => {
                    relevant.add(parent.node.id);
                    queue.push(parent.node.id);
                });
            }
        });
        return relevant;
    }

    selectedPathIds() {
        const selectedPath = new Set();
        if (!this.selectedId) return selectedPath;
        const queue = [this.selectedId];
        while (queue.length) {
            const id = queue.shift();
            if (selectedPath.has(id)) continue;
            selectedPath.add(id);
            (this.graph.parentsById.get(id) ?? []).forEach(parent => queue.push(parent.node.id));
        }
        return selectedPath;
    }

    nodeMarkup(node, incomingEdge, path, relevant, selectedPath) {
        if (path.has(node.id)) {
            return '<li class="at-tree-cycle" role="treeitem">Siklus data terdeteksi</li>';
        }
        const nextPath = new Set(path).add(node.id);
        const allChildren = this.graph.childrenById.get(node.id) ?? [];
        const children = relevant ? allChildren.filter(child => relevant.has(child.node.id)) : allChildren;
        const forceOpenForSearch = Boolean(this.query && children.length);
        const open = children.length > 0 && (this.expanded.has(node.id) || forceOpenForSearch);
        const selected = node.id === this.selectedId;
        const inSelectedPath = selectedPath.has(node.id);
        const relation = normalizeRelation(incomingEdge?.relation || node.relation);
        const parent = incomingEdge ? this.graph.nodeById.get(incomingEdge.from) : null;
        const skippedStages = parent ? stageGap(parent.type, node.type) : [];
        const edgeClass = skippedStages.length ? 'gap' : relation;
        const relationLabel = {
            verified: 'Parent cocok',
            lld: 'Sesuai LLD',
            inferred: 'Parent terduga',
            unconnected: 'Belum terhubung',
            conceptual: 'Konseptual',
            gap: 'Tahap dilewati',
        }[edgeClass] ?? 'Relasi';
        const asset = node.asset ?? {};
        const traffic = nodeTrafficState(asset);
        const portState = nodePortState(node);
        const portPanelOpen = this.portExpanded.has(node.id);
        const portPanel = nodePortPanelMarkup(node, portPanelOpen);
        const identifier = value(node.assetId, node.raw?.id, node.virtual ? 'ROOT' : '');
        const model = value(asset.model, asset.brand, node.raw?.role, displayAssetDetail(node));
        const typeClass = normalizeText(node.type).replace(/\s+/g, '-');
        const showModel = model
            && normalizeText(model) !== normalizeText(node.label)
            && normalizeText(model) !== normalizeText(identifier);
        const fromPort = value(incomingEdge?.fromPort, incomingEdge?.raw?.fromPort, incomingEdge?.raw?.from_port);
        const toPort = value(incomingEdge?.toPort, incomingEdge?.raw?.toPort, incomingEdge?.raw?.to_port);
        const physicalCable = parent?.type === 'Switch LAN'
            && node.type === 'Access Point'
            && Boolean(fromPort || toPort);
        const cableDescription = physicalCable
            ? `Switch port ${fromPort || '?'} menuju Access Point port ${toPort || '?'}`
            : '';

        return `
            <li class="at-tree-item is-${escapeHtml(edgeClass)} is-type-${escapeHtml(typeClass)} ${physicalCable ? 'has-physical-cable' : ''} ${portPanelOpen ? 'has-port-panel' : ''} ${selected ? 'is-selected' : ''} ${inSelectedPath ? 'is-selected-path' : ''}" role="treeitem" aria-selected="${selected}" aria-expanded="${children.length ? String(open) : 'false'}" data-tree-node="${escapeHtml(node.id)}">
                ${physicalCable ? `<span class="at-cable-port is-source" title="${escapeHtml(cableDescription)}"><small>SW</small><strong>P${escapeHtml(fromPort || '?')}</strong></span>
                    <span class="at-cable-port is-target" title="${escapeHtml(cableDescription)}"><small>AP</small><strong>P${escapeHtml(toPort || '?')}</strong></span>` : ''}
                ${skippedStages.length ? `<span class="at-edge-gap">Lewati ${escapeHtml(skippedStages.join(' + '))}</span>` : ''}
                <div class="at-node-shell">
                    <button class="at-node-card is-${escapeHtml(typeClass)}" type="button" data-node-card="${escapeHtml(node.id)}" aria-label="${escapeHtml(`${node.type} ${node.label}. ${relationLabel}${traffic ? `. ${traffic.label}` : ''}${portState ? `. ${portState.label}` : ''}`)}">
                        <span class="at-node-icon is-${escapeHtml(typeClass)}" aria-hidden="true">${iconForType(node.type)}</span>
                        <span class="at-node-copy">
                            <span class="at-node-type">${escapeHtml(node.type)}</span>
                            <strong title="${escapeHtml(node.label)}">${escapeHtml(node.label)}</strong>
                            <small title="${escapeHtml(identifier)}">${escapeHtml(identifier || model || 'Node topologi')}</small>
                        </span>
                        <span class="at-relation-dot" title="${escapeHtml(relationLabel)}" aria-hidden="true"></span>
                        ${showModel ? `<span class="at-node-meta" title="${escapeHtml(model)}">${escapeHtml(model)}</span>` : ''}
                        ${traffic || portState ? `<span class="at-node-statuses">
                            ${traffic ? `<span class="at-node-log is-${escapeHtml(traffic.status)}"><span aria-hidden="true"></span>${escapeHtml(traffic.label)}</span>` : ''}
                            ${portState ? `<span class="at-node-ports is-${escapeHtml(portState.tone)}"><span aria-hidden="true"></span>${escapeHtml(portState.label)}</span>` : ''}
                        </span>` : ''}
                    </button>
                    ${portPanel}
                    ${children.length ? `<button class="at-node-toggle" type="button" data-node-toggle="${escapeHtml(node.id)}" aria-label="${open ? 'Tutup' : 'Buka'} cabang ${escapeHtml(node.label)}" aria-expanded="${open}"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></button>` : ''}
                </div>
                ${open ? `<ul class="at-tree-children" role="group">${children.map(child => this.nodeMarkup(child.node, child.edge, nextPath, relevant, selectedPath)).join('')}</ul>` : ''}
            </li>`;
    }

    render() {
        const { nodes, edges, roots } = this.graph;
        const relevant = this.searchRelevantIds();
        const matchedCount = relevant
            ? nodes.filter(node => nodeSearchText(node).includes(this.query)).length
            : nodes.filter(node => !node.virtual).length;
        const visibleRoots = relevant ? roots.filter(root => relevant.has(root.id)) : roots;
        const hasData = nodes.some(node => !node.virtual);

        if (this.summaryElement) {
            this.summaryElement.textContent = this.query
                ? `${matchedCount} node cocok dari ${nodes.filter(node => !node.virtual).length} node`
                : `${nodes.filter(node => !node.virtual).length} node · ${edges.filter(edge => !edge.id.startsWith('virtual-')).length} koneksi`;
        }
        if (this.stateElement) {
            this.stateElement.hidden = hasData;
            this.stateElement.innerHTML = hasData
                ? ''
                : '<strong>Topologi belum tersedia</strong><p>Pilih lokasi lain atau tambahkan data lokasi.</p>';
        }
        this.canvas.hidden = !hasData;
        this.canvas.innerHTML = hasData
            ? `<ul class="at-tree-root" role="tree" aria-label="Topologi perangkat">${visibleRoots.map(root => this.nodeMarkup(root, null, new Set(), relevant, this.selectedPathIds())).join('')}</ul>`
            : '';
        this.onRendered?.({ selectedId: this.selectedId, hasData });
    }

    announce(message) {
        if (this.announcer) this.announcer.textContent = message;
    }
}
