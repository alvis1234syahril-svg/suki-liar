import { spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const edgePath = process.env.EDGE_PATH
    || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const targetUrl = process.env.TEST_URL || 'http://127.0.0.1:8090/asset-topology';
const debugPort = Number(process.env.DEBUG_PORT || 9338);
const viewport = process.env.VIEWPORT || '1440,1200';
const profilePath = await mkdtemp(path.join(os.tmpdir(), 'topology-smoke-'));
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

const edge = spawn(edgePath, [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${debugPort}`,
    `--user-data-dir=${profilePath}`,
    `--window-size=${viewport}`,
    'about:blank',
], { stdio: 'ignore' });

async function debugTarget() {
    for (let attempt = 0; attempt < 80; attempt += 1) {
        try {
            const response = await fetch(`http://127.0.0.1:${debugPort}/json/list`);
            const targets = await response.json();
            const page = targets.find(target => target.type === 'page');
            if (page?.webSocketDebuggerUrl) return page;
        } catch {
            // Edge is still starting.
        }
        await delay(100);
    }
    throw new Error('Tidak dapat terhubung ke Edge DevTools.');
}

function cdpClient(webSocketUrl) {
    const socket = new WebSocket(webSocketUrl);
    const pending = new Map();
    const listeners = new Map();
    let sequence = 0;
    const opened = new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });

    socket.addEventListener('message', ({ data }) => {
        const message = JSON.parse(data);
        if (message.id && pending.has(message.id)) {
            const request = pending.get(message.id);
            pending.delete(message.id);
            if (message.error) request.reject(new Error(message.error.message));
            else request.resolve(message.result);
            return;
        }
        for (const listener of listeners.get(message.method) ?? []) listener(message.params);
    });

    return {
        async send(method, params = {}) {
            await opened;
            const id = ++sequence;
            const result = new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
            socket.send(JSON.stringify({ id, method, params }));
            return result;
        },
        on(method, listener) {
            if (!listeners.has(method)) listeners.set(method, []);
            listeners.get(method).push(listener);
        },
        close() {
            socket.close();
        },
    };
}

let client;

try {
    client = cdpClient((await debugTarget()).webSocketDebuggerUrl);
    const errors = [];
    client.on('Runtime.exceptionThrown', event => errors.push(event.exceptionDetails?.text || 'Runtime exception'));
    client.on('Log.entryAdded', event => {
        if (event.entry?.level === 'error') errors.push(event.entry.text);
    });
    await client.send('Runtime.enable');
    await client.send('Log.enable');
    await client.send('Page.enable');
    await client.send('Page.navigate', { url: targetUrl });

    let ready = false;
    for (let attempt = 0; attempt < 100; attempt += 1) {
        const result = await client.send('Runtime.evaluate', {
            expression: `document.querySelector('[data-topology-app]')?.getAttribute('aria-busy') === 'false'`,
            returnByValue: true,
        });
        if (result.result?.value === true) {
            ready = true;
            break;
        }
        await delay(100);
    }
    if (!ready) throw new Error('Halaman topologi tidak selesai memuat.');

    const evaluation = await client.send('Runtime.evaluate', {
        awaitPromise: true,
        returnByValue: true,
        expression: `(async () => {
            const settle = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const result = {
                activeNavigation: Boolean(document.querySelector('a[href$="/asset-topology"].is-active')),
                analysisHref: [...document.querySelectorAll('nav a')]
                    .find(link => link.textContent.includes('Analisis Baru'))?.href,
                location: document.querySelector('[data-location-title]')?.textContent.trim(),
                metricCards: document.querySelectorAll('[data-metrics] > *').length,
                trafficPanel: Boolean(document.querySelector('[data-traffic-panel]')?.textContent.trim()),
                importExcel: Boolean(document.querySelector('[data-open-import-dialog]')),
                initialNodes: document.querySelectorAll('[data-node-card]').length,
                documentOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };

            const firewallCard = [...document.querySelectorAll('[data-node-card]')]
                .find(card => card.querySelector('.at-node-type')?.textContent.trim() === 'Firewall');
            firewallCard?.click();
            await settle();
            const firewallToggle = firewallCard?.closest('.at-node-shell')?.querySelector('[data-node-toggle]');
            if (firewallToggle?.getAttribute('aria-expanded') === 'false') firewallToggle.click();
            await settle();
            result.expandedNodes = document.querySelectorAll('[data-node-card]').length;

            const zoomBefore = document.querySelector('[data-zoom-value]')?.textContent.trim();
            document.querySelector('[data-zoom-in]')?.click();
            await settle();
            result.zoomBefore = zoomBefore;
            result.zoomAfter = document.querySelector('[data-zoom-value]')?.textContent.trim();

            const routerCard = [...document.querySelectorAll('[data-node-card]')]
                .find(card => card.querySelector('.at-node-type')?.textContent.trim() === 'Router');
            routerCard?.click();
            await settle();
            result.inspectorUpdated = !document.querySelector('[data-inspector]')?.textContent.includes('Pilih sebuah node');

            const switchCards = [...document.querySelectorAll('[data-node-card]')]
                .filter(card => card.querySelector('.at-node-type')?.textContent.trim() === 'Switch LAN');
            const switchCard = switchCards.find(card => card.dataset.nodeCard === 'switch-core')
                || switchCards.find(card => card.closest('.at-node-shell')?.querySelector('[data-node-toggle]'))
                || switchCards[0];
            switchCard?.click();
            await settle();
            const switchToggle = switchCard?.closest('.at-node-shell')?.querySelector('[data-node-toggle]');
            if (switchToggle?.getAttribute('aria-expanded') === 'false') switchToggle.click();
            await settle();
            result.cableRoutes = document.querySelectorAll('.at-cable-port.is-source').length;
            result.cableLabels = [...document.querySelectorAll('.at-cable-port')].map(label => label.textContent.trim());

            const portDisclosure = document.querySelector('[data-node-port-toggle]');
            result.portDisclosure = Boolean(portDisclosure);
            result.portsInitiallyCollapsed = document.querySelectorAll('[data-port-assign-node]').length === 0;
            portDisclosure?.click();
            await settle();
            const physicalPort = document.querySelector('[data-port-assign-node]');
            result.physicalPorts = document.querySelectorAll('[data-port-assign-node]').length;
            physicalPort?.click();
            await settle();
            const portDialog = document.querySelector('[data-port-dialog]');
            result.portDialog = Boolean(portDialog?.open);
            result.portAccessPoints = Math.max(0, (portDialog?.querySelector('[data-port-access-point]')?.options.length || 1) - 1);
            portDialog?.querySelector('[data-close-dialog]')?.click();
            await settle();

            document.querySelector('[data-tab="master"]')?.click();
            await settle();
            result.masterRows = document.querySelectorAll('[data-master-body] tr').length;
            result.masterLogColumn = [...document.querySelectorAll('.at-table thead th')]
                .some(cell => cell.textContent.trim() === 'Log terbaru');
            return result;
        })()`,
    });

    const result = evaluation.result?.value ?? {};
    const screenshotPath = process.env.SCREENSHOT_PATH;
    if (screenshotPath) {
        await client.send('Runtime.evaluate', {
            awaitPromise: true,
            expression: `(async () => {
                const settle = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                document.querySelector('[data-tab="explorer"]')?.click();
                await settle();
                const disclosure = document.querySelector('[data-node-port-toggle][aria-expanded="true"]');
                disclosure?.click();
                await settle();
                const switchIds = [...document.querySelectorAll('[data-node-card]')]
                    .filter(card => card.querySelector('.at-node-type')?.textContent.trim() === 'Switch LAN')
                    .map(card => card.dataset.nodeCard);
                for (const switchId of switchIds) {
                    const card = [...document.querySelectorAll('[data-node-card]')]
                        .find(candidate => candidate.dataset.nodeCard === switchId);
                    const toggle = card?.closest('.at-node-shell')?.querySelector('[data-node-toggle][aria-expanded="false"]');
                    toggle?.click();
                    await settle();
                }
                document.querySelector('[data-zoom-fit]')?.click();
                await settle();
                const viewport = document.querySelector('[data-tree-viewport]');
                viewport?.scrollTo({ left: 0, top: 0, behavior: 'instant' });
                viewport?.scrollIntoView({ block: 'start' });
                await settle();
            })()`,
        });
        const treeBounds = await client.send('Runtime.evaluate', {
            returnByValue: true,
            expression: `(() => {
                const rect = document.querySelector('[data-tree-viewport]')?.getBoundingClientRect();
                return rect ? {
                    x: Math.max(0, rect.left + scrollX),
                    y: Math.max(0, rect.top + scrollY),
                    width: Math.max(1, rect.width),
                    height: Math.max(1, rect.height),
                } : null;
            })()`,
        });
        const screenshot = await client.send('Page.captureScreenshot', {
            format: 'png',
            captureBeyondViewport: true,
            ...(treeBounds.result?.value ? { clip: { ...treeBounds.result.value, scale: 1 } } : {}),
        });
        await writeFile(screenshotPath, Buffer.from(screenshot.data, 'base64'));
    }
    if (result.analysisHref) {
        await client.send('Page.navigate', { url: result.analysisHref });
        for (let attempt = 0; attempt < 80; attempt += 1) {
            const snapshot = await client.send('Runtime.evaluate', {
                expression: `({
                    title: document.title,
                    styled: document.body?.classList.contains('theme-electric')
                        && [...document.styleSheets].some(sheet => sheet.href?.includes('/build/assets/app-')),
                    heading: document.querySelector('h1')?.textContent.trim(),
                    path: location.pathname,
                })`,
                returnByValue: true,
            });
            const value = snapshot.result?.value;
            if (value?.title === 'Analisis Baru') {
                result.analysisNavigation = value;
                break;
            }
            await delay(100);
        }
    }
    const checks = {
        ...result,
        consoleErrors: errors,
        passed: result.activeNavigation
            && result.location === 'UPT BATURAJA'
            && result.metricCards >= 6
            && result.trafficPanel
            && result.importExcel
            && result.initialNodes >= 3
            && result.expandedNodes > result.initialNodes
            && result.zoomBefore !== result.zoomAfter
            && result.inspectorUpdated
            && result.cableRoutes > 0
            && result.cableLabels.some(label => label.startsWith('SWP'))
            && result.cableLabels.some(label => label.startsWith('APP'))
            && result.portDisclosure
            && result.portsInitiallyCollapsed
            && result.physicalPorts > 0
            && result.portDialog
            && result.portAccessPoints > 0
            && result.masterRows > 0
            && result.masterLogColumn
            && result.documentOverflow === false
            && result.analysisNavigation?.path === '/analysis/new'
            && result.analysisNavigation?.title === 'Analisis Baru'
            && result.analysisNavigation?.heading === 'Analisis Baru'
            && result.analysisNavigation?.styled === true
            && errors.length === 0,
    };
    console.log(JSON.stringify(checks, null, 2));
    if (!checks.passed) process.exitCode = 1;
} finally {
    client?.close();
    if (edge.exitCode === null) {
        edge.kill();
        await Promise.race([
            new Promise(resolve => edge.once('exit', resolve)),
            delay(2_000),
        ]);
    }
    await rm(profilePath, {
        recursive: true,
        force: true,
        maxRetries: 8,
        retryDelay: 250,
    });
}
