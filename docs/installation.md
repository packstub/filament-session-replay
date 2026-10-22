# Installation

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer |
| Laravel | 12.x or 13.x |
| Filament | 5.x |
| packstub/session-replay | ^1.0 (1.0.0-beta.3 or newer during the beta), installed by Composer |

## Install

```bash
composer require "packstub/filament-session-replay:^1.0@beta" "packstub/session-replay:^1.0@beta"
php artisan session-replay:install
```

While both are in beta, require the core with `@beta` too: Composer takes a beta of a dependency only when your app asks for it.

The install command belongs to the core package. It publishes `config/session-replay.php`, offers to run the migrations and publishes `app/Providers/SessionReplayServiceProvider.php` with the gate in it. The core's [installation guide](https://github.com/packstub/session-replay/blob/main/docs/installation.md) describes each step.

## Register the plugin

```php
use Filament\Panel;
use Packstub\SessionReplay\SessionReplayPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(SessionReplayPlugin::make());
}
```

That is all a panel needs. You do not add `@sessionReplay` to a panel: the plugin adds the recorder before `</body>` through a render hook, and tells the core what only the panel knows:

| What | Where it comes from |
| --- | --- |
| The person | `Filament::auth()->user()`, so a panel on its own guard is recorded correctly |
| The workspace | `Filament::getTenant()` |
| The `panel` property | The panel's id, kept in the recording's `properties` |
| The impersonator | [Account Switcher](tenancy.md#impersonation), or your own `impersonatorUsing()` |

Everything else that decides whether a request is recorded (the master switch, `except`, `guests`, `SessionReplay::recordWhen()`, the sample rate, consent) is the core's and works the same in a panel. See [Recording](https://github.com/packstub/session-replay/blob/main/docs/recording.md). Guests are not recorded by default, so the login page stays out of recordings.

The pages of the Sessions resource are never recorded: someone watching recordings is not recorded doing it.

Pages outside a panel (a storefront, a Livewire front end) still use the `@sessionReplay` directive of the core. Both end up in the same list.

## Say who may watch

Until your app defines the `viewSessionReplay` gate, only the local environment can open recordings, in the panel as well as in the core's viewer. Define it in the published provider:

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    return $user->is_admin;
});
```

The gate is called without a recording for the list and with it for a single replay and every file behind it. The core's [Watching replays](https://github.com/packstub/session-replay/blob/main/docs/watching.md#the-gate) explains it with examples; [Watching](watching.md#who-may-do-what) covers policies and Filament Shield.

### Watching only in the panel

The core also serves its own pages, `/session-replay` (a list) and `/session-replay/{id}` (a player), behind `['web', 'auth']` and the same gate. When your team watches recordings only in the panel, turn them off:

```php
// config/session-replay.php
'viewer' => [
    'enabled' => false,
],
```

The panel keeps working: the player's data routes and the `<x-session-replay::player>` component stay, and replay links (`ReplaySession::url()`, `session_replay_url` in the log context) open the panel's watch page instead of the core's. Your own `SessionReplay::urlUsing()` in a provider's `boot()` still takes precedence. Turning the pages off also keeps a visitor of `/session-replay` from being sent to a `login` route your app may not have (a Filament panel's login is `filament.admin.auth.login`, not `login`), and in a multi-tenant app it keeps the core's list, which knows nothing about the panel's workspace, out of reach. See [Tenancy](tenancy.md#what-each-panel-lists).

### A panel on its own guard

The player loads the recording from the core's data routes, which sit outside the panel and run `viewer.middleware` (`['web', 'auth']`) and then the gate with the user of `viewer.guard` (`null`: the default guard). When the panel signs people in with a guard of its own, say `->authGuard('admin')`, set both, or the player is refused while the page itself opens:

```php
// config/session-replay.php
'viewer' => [
    'enabled' => false,
    'middleware' => ['web', 'auth:admin'],
    'guard' => 'admin',
],
```

The keys are described in the core's [Watching replays](https://github.com/packstub/session-replay/blob/main/docs/watching.md#the-built-in-viewer). Recording needs nothing: the plugin signs the panel's own user into the recording.

## Schedule the clean-up

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('session-replay:prune')->daily();
```

Recordings older than `retention.days` (30 by default) are deleted with their files; pinned recordings stay. See [Storage](https://github.com/packstub/session-replay/blob/main/docs/storage.md#pruning).

## Before production

The recordings are files on the disk named by `storage.disk` (`SESSION_REPLAY_DISK`, `local` by default) with an index in the database. The `local` disk is fine on one server with a persistent filesystem. When the app runs on several servers, or where the local filesystem does not last (Laravel Cloud, Vapor, containers that are replaced on deploy), an upload lands on one machine and the player asks another, and recordings vanish on the next deploy. Point the core at a shared, persistent disk such as S3 or R2:

```dotenv
SESSION_REPLAY_DISK=replays
```

The core's [Storage](https://github.com/packstub/session-replay/blob/main/docs/storage.md#the-disk) page has the disk configuration; keep it private, every file is read through the gated routes.

## Plugin options

```php
SessionReplayPlugin::make()
    ->record(fn ($user) => ! $user?->is_staff)
    ->widget()
    ->navigationGroup('Support')
    ->properties(fn ($user) => ['plan' => $user?->plan]);
```

| Method | Default | What it does |
| --- | --- | --- |
| `record(bool\|Closure $condition = true)` | `true` | Record this panel's pages. A closure receives the signed-in person as `$user` and returns a bool. |
| `resource(bool\|Closure $condition = true)` | `true` | Register the Sessions resource in this panel. `false` for a panel that is recorded but is not where recordings are watched. |
| `widget(bool $condition = true)` | off | Register `ReplayStatsWidget` on the panel. See [People](people.md#the-stats-widget). |
| `properties(array\|Closure $properties)` | `[]` | Extra properties kept on every recording of this panel, next to `panel`. A closure receives `$user`. |
| `impersonatorUsing(Closure $callback)` | [Account Switcher](https://packstub.dev/docs/filament-account-switcher), when installed (see [Impersonation](tenancy.md#impersonation)) | Returns the person who is impersonating (a model, named in the list; or their key, shown as `#key`), or null. |
| `userLabelUsing(Closure $callback)` | see [People](people.md#naming-people) | Returns how a person is named in the table and on the watch page: `fn (ReplaySession $session): ?string`. |
| `tenantLabelUsing(Closure $callback)` | the tenant's `name` | Returns how a workspace is named in the Workspace column: `fn (ReplaySession $session): ?string`. See [Tenancy](tenancy.md#naming-workspaces). |
| `searchPeopleBy(array $columns)` | `['name', 'email']` | The columns of your people's tables the table search looks in. See [People](people.md#finding-people). |
| `navigationGroup(string\|UnitEnum\|null $group)` | none | The resource's navigation group. |
| `navigationIcon(string\|BackedEnum\|null $icon)` | `Heroicon::OutlinedPlayCircle` | The resource's navigation icon. |
| `navigationSort(?int $sort)` | none | The resource's navigation sort. |
| `navigationLabel(?string $label)` | "Session replays" | The resource's navigation label. |
| `slug(string $slug)` | `session-replays` | The resource's URL segment. |
| `scopeToTenant(bool $condition = true)` | `true` | In a panel with tenancy, list only the current workspace's recordings. See [Tenancy](tenancy.md). |

`SessionReplayPlugin::get()` returns the plugin instance of the current panel.

## What recording a panel costs

Measured on a Filament 5 panel: a table page of 50 rows is a 34 KB snapshot, an open page nobody touches adds 0.2 KB per minute, the same table polling every two seconds 8 KB per minute, and each re-render of the table, modal or page change 10 to 30 KB. The Filament stylesheet is stored once for every recording. With the CPU slowed down four times, interaction latency was the same with and without the recorder. Dark mode, modals, notifications, `wire:navigate`, the rich editor and file uploads replay as they looked. As a rough plan, someone opening four to six pages a minute stores 65 to 100 KB per minute on the disk and about 7 KB per minute of index rows in the database. The full table and the monthly arithmetic are in the core's [Storage](https://github.com/packstub/session-replay/blob/main/docs/storage.md#what-to-expect) page, and the lab that produced it is in this repository (`workbench/lab/measure.mjs`) if you want numbers for your own pages.

## Languages

The resource, the watch page and the player ship in English, German, Spanish, Romanian and Russian and follow the app's locale. The panel's strings are JSON translations keyed by the English text, so your app's `lang/{locale}.json` overrides any of them or adds a language:

```json
{
    "Session replays": "Opnames",
    "Watch last session": "Bekijk laatste sessie"
}
```

The player's strings belong to the core: `php artisan vendor:publish --tag=session-replay-translations`.

## Record in one panel, watch in another

A common setup: customers work in one panel, your team watches in an operator panel. Register the plugin in both with different options.

```php
// The customer panel: recorded, no Sessions resource.
$panel->plugin(SessionReplayPlugin::make()->resource(false));

// The operator panel: the Sessions resource, not recorded.
$panel->plugin(SessionReplayPlugin::make()->record(false)->navigationGroup('Support'));
```

Recordings of the customer panel carry `panel: app` (its id) in their properties and, in a panel with tenancy, the workspace. The operator panel has no current tenant, so it lists every workspace and shows the Workspace column.

`ReplaysRelationManager`, `WatchLastSessionAction` and `ReplayStatsWidget` link to the Sessions resource, so use them in a panel where the resource is registered.
