// What the recorder does across wire:navigate, checked in a real panel: pages the app does not record stay out
// of the recording, console messages during a page swap are kept, and a page signed for another identity starts
// a new recording. Each scenario starts its own server on PORT (the workbench with LAB_SPA=1 and the scenario's
// knobs), runs Chrome against it and reads what was stored. Exits 1 when a check fails.
//
//   composer serve   (once, to build the workbench database; then stop it)
//   cd workbench/lab && npm install && node navigate.mjs [scenario ...]
//
// RECORDER=path/to/recorder.js serves that build instead of the installed one (a core change before it is
// released); CHANNEL=chrome runs the installed Chrome instead of Playwright's. PORT=8124 by default.
import { chromium } from 'playwright';
import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';

const ROOT = new URL('../../', import.meta.url).pathname;
const LARAVEL = `${ROOT}vendor/orchestra/testbench-core/laravel`;
const DB = `${LARAVEL}/database/database.sqlite`;
const DISK = `${LARAVEL}/storage/app/private/session-replay/sessions`;
const PORT = Number(process.env.PORT ?? 8124);
const BASE = `http://127.0.0.1:${PORT}`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const sql = (query) => JSON.parse(execFileSync('sqlite3', ['-json', DB, query], { encoding: 'utf8' }) || '[]');

// Every page's recorder logs its own marker; the checks look for these strings in the stored events.
const MARK = (name) => `SR-LAB-${name}`;

const scenarios = {
    // Users is in except_routes: Dashboard, Users, Orders must store Dashboard and Orders only.
    excluded: {
        env: { LAB_EXCEPT_ROUTES: 'filament.admin.resources.users.*' },
        run: (lab) => lab.walk(['Users', 'Orders']),
        check(lab, [recording, ...others]) {
            lab.expect('one recording', recording && others.length === 0);
            lab.expect('pages stored: /admin, /admin/orders', same(recording?.pages, ['/admin', '/admin/orders']));
            lab.expect('no navigation marker for /admin/users', !recording?.markers.some((m) => m.includes('/admin/users')));
            lab.expect('nothing logged on Users reaches the recording', !recording?.text.includes(MARK('Users')));
            lab.expect('no event while on Users', recording?.eventsBetween(lab.times.Users) === 0);
            lab.expect('isRecording() is false on Users', lab.state.Users?.isRecording === false);
            lab.expect('a swap touching Users is dropped', !recording?.text.includes(`${MARK('swap')} Users`) && !recording?.text.includes(`${MARK('swap')} Orders`));
            lab.expect('Orders is recorded, its console message too', recording?.text.includes(MARK('Orders')));
        },
    },

    // The tab opens on Users, which the app does not record, so no recorder is loaded yet: the first recorded page
    // reached with wire:navigate must still load it and record from there.
    'starts-excluded': {
        env: { LAB_EXCEPT_ROUTES: 'filament.admin.resources.users.*' },
        start: '/admin/users',
        run: (lab) => lab.walk(['Orders', 'Dashboard']),
        check(lab, [recording, ...others]) {
            lab.expect('one recording', recording && others.length === 0);
            lab.expect('pages stored: /admin/orders, /admin', same(recording?.pages, ['/admin/orders', '/admin']));
            lab.expect('recording on Orders', lab.state.Orders?.isRecording === true);
        },
    },

    // The same walk with nothing excluded: every page, every console message, the swaps' included.
    recorded: {
        env: {},
        run: (lab) => lab.walk(['Users', 'Orders']),
        check(lab, [recording, ...others]) {
            lab.expect('one recording', recording && others.length === 0);
            lab.expect('pages stored: /admin, /admin/users, /admin/orders', same(recording?.pages, ['/admin', '/admin/users', '/admin/orders']));
            lab.expect('console messages of every page', ['Users', 'Orders'].every((page) => recording?.text.includes(MARK(page))));
            lab.expect('console messages during each swap', ['Users', 'Orders'].every((page) => recording?.text.includes(`${MARK('swap')} ${page}`)));
            lab.note(`recorder.js ran ${lab.recorderRuns} times over 3 pages`);
        },
    },

    // Orders is signed with an impersonator: Dashboard, Orders, Dashboard are three recordings.
    identity: {
        env: { LAB_IMPERSONATE_ON: 'admin/orders' },
        run: (lab) => lab.walk(['Orders', 'Dashboard']),
        check(lab, recordings) {
            lab.expect('three recordings', recordings.length === 3);
            lab.expect('impersonator: none, 7, none', same(recordings.map((r) => r.impersonator_id), [null, '7', null]));
            lab.expect('each recording holds its own page', same(recordings.map((r) => r.pages.join(' ')), ['/admin', '/admin/orders', '/admin']));
            lab.expect('no upload refused', lab.refused.length === 0);
        },
    },

    // Mode on_error: the window kept on Dashboard belongs to another identity than Orders, where the error
    // happens. Only Orders is uploaded, under the impersonator.
    'identity-on-error': {
        env: { LAB_IMPERSONATE_ON: 'admin/orders', SESSION_REPLAY_MODE: 'on_error' },
        async run(lab) {
            await lab.walk(['Orders']);
            await lab.page.evaluate(() => setTimeout(() => {
                throw new Error('SR-LAB-error');
            }));
            await sleep(2500);
        },
        check(lab, [recording, ...others]) {
            lab.expect('one recording', recording && others.length === 0);
            lab.expect('under the impersonator', recording?.impersonator_id === '7');
            lab.expect('pages stored: /admin/orders', same(recording?.pages, ['/admin/orders']));
            lab.expect('the error is a marker', recording?.markers.some((m) => m.startsWith('error:') && m.includes('SR-LAB-error')));
            lab.expect('no upload refused', lab.refused.length === 0);
        },
    },
};

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

async function serve(env) {
    const server = spawn('php', ['vendor/bin/testbench', 'serve', `--port=${PORT}`], { cwd: ROOT, env: { ...process.env, LAB_SPA: '1', ...env }, stdio: 'ignore', detached: true });

    for (let i = 0; i < 60; i++) {
        try {
            if ((await fetch(`${BASE}/admin`)).ok) return server;
        } catch {
            // Not up yet.
        }

        await sleep(250);
    }

    process.kill(-server.pid);
    throw new Error(`The workbench did not answer on ${BASE}`);
}

function stored(id) {
    const [row] = sql(`select id, impersonator_id from replay_sessions where id = '${id}'`);
    const events = [];

    for (const file of readdirSync(`${DISK}/${id}`).sort((a, b) => parseInt(a) - parseInt(b))) {
        const raw = readFileSync(`${DISK}/${id}/${file}`);

        events.push(...JSON.parse((raw[0] === 0x1f ? gunzipSync(raw) : raw).toString()));
    }

    return {
        ...row,
        pages: events.filter((event) => event.type === 4).map((event) => new URL(event.data.href).pathname),
        markers: sql(`select type, label from replay_markers where replay_session_id = '${id}' order by at_ms`).map((m) => `${m.type}:${m.label}`),
        text: JSON.stringify(events),
        eventsBetween: (span) => (span ? events.filter((event) => event.timestamp > span.from && event.timestamp < span.to).length : null),
        startedAt: Math.min(...events.map((event) => event.timestamp)),
    };
}

async function run(name) {
    const scenario = scenarios[name];
    const server = await serve(scenario.env);
    const before = new Set(sql('select id from replay_sessions').map((row) => row.id));
    const browser = await chromium.launch(process.env.CHANNEL ? { channel: process.env.CHANNEL } : {});
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const lab = { times: {}, state: {}, refused: [], recorderRuns: 0, results: [], notes: [] };

    if (process.env.RECORDER) {
        await context.route(/session-replay\/scripts\/recorder\.js/, (route) => route.fulfill({ path: process.env.RECORDER, contentType: 'text/javascript' }));
    }

    // Every run of the recorder bundle reads window.SessionReplay once, in its guard; nothing else in it does.
    await context.addInitScript(() => {
        let value;

        window.__labRecorderRuns = 0;
        Object.defineProperty(window, 'SessionReplay', {
            configurable: true,
            get() {
                if (/recorder\.js/.test(new Error().stack || '')) window.__labRecorderRuns++;

                return value;
            },
            set(next) {
                value = next;
            },
        });
    });

    const page = (lab.page = await context.newPage());
    page.on('response', async (response) => {
        if (response.url().includes('/ingest') && !response.ok()) lab.refused.push(`${response.status()} ${await response.text().catch(() => '')}`);
    });

    lab.expect = (label, ok) => lab.results.push({ label, ok: !!ok });
    lab.note = (text) => lab.notes.push(text);
    // Clicks through the sidebar; on each page logs its marker, moves the mouse and scrolls.
    lab.walk = async (labels) => {
        for (const label of labels) {
            await page.evaluate((next) => (window.__labNext = next), label);
            await page.locator('.fi-sidebar-item a', { hasText: label }).first().click();
            await page.waitForLoadState('networkidle');
            await sleep(1200);

            const from = Date.now();

            lab.state[label] = await page.evaluate((mark) => {
                console.error(mark);
                window.SessionReplay?.mark(mark);

                return { isRecording: window.SessionReplay?.isRecording() ?? null };
            }, MARK(label));

            for (let i = 0; i < 8; i++) {
                await page.mouse.move(300 + i * 60, 300 + i * 20);
                await sleep(120);
            }

            await page.mouse.wheel(0, 400);
            await sleep(800);
            lab.times[label] = { from, to: Date.now() };
        }
    };

    try {
        await page.goto(`${BASE}${scenario.start ?? '/admin'}`);
        await page.waitForLoadState('networkidle');
        await sleep(1500);
        // Logged inside livewire:navigating, after the recorder's own listener (added once the recorder runs):
        // during the swap to window.__labNext.
        lab.state.start = await page.evaluate(() => {
            document.addEventListener('livewire:navigating', () => console.error(`SR-LAB-swap ${window.__labNext}`));

            return { isRecording: window.SessionReplay?.isRecording() ?? null, isBuffering: window.SessionReplay?.isBuffering() ?? null };
        });
        await scenario.run(lab);
        lab.recorderRuns = await page.evaluate(() => window.__labRecorderRuns);
        await page.evaluate(() => window.SessionReplay?.flush());
        await sleep(2000);
        await page.goto('about:blank');
        await sleep(1500);
    } finally {
        await browser.close();
        process.kill(-server.pid);
    }

    const recordings = sql('select id from replay_sessions').map((row) => row.id).filter((id) => !before.has(id)).map(stored).sort((a, b) => a.startedAt - b.startedAt);

    scenario.check(lab, recordings);

    console.log(`\n${name}`);
    for (const result of lab.results) console.log(`  ${result.ok ? 'ok  ' : 'FAIL'} ${result.label}`);
    for (const note of lab.notes) console.log(`  --   ${note}`);

    // What was stored, to tell why a check failed.
    if (!lab.results.every((result) => result.ok)) {
        for (const r of recordings) console.log(`  --   stored ${r.id}: impersonator ${r.impersonator_id}, pages ${r.pages.join(' ') || '-'}, markers ${r.markers.join(' | ')}`);
        if (!recordings.length) console.log('  --   nothing stored');
        console.log(`  --   state: ${JSON.stringify(lab.state)}`);
        for (const refusal of lab.refused) console.log(`  --   refused: ${refusal}`);
    }

    return lab.results.every((result) => result.ok);
}

let passed = true;

for (const name of process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(scenarios)) {
    if (!scenarios[name]) throw new Error(`Unknown scenario "${name}": ${Object.keys(scenarios).join(', ')}`);

    passed = (await run(name)) && passed;
}

process.exit(passed ? 0 : 1);
