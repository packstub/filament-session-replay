// What recording a Filament panel costs in memory: the JS heap, the DOM nodes kept alive and the renderer's
// RSS, sampled every few seconds while a page does the things a panel does all day, with the recorder on and
// off. Made for a report of 700-800 MB per page (2026-10-28); the numbers in docs/ and in the core's
// docs/storage.md come from here. The server's knobs are env vars, so one server per setup, each on its port:
//
//   composer serve                                                              (the panel as it is, :8000)
//   LAB_SPA=1 php vendor/bin/testbench serve --port=8001                        (wire:navigate, for `navigation`)
//   LAB_ROWS=1000 LAB_ROWS_PER_PAGE=500 php vendor/bin/testbench serve --port=8002   (a 6.7 MB page)
//   SESSION_REPLAY_MODE=on_error LAB_ROWS_PER_PAGE=500 php vendor/bin/testbench serve --port=8003
//   cd workbench/lab && npm install && BASE=http://127.0.0.1:8002 node memory.mjs [scenario ...]
//
// BASE=http://127.0.0.1:8000 by default; SECONDS=120 per scenario; EVERY=5 seconds between samples;
// RECORDER=0 skips the runs with the recorder off; HEAP=1 writes a heap snapshot per run for heap.mjs;
// INGEST=slow|fail|limit stands in for a server that answers uploads after 20 s, with a 500, or with a 429;
// RECORDER=path serves that recorder build; PROFILE=1 writes a CPU profile per run, summed by function.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8000';
const SECONDS = Number(process.env.SECONDS ?? 120);
const EVERY = Number(process.env.EVERY ?? 5);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

mkdirSync('out', { recursive: true });

async function during(seconds, step) {
    const until = Date.now() + seconds * 1000;

    for (let round = 0; Date.now() < until; round++) {
        try {
            await step(round);
        } catch (error) {
            console.error('  step failed:', error.message.split('\n')[0]);
            await sleep(500);
        }
    }
}

const scenarios = {
    'polled-table': { url: '/admin/orders?poll=1', run: () => sleep(SECONDS * 1000) },
    'idle-page': { url: '/admin/orders', run: () => sleep(SECONDS * 1000) },
    'table-work': {
        url: '/admin/orders',
        run: (page) => during(SECONDS, async (round) => {
            const search = page.locator('.fi-ta-search-field input').first();

            await search.fill(['ada', 'grace', 'ORD-001', ''][round % 4]);
            await sleep(1500);
            await page.locator('.fi-ta-header-cell button', { hasText: ['Reference', 'Customer', 'Total'][round % 3] }).first().click();
            await sleep(1500);
            await page.mouse.wheel(0, 900);
            await sleep(700);
            await page.mouse.wheel(0, -900);
            await sleep(700);
        }),
    },
    modals: {
        url: '/admin/orders',
        run: (page) => during(SECONDS, async (round) => {
            await page.locator('.fi-ta-row').nth(round % 10).getByRole('button', { name: 'Edit' }).click();
            await page.locator('.fi-modal-window input[id$="customer"]').fill(`Customer ${round}`);
            await page.locator('.fi-modal-window input[id$="total"]').fill(String(1000 + round));
            await sleep(800);
            await page.locator('.fi-modal-window').getByRole('button', { name: 'Save changes' }).click();
            await page.locator('.fi-modal-window').waitFor({ state: 'hidden' });
            await sleep(1200);
        }),
    },
    navigation: {
        url: '/admin',
        run: (page) => during(SECONDS, async (round) => {
            await page.locator('.fi-sidebar-item a', { hasText: ['Orders', 'Users', 'Dashboard'][round % 3] }).first().click();
            await page.waitForLoadState('networkidle');
            await sleep(1500);
        }),
    },
};

/** RSS (MB) of this browser's renderer processes, summed (the browser's own CDP session names their pids). */
async function rendererRss(browserCdp) {
    try {
        const { processInfo } = await browserCdp.send('SystemInfo.getProcessInfo');
        const pids = processInfo.filter((process) => process.type === 'renderer').map((process) => String(process.id));

        if (pids.length === 0) return 0;

        const lines = execFileSync('ps', ['-o', 'rss=', '-p', pids.join(',')], { encoding: 'utf8' }).trim().split('\n');

        return Math.round(lines.reduce((sum, line) => sum + Number(line.trim() || 0), 0) / 1024);
    } catch {
        return null;
    }
}

async function sample(cdp, page, browserCdp) {
    const { metrics } = await cdp.send('Performance.getMetrics');
    const get = (name) => metrics.find((metric) => metric.name === name)?.value ?? 0;
    const recorder = await page.evaluate(() => (window.__srLab ? window.__srLab() : null)).catch(() => null);

    return {
        t: 0,
        heapMb: +(get('JSHeapUsedSize') / 1048576).toFixed(1),
        heapTotalMb: +(get('JSHeapTotalSize') / 1048576).toFixed(1),
        nodes: get('Nodes'),
        listeners: get('JSEventListeners'),
        documents: get('Documents'),
        rssMb: await rendererRss(browserCdp),
        recorder,
    };
}

async function run(name, { recorder = true } = {}) {
    const scenario = scenarios[name];
    const browser = await chromium.launch({ args: ['--js-flags=--expose-gc'] });
    const browserCdp = await browser.newBrowserCDPSession();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    if (!recorder) await context.route(/session-replay\/scripts\/recorder\.js/, (route) => route.abort());
    // RECORDER=path serves that build (an unminified one, for PROFILE=1).
    else if (process.env.RECORDER) await context.route(/session-replay\/scripts\/recorder\.js/, (route) => route.fulfill({ status: 200, contentType: 'application/javascript', body: readFileSync(process.env.RECORDER) }));

    if (process.env.INGEST) {
        await context.route(/\/session-replay\/ingest/, async (route) => {
            if (process.env.INGEST === 'slow') {
                await sleep(20_000);

                return route.continue();
            }

            return route.fulfill({ status: process.env.INGEST === 'limit' ? 429 : 500, contentType: 'application/json', body: '{}' });
        });
    }

    const page = await context.newPage();
    const cdp = await context.newCDPSession(page);

    await cdp.send('Performance.enable');
    await cdp.send('HeapProfiler.enable');

    // PROFILE=1: a CPU profile of the run, summed by function in out/profile-<scenario>-<on|off>.txt.
    if (process.env.PROFILE === '1') {
        await cdp.send('Profiler.enable');
        await cdp.send('Profiler.setSamplingInterval', { interval: 500 });
        await cdp.send('Profiler.start');
    }

    const samples = [];
    const started = Date.now();

    await page.goto(BASE + scenario.url);
    await page.waitForLoadState('networkidle');

    const ticker = setInterval(async () => {
        try {
            const point = await sample(cdp, page, browserCdp);

            point.t = Math.round((Date.now() - started) / 1000);
            samples.push(point);
            console.error(`  ${name} ${recorder ? 'on ' : 'off'} t=${point.t}s heap=${point.heapMb}MB nodes=${point.nodes} listeners=${point.listeners} rss=${point.rssMb}MB ${point.recorder ? JSON.stringify(point.recorder) : ''}`);
        } catch {
            // The page went away.
        }
    }, EVERY * 1000);

    await scenario.run(page);
    clearInterval(ticker);

    if (process.env.PROFILE === '1') {
        const { profile } = await cdp.send('Profiler.stop');
        const self = new Map();
        const byId = new Map(profile.nodes.map((node) => [node.id, node]));
        const total = profile.timeDeltas.reduce((sum, delta) => sum + delta, 0);

        for (let i = 0; i < profile.samples.length; i++) {
            const node = byId.get(profile.samples[i]);
            const frame = node.callFrame;
            const file = (frame.url || '').split('/').pop();
            const key = `${frame.functionName || '(anonymous)'} ${file}:${frame.lineNumber + 1}`;

            self.set(key, (self.get(key) || 0) + (profile.timeDeltas[i] || 0));
        }

        const byFile = new Map();

        for (const [key, time] of self) {
            const file = key.split(' ').pop().split(':')[0] || '(native)';

            byFile.set(file, (byFile.get(file) || 0) + time);
        }

        const lines = [
            `total sampled ${(total / 1000).toFixed(0)} ms over ${Math.round((Date.now() - started) / 1000)} s`,
            '',
            'by file:',
            ...[...byFile.entries()].sort((a, b) => b[1] - a[1]).slice(0, 15).map(([file, time]) => `  ${(time / 1000).toFixed(0).padStart(7)} ms  ${file}`),
            '',
            'by function (self time):',
            ...[...self.entries()].sort((a, b) => b[1] - a[1]).slice(0, 60).map(([key, time]) => `  ${(time / 1000).toFixed(0).padStart(7)} ms  ${key}`),
        ];

        writeFileSync(`out/profile-${name}-${recorder ? 'on' : 'off'}.txt`, lines.join('\n'));
    }

    // After a forced GC: what is really kept, not what the collector has not got to yet.
    await cdp.send('HeapProfiler.collectGarbage');
    await sleep(500);
    const after = await sample(cdp, page, browserCdp);

    after.t = Math.round((Date.now() - started) / 1000);
    after.gc = true;
    samples.push(after);

    // HEAP=1: the heap after that GC, for heap.mjs.
    if (process.env.HEAP === '1') {
        const chunks = [];
        const collect = ({ chunk }) => chunks.push(chunk);

        cdp.on('HeapProfiler.addHeapSnapshotChunk', collect);
        await cdp.send('HeapProfiler.takeHeapSnapshot', { reportProgress: false });
        cdp.off('HeapProfiler.addHeapSnapshotChunk', collect);
        writeFileSync(`out/heap-${name}-${recorder ? 'on' : 'off'}.heapsnapshot`, chunks.join(''));
    }

    await browser.close();

    const first = samples[0];
    const result = {
        scenario: name,
        recorder,
        seconds: after.t,
        heapStartMb: first.heapMb,
        heapEndMb: samples[samples.length - 2]?.heapMb,
        heapAfterGcMb: after.heapMb,
        heapPeakMb: Math.max(...samples.map((point) => point.heapMb)),
        nodesStart: first.nodes,
        nodesAfterGc: after.nodes,
        listenersStart: first.listeners,
        listenersAfterGc: after.listeners,
        rssStartMb: first.rssMb,
        rssPeakMb: Math.max(...samples.map((point) => point.rssMb ?? 0)),
        rssEndMb: after.rssMb,
    };

    writeFileSync(`out/memory-${name}-${recorder ? 'on' : 'off'}.json`, JSON.stringify(samples, null, 2));

    return result;
}

const wanted = process.argv.slice(2);
const results = [];

for (const name of wanted.length ? wanted.filter((name) => scenarios[name]) : Object.keys(scenarios)) {
    for (const recorder of process.env.RECORDER === '0' ? [true] : [false, true]) {
        console.error(`> ${name}, recorder ${recorder ? 'on' : 'off'}`);
        results.push(await run(name, { recorder }));
    }
}

writeFileSync('out/memory-results.json', JSON.stringify(results, null, 2));
console.table(results);
