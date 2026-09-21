# packstub/filament-session-replay

Session replay inside a Filament v5 panel, on top of `packstub/session-replay` (the sibling repo, `plugins/session-replay`), which records, stores, gates and plays. This package is the panel experience: the recorder injected with the panel's guard and workspace, the Sessions resource, the watch page, a relation manager, an action, a widget and the masking macros. **Filament Session Replay** on packstub.dev, **Packstub Session Replay** on filamentphp.com (naming by channel). Free, MIT, with a written Pro reserve in `workspace/notes/session-replay/plugin-idea.md` (nothing here may later move behind a paywall). Both packages share the `Packstub\SessionReplay\` namespace, so class names must not collide across them.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, a fixture panel)
composer test:filter <name>
composer lint               # Pint
composer serve              # Testbench workbench: a recorded panel on :8000/admin, signed in, recordings under Support
```

The core is required through a path repository (`../session-replay`) until it is on Packagist; CI checks it out next to this repo with the `SESSION_REPLAY_DEPLOY_KEY` secret (a read-only deploy key on the core repo). Remove the `repositories` block, `minimum-stability: dev` and that checkout step when the core is public.

## Layout

- `src/SessionReplayPlugin.php` — the panel plugin: options (`record`, `resource`, `widget`, `properties`, `impersonatorUsing`, `userLabelUsing`, navigation, `slug`, `scopeToTenant`), the `BODY_END` render hook that calls the core's `recorder()` with `Filament::auth()->user()`, `Filament::getTenant()`, the panel id and the impersonator (Account Switcher detected by class name), never on the resource's own pages.
- `src/Filament/Resources/ReplaySessions` — `ReplaySessionResource` (table, filters, pin action, authorization), `Pages/ListReplaySessions`, `Pages/ViewReplaySession` (facts, the core's `<x-session-replay::player>` inside `wire:ignore`, export), `RelationManagers/ReplaysRelationManager`.
- `src/Filament/Actions/WatchLastSessionAction`, `src/Filament/Widgets/ReplayStatsWidget`.
- `src/Filament/FilamentSessionReplayServiceProvider` — views and the `maskInReplay()` / `blockInReplay()` macros on `Field`, `Column`, `Entry` and layout `Component`s.
- `tests/Fixtures` — the admin panel, a `UserResource` using the relation manager, the action and the macros, a policy; `workbench/` — the app behind `composer serve`.
- `workbench/lab/measure.mjs` — the measuring lab (Playwright against `composer serve`, the workbench's Orders resource): stored bytes and events per scenario, interaction latency with and without the recorder. The numbers in `docs/installation.md` and the core's `docs/storage.md` come from it; re-run it after a recorder change.
- `docs/` — customer docs; synced into the store under `filament-session-replay/1.x` once public.

## Conventions

- PHP 8.3+, Filament 5. Every change needs a test and a `CHANGELOG.md` line; changelog headings are `## <version> — <date>`, the tag is `v<version>` on `main`.
- Authorization has one source: the core's `viewSessionReplay` gate (`SessionReplay::check()`), unless the app registered a policy for `ReplaySession`, which then decides in the panel. The core's data routes always ask the gate, so nothing here may serve recording content through a route of its own.
- Recording, storage, privacy defaults and the player belong to the core. If a change needs the chunk format, the manifest or the recorder, make it there and require the new core minor here.
- Strings are `__()` keyed by the English text.
- After a change in the core run this suite too.
