// Assist in a real panel, between two browsers: Ada's page (/admin/orders, recorded) asks for help; Ana Support
// gets the panel's toast, claims the request on Help requests and lands on the live stage, where the core's stage
// script mirrors Ada's page over WebRTC (loopback) beside Filament Calls' widget. In a panel that navigates with
// wire:navigate (LAB_SPA=1, the default here) the stage leaves the call when Ana follows a sidebar link and comes
// back through Open live view. Ana then ends the assist. Screenshots of the new pages in light and dark mode land
// in OUT (workbench/lab/out by default). Exits 1 when a check fails.
//
//   SESSION_REPLAY_ASSIST=1 composer serve   (once, to build the workbench database with Assist's and Calls' tables; then stop it)
//   cd workbench/lab && npm install && node assist.mjs
//
// Needs packstub/calls and packstub/filament-calls linked (AGENTS.md, "Assist"). CHANNEL=chrome runs the installed
// Chrome instead of Playwright's; PORT=8125 by default; LAB_SPA=0 runs the panel without wire:navigate.
import { chromium } from 'playwright';
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ROOT = new URL('../../', import.meta.url).pathname;
const DB = `${ROOT}vendor/orchestra/testbench-core/laravel/database/database.sqlite`;
const PORT = Number(process.env.PORT ?? 8125);
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = process.env.OUT ?? new URL('./out/', import.meta.url).pathname;
const SPA = process.env.LAB_SPA ?? '1';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const sql = (query) => JSON.parse(execFileSync('sqlite3', ['-json', DB, query], { encoding: 'utf8' }) || '[]');

const failures = [];

function expect(label, ok, detail = '') {
    console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}${detail ? ` (${detail})` : ''}`);

    if (!ok) failures.push(label);
}

/** Without a file for the fake microphone, Chrome on macOS asks for the real one: one second of silence. */
function silentWav() {
    const path = join(tmpdir(), 'filament-session-replay-lab-mic.wav');
    const data = Buffer.alloc(32000);
    const header = Buffer.alloc(44);

    header.write('RIFF', 0);
    header.writeUInt32LE(36 + data.length, 4);
    header.write('WAVE', 8);
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20);
    header.writeUInt16LE(1, 22);
    header.writeUInt32LE(16000, 24);
    header.writeUInt32LE(32000, 28);
    header.writeUInt16LE(2, 32);
    header.writeUInt16LE(16, 34);
    header.write('data', 36);
    header.writeUInt32LE(data.length, 40);
    writeFileSync(path, Buffer.concat([header, data]));

    return path;
}

async function serve() {
    // Several PHP workers: the visitor's page, the agent's stage and the Calls widget poll at the same time.
    const server = spawn('php', ['vendor/bin/testbench', 'serve', `--port=${PORT}`], {
        cwd: ROOT,
        env: { ...process.env, SESSION_REPLAY_ASSIST: '1', PHP_CLI_SERVER_WORKERS: '8', LAB_SPA: SPA },
        stdio: 'ignore',
        detached: true,
    });

    for (let i = 0; i < 80; i++) {
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

const stageState = (page) => page.evaluate(() => document.querySelector('[data-session-replay-stage]')?.sessionReplayStage?.state ?? null);

async function waitFor(label, fn, timeout = 60_000) {
    const until = Date.now() + timeout;
    let last;

    while (Date.now() < until) {
        last = await fn().catch((error) => ({ error: error.message }));

        if (last && last.ok) return last;

        await sleep(250);
    }

    expect(label, false, JSON.stringify(last));

    return last;
}

/** Both colour schemes of the page as it is: Filament follows prefers-color-scheme in its default "system" mode. */
async function shoot(page, name) {
    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await sleep(400);
        await page.screenshot({ path: `${OUT}${name}-${scheme}.png`, fullPage: true });
    }

    await page.emulateMedia({ colorScheme: 'light' });
}

mkdirSync(OUT, { recursive: true });

// A clean slate: no requests or calls left from an earlier run.
sql('delete from replay_assist_requests; delete from call_signals; delete from call_participants; delete from calls; select 1');

const server = await serve();
const browser = await chromium.launch({
    channel: process.env.CHANNEL,
    args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-audio-capture=${silentWav()}`, '--autoplay-policy=no-user-gesture-required'],
});

try {
    const viewport = { width: 1440, height: 900 };
    const visitorContext = await browser.newContext({ viewport });
    const agentContext = await browser.newContext({ viewport, permissions: ['microphone', 'camera'] });
    // The visitor's panel lives in a closed shadow root: opened here so the checks can read it, as a person would.
    await visitorContext.addInitScript(() => {
        const attach = Element.prototype.attachShadow;

        Element.prototype.attachShadow = function (init) {
            return attach.call(this, { ...init, mode: 'open' });
        };
    });

    const visitor = await visitorContext.newPage();
    const agent = await agentContext.newPage();
    const errors = [];
    const polls = [];

    visitor.on('pageerror', (error) => errors.push(`visitor: ${error.message}`));
    agent.on('pageerror', (error) => errors.push(`agent: ${error.message}`));
    agent.on('request', (request) => {
        if (request.url().includes('/filament-calls/calls/poll')) polls.push(new URL(request.url()).searchParams.get('kind'));
    });

    // ---- Ada's page records -----------------------------------------------------------------------------

    const firstUpload = visitor.waitForResponse((response) => response.url().includes('/session-replay/ingest') && response.status() === 201, { timeout: 30_000 });

    await visitor.goto(`${BASE}/admin/orders`);
    await firstUpload;
    expect('Ada\'s page records', true);

    // ---- Ana: the recordings list's Live now tab, then Help requests, empty -----------------------------------

    await agent.goto(`${BASE}/lab/agent`);
    await agent.waitForURL(/\/admin\/session-replays\/requests$/);
    await agent.getByText('Nobody is waiting for help.').waitFor();
    await shoot(agent, 'requests-empty');

    await agent.goto(`${BASE}/admin/session-replays?tab=live`);
    const offer = agent.getByRole('button', { name: 'Offer help' }).first();

    await offer.waitFor();
    expect('Offer help on Ada\'s live recording', await offer.isVisible());
    expect('"last seen … ago" on the live row', await agent.getByText(/last seen \d+ s ago/).first().isVisible());
    await shoot(agent, 'sessions-live');

    await offer.click();
    await agent.getByText('Offer help?').waitFor();
    await shoot(agent, 'offer-modal');
    await agent.keyboard.press('Escape');

    // ---- Ada asks for help; Ana's panel tells her ----------------------------------------------------------

    await visitor.evaluate(() => window.SessionReplay.assist.request({ message: 'The export button does nothing' }));

    await agent.goto(`${BASE}/admin/session-replays/requests`);

    const toast = agent.locator('.fi-no-notification').filter({ hasText: 'asked for help' });

    await toast.first().waitFor({ timeout: 20_000 });
    expect('a toast tells Ana about the request', await toast.first().isVisible());

    const row = agent.getByRole('row', { name: /The export button does nothing/ });

    await row.waitFor({ timeout: 15_000 });
    expect('the request is listed, waiting', (await row.textContent()).includes('Waiting'));

    const badge = agent.locator('.fi-sidebar-item').filter({ hasText: 'Help requests' }).locator('.fi-badge');

    expect('the navigation badge counts one visitor waiting', (await badge.textContent())?.trim() === '1');
    await shoot(agent, 'requests');

    // ---- Claim: the stage, live ----------------------------------------------------------------------------

    await row.getByRole('button', { name: 'Claim' }).click();
    await agent.waitForURL(/\/admin\/session-replays\/requests\/[0-9a-f-]+\/stage$/, { timeout: 20_000 });
    expect('Claim leads to the stage', true);

    const live = await waitFor('the stage goes live and mirrors Ada\'s page', async () => {
        const state = await stageState(agent);

        return { ok: state?.phase === 'live' && state.events > 0, ...state };
    });

    expect('the stage is live', live?.phase === 'live', `${live?.events} events`);
    const mirrored = await waitFor('the mirror shows Ada\'s Orders page', async () => {
        const text = await agent.evaluate(() => document.querySelector('[data-ref="mirror"] iframe')?.contentDocument?.body?.textContent ?? '');

        return { ok: text.includes('ORD-00001'), length: text.length };
    }, 20_000);

    expect('the mirror shows Ada\'s Orders page', mirrored?.ok === true);
    expect('the panel\'s badge says Live', (await agent.locator('.fi-badge:visible').allTextContents()).map((text) => text.trim()).includes('Live'));
    expect('Ada\'s page says Ana sees it', await visitor.getByText(/sees this page/).isVisible().catch(() => false));
    expect('Filament Calls\' widget is on the stage page', (await agent.locator('[data-calls-widget], .fi-calls').count()) > 0);
    expect('the stage polls Calls with kind=assist, the widget with kind=call', polls.includes('assist') && polls.includes('call') && !polls.includes(null), [...new Set(polls)].join(', '));

    // Draw: the panel's toggle button is pressed and the label stays beside its icon.
    await agent.locator('[data-action="draw"]').click();
    expect('Draw is a pressed toggle', (await agent.locator('[data-action="draw"]').getAttribute('aria-pressed')) === 'true');
    expect('the button keeps its icon', (await agent.locator('[data-action="draw"] svg').count()) === 1);
    await shoot(agent, 'stage');
    await agent.locator('[data-action="draw"]').click();

    const [request] = sql("select id, status from replay_assist_requests where message = 'The export button does nothing'");

    expect('the request is active', request?.status === 'active');

    // ---- wire:navigate away and back ----------------------------------------------------------------------

    if (SPA === '1') {
        await agent.locator('.fi-sidebar-item').filter({ hasText: 'Orders' }).locator('a').click();
        await agent.waitForURL(/\/admin\/orders$/);
        await sleep(500);
        expect('the stage is gone after wire:navigate', (await agent.locator('[data-session-replay-stage]').count()) === 0);

        const [still] = sql(`select status from replay_assist_requests where id = '${request.id}'`);

        expect('the request stays Ana\'s and live', still?.status === 'active');

        await agent.goto(`${BASE}/admin/session-replays/requests`);
        await agent.getByRole('row', { name: /The export button does nothing/ }).getByRole('button', { name: 'Open live view' }).click();
        await agent.waitForURL(/\/stage$/);

        const back = await waitFor('the stage is live again after Open live view', async () => {
            const state = await stageState(agent);

            return { ok: state?.phase === 'live' && state.events > 0, ...state };
        });

        expect('live again', back?.phase === 'live');
    }

    // ---- End ----------------------------------------------------------------------------------------------

    await agent.locator('[data-action="end"]').click();
    await waitFor('the stage says the assist is over', async () => ({ ok: (await stageState(agent))?.phase === 'ended' }), 20_000);

    const [ended] = sql(`select status, end_reason from replay_assist_requests where id = '${request.id}'`);

    expect('the request ended, by the agent', ended?.status === 'ended' && ended?.end_reason === 'ended_by_agent', JSON.stringify(ended));
    await shoot(agent, 'stage-ended');

    expect('no page errors', errors.length === 0, errors.join(' | '));
} finally {
    await browser.close();
    process.kill(-server.pid);
}

console.log(failures.length ? `\n${failures.length} check(s) failed` : '\nAll checks passed');
process.exit(failures.length ? 1 : 0);
