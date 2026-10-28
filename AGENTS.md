# packstub/filament-session-replay

Session replay inside a Filament v5 panel, on top of `packstub/session-replay` (the sibling repo, `plugins/session-replay`), which records, stores, gates and plays. This package is the panel experience: the recorder injected with the panel's guard and workspace, the Sessions resource, the watch page, a relation manager, an action, a widget and the masking macros. **Filament Session Replay** on packstub.dev, **Packstub Session Replay** on filamentphp.com (naming by channel). Free, MIT, with a written Pro reserve in `workspace/notes/session-replay/plugin-idea.md` (nothing here may later move behind a paywall). Both packages share the `Packstub\SessionReplay\` namespace, so class names must not collide across them.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, a fixture panel)
composer test:filter <name>
composer lint               # Pint
composer serve              # Testbench workbench: a recorded panel on :8000/admin, signed in, recordings under Support
```

The core comes from Packagist (`^1.1`, `minimum-stability` `stable`). To work on both packages at once, add a path repository to `../session-replay` locally and leave it out of the commit.

### Local setup for Assist (core, Calls and Filament Calls from the sibling checkouts)

`packstub/calls` and `packstub/filament-calls` are only in `suggest`, so CI runs the Assist tests that need them as skipped (`needsCalls()`). Locally, install with an uncommitted `composer.local.json` (a copy of `composer.json` plus path repositories, symlinked) and `COMPOSER=composer.local.json composer update`:

```json
"repositories": [
    {"type": "path", "url": "../session-replay", "options": {"symlink": true}},
    {"type": "path", "url": "../calls", "options": {"symlink": true}},
    {"type": "path", "url": "../filament-calls", "options": {"symlink": true}}
],
"require": {"packstub/session-replay": "dev-main as 1.1.0"},
"require-dev": {"packstub/calls": "dev-main as 1.0.0", "packstub/filament-calls": "dev-main as 1.0.0"}
```

(From a worktree under `.worktrees/<name>`, the paths are `../../../<repo>`; point `session-replay` at the core's branch, e.g. `../../../session-replay/.worktrees/assist` with `dev-assist as 1.1.0`.) `composer.local.json` and `composer.local.lock` are in `.git/info/exclude`. `vendor/packstub/*` are then symlinks, and testbench discovers the Calls providers. The browser lab's `node_modules` is a symlink to the main checkout's `workbench/lab/node_modules`.

## Layout

- `src/SessionReplayPlugin.php` — the panel plugin: options (`record`, `resource`, `widget`, `properties`, `impersonatorUsing`, `userLabelUsing`, navigation, `slug`, `scopeToTenant`), the `BODY_END` render hook that calls the core's `recorder()` with `Filament::auth()->user()`, `Filament::getTenant()`, the panel id and the impersonator (Account Switcher detected by class name), never on the resource's own pages.
- `src/Filament/Resources/ReplaySessions` — `ReplaySessionResource` (table, filters, pin action, authorization), `Pages/ListReplaySessions`, `Pages/ViewReplaySession` (facts, the core's `<x-session-replay::player>` inside `wire:ignore`, export), `RelationManagers/ReplaysRelationManager`.
- `src/Filament/Actions/WatchLastSessionAction`, `src/Filament/Widgets/ReplayStatsWidget`.
- Assist (help requests and the live view, on the core's `Support\Assist` service): `src/Filament/Assist/PanelAssist` (the panel's agent, the core's queue narrowed to the panel's workspace and read once per HTTP request, the stage's config with the panel's Calls routes when Filament Calls is on the panel), `src/Filament/Assist/AssistActions` (Claim, Open live view, Withdraw, End on a request; Offer help and Open live view on a recording), `Pages/AssistRequests` (resource page `requests`, a polled table over the queue; the resource adds a Help requests navigation item with the waiting count), `Pages/AssistStage` (resource page `requests/{assistRequest}/stage`, view `resources/views/assist/stage.blade.php`: the core's `stage.js`/`stage.css` with the panel's buttons carrying the stage's `data-action`/`data-ref`/`data-why`/`data-label` attributes, inside `wire:ignore`), `src/Filament/Livewire/AssistNotifier` (rendered on every page for agents by a `BODY_END` hook, polls the queue, toasts each new request once per session and puts it in the bell with database notifications on; its Claim comes back as a browser event), `src/Filament/View/SessionReplayIconAlias`. The live view is a page of its own, never reached with `wire:navigate` and not persisted (the core's stage leaves the call on `livewire:navigating`). Who may help is the core's (`canAssist`, gate, `visibleUsing()`); nothing here decides it alone.
- `src/Filament/FilamentSessionReplayServiceProvider` — views and the `maskInReplay()` / `blockInReplay()` macros on `Field`, `Column`, `Entry` and layout `Component`s.
- `tests/Assist` (`AssistTestCase`: Assist on, the Calls providers and `Fixtures/AssistPanelProvider` with Filament Calls when installed) — the Requests page, actions, gating, the stage page and the notifier.
- `tests/Fixtures` — the admin panel, a `UserResource` using the relation manager, the action and the macros, a policy; `workbench/` — the app behind `composer serve`.
- `workbench/lab/measure.mjs` — the measuring lab (Playwright against `composer serve`, the workbench's Orders resource): stored bytes and events per scenario, interaction latency with and without the recorder. The numbers in `docs/installation.md` and the core's `docs/storage.md` come from it; re-run it after a recorder change.
- `workbench/lab/assist.mjs` — Assist in a real panel between two browsers: Ada's page asks for help, Ana (`/lab/agent`, which signs her in) gets the toast, claims on Help requests, reaches a live stage (WebRTC on loopback) beside the Filament Calls widget, navigates away with `wire:navigate` and back through Open live view, and ends it; screenshots of the new pages in light and dark in `OUT`. Needs Calls and Filament Calls linked (above) and the workbench built with `SESSION_REPLAY_ASSIST=1`. Run it after a change in the core's stage or in the Assist pages here.
- `workbench/lab/navigate.mjs` — the browser check for `wire:navigate`: each scenario starts the workbench with `LAB_SPA=1` and its knobs (`LAB_EXCEPT_ROUTES`, `LAB_IMPERSONATE_ON`, `SESSION_REPLAY_MODE`) and checks what was stored: excluded pages stay out, console messages during a swap are kept, an identity change starts a new recording. Exits 1 on a failed check. `RECORDER=path` serves a core build that is not released yet. Run it after a change in the core's `resources/js`.
- `docs/` — customer docs; synced into the store under `filament-session-replay/1.x` once public.

## Conventions

- PHP 8.3+, Filament 5. Every change needs a test and a `CHANGELOG.md` line; changelog headings are `## <version> — <date>`, the tag is `v<version>` on `main`.
- Authorization has one source: the core's `viewSessionReplay` gate (`SessionReplay::check()`), unless the app registered a policy for `ReplaySession`, which then decides in the panel. The core's data routes always ask the gate, so nothing here may serve recording content through a route of its own.
- Recording, storage, privacy defaults and the player belong to the core. If a change needs the chunk format, the manifest or the recorder, make it there and require the new core minor here.
- Strings are `__()` keyed by the English text.
- After a change in the core run this suite too.
