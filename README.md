# Filament Session Replay

Session replay inside your Filament panel. Every recording made by [Session Replay for Laravel](https://github.com/packstub/session-replay) shows up in a filterable resource, plays in the panel with errors, failed Livewire requests and web vitals on the timeline, and hangs off the people it belongs to: a relation manager on your user resource, a "Watch last session" action on a row. Free and open source (MIT).

Recordings stay on your own disk and database, and who may watch them is decided by a gate your app defines.

## Features

- **Recording without a directive.** Register the plugin and the panel's pages are recorded, with the panel's own guard, the current Filament tenant, the panel id and the impersonator signed into the recording.
- **A Sessions resource.** Person, workspace, start, length, pages, errors, rage clicks, vitals, device, first page, pinned, size. Filters for errors, slow pages, rage clicks, impersonated sessions, pinned, device, first page and start date.
- **The player in the panel.** The facts of the recording, the rrweb player with coloured markers on the timeline, a marker list that seeks on click, Pin, Export and Delete.
- **Around your users.** `ReplaysRelationManager` for a person's page, `WatchLastSessionAction` for a row or a header, `ReplayStatsWidget` for the dashboard.
- **Masking where the field is.** `->maskInReplay()` and `->blockInReplay()` on form fields, table columns, infolist entries and layout components.
- **Workspace-aware.** In a panel with tenancy the list shows the current workspace; an operator panel shows all of them with a Workspace column.
- **One rule for access.** The `viewSessionReplay` gate of the core, with the recording as argument. A policy for `ReplaySession` takes over when you register one, which is how Filament Shield permissions plug in.

## Requirements

PHP 8.3+, Laravel 12 or 13, Filament 5. Composer installs `packstub/session-replay` for you.

## Quick start

```bash
composer require packstub/filament-session-replay
php artisan session-replay:install
```

Register the plugin in your panel provider:

```php
use Packstub\SessionReplay\SessionReplayPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(SessionReplayPlugin::make());
}
```

Say who may watch, in the `App\Providers\SessionReplayServiceProvider` the install command published:

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    return $user->is_admin;
});
```

Schedule the clean-up:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('session-replay:prune')->daily();
```

Open the panel, click around, then open **Session replays** in the navigation.

## Two packages, one picture

| Package | What it does |
| --- | --- |
| [`packstub/session-replay`](https://github.com/packstub/session-replay) | Records (rrweb, masked inputs, markers), ingests, stores gzip chunks on your disk with an index in your database, prunes, links the recording into Laravel's log context, and guards every byte with the `viewSessionReplay` gate. Works in any Laravel app. |
| `packstub/filament-session-replay` (this one) | The panel experience: recording wired to the panel's guard and tenant, the Sessions resource, the watch page, the relation manager, the action, the widget and the masking macros. |

Everything about privacy, storage, consent and configuration lives in the core and applies here unchanged.

## Documentation

| Guide | What it covers |
| --- | --- |
| [Overview](docs/README.md) | What you get, at a glance |
| [Installation](docs/installation.md) | Install, register, the gate, the plugin's options, recording in one panel and watching in another |
| [Watching](docs/watching.md) | The resource, its filters, the watch page, Pin, Export, Delete, who may do what |
| [Masking](docs/masking.md) | `maskInReplay()` and `blockInReplay()` |
| [People](docs/people.md) | The relation manager, "Watch last session", the stats widget, naming people |
| [Tenancy](docs/tenancy.md) | Workspaces, operator panels, database-per-tenant apps, impersonation |

The core's guides cover the rest: [Recording](https://packstub.dev/docs/session-replay/recording), [Privacy](https://packstub.dev/docs/session-replay/privacy), [Watching replays](https://packstub.dev/docs/session-replay/watching), [Storage](https://packstub.dev/docs/session-replay/storage), [Error tracking](https://packstub.dev/docs/session-replay/error-tracking), [Configuration](https://packstub.dev/docs/session-replay/configuration).

## Credits

Recording and playback are [rrweb](https://github.com/rrweb-io/rrweb) (MIT), through the core package.

## License

MIT. See [LICENSE.md](LICENSE.md).
