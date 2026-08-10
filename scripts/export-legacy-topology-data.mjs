import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const [assetsSource, topologySource, outputDirectory] = process.argv.slice(2);

if (!assetsSource || !topologySource || !outputDirectory) {
    console.error('Usage: node scripts/export-legacy-topology-data.mjs <assets.js> <topology.js> <output-directory>');
    process.exit(1);
}

const evaluateLegacySource = (sourcePath, property) => {
    const sandbox = { window: {} };
    vm.runInNewContext(fs.readFileSync(sourcePath, 'utf8'), sandbox, {
        filename: sourcePath,
        timeout: 5_000,
    });

    const value = sandbox.window[property];
    if (!value || typeof value !== 'object') {
        throw new Error(`${property} was not defined by ${sourcePath}`);
    }

    return value;
};

const assets = evaluateLegacySource(assetsSource, 'PLN_DATA');
const topology = evaluateLegacySource(topologySource, 'PLN_TOPOLOGY');

fs.mkdirSync(outputDirectory, { recursive: true });
fs.writeFileSync(
    path.join(outputDirectory, 'base-assets.json'),
    `${JSON.stringify(assets, null, 2)}\n`,
);
fs.writeFileSync(
    path.join(outputDirectory, 'baturaja-topology.json'),
    `${JSON.stringify(topology, null, 2)}\n`,
);

console.log(JSON.stringify({
    assets: Array.isArray(assets.assets) ? assets.assets.length : 0,
    nodes: Array.isArray(topology.nodes) ? topology.nodes.length : 0,
    links: Array.isArray(topology.links) ? topology.links.length : 0,
    outputDirectory: path.resolve(outputDirectory),
}));
