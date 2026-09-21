// What recording a Filament panel costs, measured on the workbench's lab resource (/admin/orders):
// events and stored bytes per minute for the things a panel does all day, and what the recorder
// adds to interaction latency. The numbers in docs/ and in the core's docs/storage.md come from here.
//
//   composer serve                       (or: LAB_SPA=1 php vendor/bin/testbench serve --port=8123)
//   cd workbench/lab && npm install && node measure.mjs [scenario ...]
//
// BASE=http://127.0.0.1:8000 by default; SECONDS=45 per scenario; SHOTS=1 keeps a screenshot of
// each scenario's replay in out/ (fidelity: dark mode, modals, the rich editor).
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8000';
const SECONDS = Number(process.env.SECONDS ?? 45);
const SHOTS = process.env.SHOTS === '1';
const DB = new URL('../../vendor/orchestra/testbench-core/laravel/database/database.sqlite', import.meta.url).pathname;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const sql = (query) => JSON.parse(execFileSync('sqlite3', ['-json', DB, query], { encoding: 'utf8' }) || '[]');

mkdirSync('out', { recursive: true });

// Runs `step` over and over until the scenario's time is up; a step that throws is logged and skipped.
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
    // A table of 50 rows polling every 2 s with one cell per row changing each time; nobody touches anything.
    'polled-table': { url: '/admin/orders?poll=1', run: (page) => sleep(SECONDS * 1000) },

    // The same table without polling, left alone: what an open page costs.
    'idle-page': { url: '/admin/orders', run: (page) => sleep(SECONDS * 1000) },

    // Someone working a table: search, sort, filter by paging, every couple of seconds.
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

    // Edit modals: open, change two fields, save.
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

    // The create page: a paragraph typed into the rich editor, a file uploaded, saved; again.
    'editor-upload': {
        url: '/admin/orders/create',
        run: (page) => during(SECONDS, async (round) => {
            await page.locator('input[id$="reference"]').fill(`LAB-${round}`);
            await page.locator('input[id$="customer"]').fill('Lab Customer');
            await page.locator('input[id$="total"]').fill('4200');
            await page.locator('.tiptap').click();
            await page.keyboard.type('The customer called about the delivery window and asked for a morning slot instead. ', { delay: 25 });
            await page.locator('input[type=file]').first().setInputFiles({ name: 'invoice.png', mimeType: 'image/png', buffer: Buffer.alloc(180 * 1024, 7) });
            await sleep(2500);
            await page.goto(`${BASE}/admin/orders/create`);
        }),
    },

    // Page to page. With LAB_SPA=1 on the server this is wire:navigate: one document, many pages.
    navigation: {
        url: '/admin',
        run: (page) => during(SECONDS, async (round) => {
            await page.locator('.fi-sidebar-item a', { hasText: ['Orders', 'Users', 'Dashboard'][round % 3] }).first().click();
            await page.waitForLoadState('networkidle');
            await sleep(1500);
        }),
    },

    // The polled table again with the panel in dark mode: same cost, and the replay has to come out dark.
    dark: { url: '/admin/orders', dark: true, run: (page) => sleep(Math.min(SECONDS, 15) * 1000) },
};

// Interaction latency the way INP counts it: the longest event of each interaction, from the Event Timing API.
const OBSERVE = () => {
    window.__interactions = new Map();
    window.__longTasks = 0;
    new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
            if (entry.interactionId) window.__interactions.set(entry.interactionId, Math.max(window.__interactions.get(entry.interactionId) ?? 0, entry.duration));
        }
    }).observe({ type: 'event', durationThreshold: 16, buffered: true });
    new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) window.__longTasks += Math.max(0, entry.duration - 50);
    }).observe({ type: 'longtask', buffered: true });
};

async function run(name, { recorder = true, throttle = 1 } = {}) {
    const scenario = scenarios[name];
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: scenario.dark ? 'dark' : 'light' });

    await context.addInitScript(OBSERVE);

    if (!recorder) await context.route(/session-replay\/scripts\/recorder\.js/, (route) => route.abort());

    const page = await context.newPage();

    if (throttle > 1) await (await context.newCDPSession(page)).send('Emulation.setCPUThrottlingRate', { rate: throttle });

    const before = sql('select id from replay_sessions').map((row) => row.id);
    const started = Date.now();

    await page.goto(BASE + scenario.url);
    await page.waitForLoadState('networkidle');
    await scenario.run(page);

    const latency = await page.evaluate(() => {
        const sorted = [...window.__interactions.values()].sort((a, b) => a - b);
        const at = (share) => sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * share))] : null;

        return { interactions: sorted.length, p50: at(0.5), p75: at(0.75), p98: at(0.98), blockedMs: Math.round(window.__longTasks) };
    });
    const seconds = (Date.now() - started) / 1000;

    // Leaving the page is what flushes the last batch.
    await page.goto('about:blank');
    await sleep(2500);

    const result = { scenario: name, recorder, throttle, seconds: Math.round(seconds), ...latency };

    if (recorder) {
        const ids = sql('select id from replay_sessions').map((row) => row.id).filter((id) => !before.includes(id));
        const list = ids.map((id) => `'${id}'`).join(',') || "''";
        const [totals] = sql(`select coalesce(sum(event_count),0) events, coalesce(sum(bytes),0) bytes, coalesce(sum(page_count),0) pages, count(*) recordings from replay_sessions where id in (${list})`);
        const [first] = sql(`select coalesce(sum(bytes),0) bytes from replay_chunks where replay_session_id in (${list}) and seq = 0`);
        const minutes = seconds / 60;

        Object.assign(result, {
            recordings: totals.recordings,
            pages: totals.pages,
            events: totals.events,
            storedKb: +(totals.bytes / 1024).toFixed(1),
            firstChunkKb: +(first.bytes / 1024).toFixed(1),
            eventsPerMinute: Math.round(totals.events / minutes),
            kbPerMinute: +(totals.bytes / 1024 / minutes).toFixed(1),
            kbPerMinuteAfterSnapshot: +((totals.bytes - first.bytes) / 1024 / minutes).toFixed(1),
        });

        if (SHOTS && ids[0]) {
            const watch = await context.newPage();

            await watch.goto(`${BASE}/admin/session-replays/${ids[0]}?t=${Math.max(1, Math.round(seconds * 0.6))}`);
            await watch.waitForSelector('.replayer-wrapper iframe', { timeout: 20000 }).catch(() => {});
            await sleep(2500);
            await watch.screenshot({ path: `out/replay-${name}.png` });
        }
    }

    await browser.close();

    return result;
}

const wanted = process.argv.slice(2);
const results = [];

for (const name of wanted.length ? wanted.filter((name) => scenarios[name]) : Object.keys(scenarios)) {
    console.error(`> ${name}`);
    results.push(await run(name));
}

// What the recorder adds to an interaction, on a CPU four times slower than this machine.
if (!wanted.length || wanted.includes('latency')) {
    for (const recorder of [false, true]) {
        for (const name of ['table-work', 'modals']) {
            console.error(`> latency: ${name}, recorder ${recorder ? 'on' : 'off'}`);
            results.push(await run(name, { recorder, throttle: 4 }));
        }
    }
}

writeFileSync('out/results.json', JSON.stringify(results, null, 2));
console.table(results);
