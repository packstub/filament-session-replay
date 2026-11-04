// Who keeps rrweb's serialized nodes alive: counts the plain objects with a `tagName` property in a heap
// snapshot (a serialized element) and prints the shortest retainer path from the GC roots to a few of them.
// node retainers.mjs out/heap-navigation-on.heapsnapshot [samples]
import { readFileSync } from 'node:fs';

const [file, samplesWanted = '4'] = process.argv.slice(2);
const snapshot = JSON.parse(readFileSync(file, 'utf8'));
const meta = snapshot.snapshot.meta;
const { nodes, edges, strings } = snapshot;
const nodeWidth = meta.node_fields.length;
const edgeWidth = meta.edge_fields.length;
const N_TYPE = meta.node_fields.indexOf('type');
const N_NAME = meta.node_fields.indexOf('name');
const N_EDGES = meta.node_fields.indexOf('edge_count');
const E_TYPE = meta.edge_fields.indexOf('type');
const E_NAME = meta.edge_fields.indexOf('name_or_index');
const E_TO = meta.edge_fields.indexOf('to_node');
const nodeTypes = meta.node_types[N_TYPE];
const edgeTypes = meta.edge_types[E_TYPE];
const count = nodes.length / nodeWidth;

// First edge index of every node.
const firstEdge = new Uint32Array(count + 1);

for (let i = 0, edge = 0; i < count; i++) {
    firstEdge[i] = edge;
    edge += nodes[i * nodeWidth + N_EDGES] * edgeWidth;
}

firstEdge[count] = edges.length;

const nodeType = (i) => nodeTypes[nodes[i * nodeWidth + N_TYPE]];
const nodeName = (i) => strings[nodes[i * nodeWidth + N_NAME]];
const edgeName = (e) => (['element', 'hidden'].includes(edgeTypes[edges[e + E_TYPE]]) ? `[${edges[e + E_NAME]}]` : strings[edges[e + E_NAME]]);

// Reverse edges: retainers of every node (weak edges left out: they keep nothing alive).
const retainerCount = new Uint32Array(count + 1);

for (let i = 0; i < count; i++) {
    for (let e = firstEdge[i]; e < firstEdge[i + 1]; e += edgeWidth) if (edgeTypes[edges[e + E_TYPE]] !== 'weak') retainerCount[edges[e + E_TO] / nodeWidth + 1]++;
}

for (let i = 1; i <= count; i++) retainerCount[i] += retainerCount[i - 1];

const retainerNode = new Uint32Array(retainerCount[count]);
const retainerEdge = new Uint32Array(retainerCount[count]);
const fill = Uint32Array.from(retainerCount);

for (let i = 0; i < count; i++) {
    for (let e = firstEdge[i]; e < firstEdge[i + 1]; e += edgeWidth) {
        if (edgeTypes[edges[e + E_TYPE]] === 'weak') continue;

        const to = edges[e + E_TO] / nodeWidth;

        retainerNode[fill[to]] = i;
        retainerEdge[fill[to]] = e;
        fill[to]++;
    }
}

// Serialized elements: plain objects with a "tagName" and a "childNodes" property.
const serialized = [];

for (let i = 0; i < count; i++) {
    if (nodeType(i) !== 'object' || nodeName(i) !== 'Object') continue;

    let tag = false;
    let children = false;

    for (let e = firstEdge[i]; e < firstEdge[i + 1]; e += edgeWidth) {
        if (edgeTypes[edges[e + E_TYPE]] !== 'property') continue;

        const name = edgeName(e);

        if (name === 'tagName') tag = true;
        if (name === 'childNodes') children = true;
    }

    if (tag && children) serialized.push(i);
}

console.log(`${serialized.length} serialized elements among ${count} heap nodes`);

/** Shortest path from a GC root to `target`, as "edge → node" steps. */
function path(target) {
    const previous = new Map([[target, null]]);
    const queue = [target];

    while (queue.length) {
        const current = queue.shift();

        if (nodeType(current) === 'synthetic' && nodeName(current).startsWith('(GC roots)')) {
            const steps = [];

            for (let node = current; node !== null; node = previous.get(node)?.node ?? null) {
                const via = previous.get(node);

                steps.push(`${nodeType(node)}:${nodeName(node).slice(0, 50)}${via ? ` --${edgeName(via.edge)}-->` : ''}`);
            }

            return steps.join(' ');
        }

        for (let r = retainerCount[current]; r < retainerCount[current + 1]; r++) {
            const node = retainerNode[r];

            if (!previous.has(node)) {
                previous.set(node, { node: current, edge: retainerEdge[r] });
                queue.push(node);
            }
        }
    }

    return '(unreachable: garbage)';
}

const step = Math.max(1, Math.floor(serialized.length / Number(samplesWanted)));

for (let k = 0; k < serialized.length; k += step) {
    console.log(`\n#${k}: ${path(serialized[k])}`);
}
