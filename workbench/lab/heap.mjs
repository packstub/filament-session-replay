// Sums a V8 heap snapshot (.heapsnapshot, from memory.mjs with HEAP=1) by constructor name: count and self
// size, biggest first, and the largest strings. node heap.mjs out/heap-navigation-on.heapsnapshot [top]
import { readFileSync } from 'node:fs';

const [file, top = '40'] = process.argv.slice(2);
const snapshot = JSON.parse(readFileSync(file, 'utf8'));
const { node_fields: fields, node_types: types } = snapshot.snapshot.meta;
const { nodes, strings } = snapshot;
const width = fields.length;
const TYPE = fields.indexOf('type');
const NAME = fields.indexOf('name');
const SIZE = fields.indexOf('self_size');
const typeNames = types[TYPE];

const byName = new Map();
const bigStrings = [];

for (let i = 0; i < nodes.length; i += width) {
    const type = typeNames[nodes[i + TYPE]];
    const name = strings[nodes[i + NAME]];
    const size = nodes[i + SIZE];
    const key = type === 'object' || type === 'closure' || type === 'native' ? `${type}:${name}` : type === 'string' || type === 'concatenated string' || type === 'sliced string' ? 'string' : type === 'array' ? `array:${name}` : type;
    const entry = byName.get(key) || { count: 0, size: 0 };

    entry.count++;
    entry.size += size;
    byName.set(key, entry);

    if ((type === 'string' || type === 'concatenated string' || type === 'sliced string') && size > 100_000) bigStrings.push({ size, head: name.slice(0, 90).replace(/\s+/g, ' ') });
}

const total = [...byName.values()].reduce((sum, entry) => sum + entry.size, 0);

console.log(`total ${(total / 1048576).toFixed(1)} MB, ${nodes.length / width} nodes`);
console.table([...byName.entries()].sort((a, b) => b[1].size - a[1].size).slice(0, Number(top)).map(([name, entry]) => ({ name: name.slice(0, 60), count: entry.count, mb: +(entry.size / 1048576).toFixed(2) })));
console.log('strings over 100 kB:');
console.table(bigStrings.sort((a, b) => b.size - a.size).slice(0, 25).map((string) => ({ kb: Math.round(string.size / 1024), head: string.head })));
