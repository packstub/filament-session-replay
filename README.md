# Filament Session Replay

<div class="filament-hidden">

![Filament Session Replay — watch what happened, inside your own panel](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-session-replay.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-session-replay)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-session-replay/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-session-replay/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-session-replay.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-session-replay)
[![License](https://img.shields.io/packagist/l/packstub/filament-session-replay.svg?style=flat-square)](https://github.com/packstub/filament-session-replay/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

Session replay inside your Filament panel. "The form did nothing when I clicked save" becomes a replay you watch next to the user it belongs to, with the failed Livewire request, the JavaScript error and the rage click marked on the timeline. Recordings stay on your own disk and database, and who may watch them is a gate your app defines. Free and open source (MIT), built on [Session Replay for Laravel](https://github.com/packstub/session-replay) and [rrweb](https://github.com/rrweb-io/rrweb).

> **Beta.** 1.0 is close and feedback is very welcome in the [issues](https://github.com/packstub/filament-session-replay/issues). Until 1.0, names and config may still change between betas; the changelog says how to upgrade.

## Features

- **[Recording without a directive](#recording-a-panel)**: register the plugin and the panel's pages are recorded, with its guard, tenant and impersonator.
- **[A Sessions resource](#the-sessions-resource)**: every recording with its errors, rage clicks and vitals, searchable and filterable.
- **[The player in the panel](#watching-a-recording)**: markers on the timeline and in a list that seeks on click, with Pin, Export and Delete.
- **[Around your users](#around-your-users)**: a relation manager, a "Watch last session" action and a dashboard widget.
- **[Masking where the field is](#masking-where-the-field-is)**: `->maskInReplay()` and `->blockInReplay()` on fields, columns, entries and sections.
- **[Workspace-aware](#workspaces-and-impersonation)**: each workspace sees its own recordings, an operator panel sees them all, impersonated sessions are flagged.
- **[One rule for access](#who-may-watch)**: the core's `viewSessionReplay` gate, or your own policy, so Filament Shield permissions plug in.
- **Dark mode, five languages**: replays look the way the page did, and the panel pages speak English, German, Spanish, Romanian and Russian.

## Compatibility

| Filament Session Replay | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 5.x | 12, 13 | 8.3+ |

## Installation

```bash
composer require "packstub/filament-session-replay:^1.0@beta" "packstub/session-replay:^1.0@beta"
php artisan session-replay:install
```

While both are in beta, require the core with `@beta` too: Composer takes a beta of a dependency only when your app asks for it.

Composer brings `packstub/session-replay` along; the install command publishes its config, runs the migrations and publishes `App\Providers\SessionReplayServiceProvider` with the gate in it. Register the plugin in your panel provider:

```php
use Packstub\SessionReplay\SessionReplayPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(SessionReplayPlugin::make());
}
```

Schedule the clean-up:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('session-replay:prune')->daily();
```

Open the panel, click around, then open **Session replays** in the navigation. [Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/installation.md)

## Recording a panel

The recorder is added through a render hook, so there is no directive to place. It records with what only the panel knows: the panel's guard, the current tenant, the panel id and who is impersonating. The pages recordings are watched on are never recorded. Record one panel and watch in another by registering the plugin twice:

```php
// The customer panel is recorded.
SessionReplayPlugin::make()->resource(false);

// The operator panel is where recordings are watched.
SessionReplayPlugin::make()->record(false)->widget();
```

`->record(fn (?Model $user): bool => ! $user?->is_staff)` narrows it per person. What recording costs (34 KB for a 50-row table page, 0.2 KB per idle minute) is measured in the [installation guide](https://github.com/packstub/filament-session-replay/blob/main/docs/installation.md#what-recording-a-panel-costs).

## The Sessions resource

![The Session replays resource in an operator panel: person, workspace, start, length, pages, errors and vitals of every recording, an impersonated session flagged](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/docs/sessions.png)

Newest first, searchable by a person's name, email or key, with the numbers that tell you which recording to open: errors, rage clicks and web vitals are indexed when the recording arrives, so filtering never opens a file.

![The filters of the Session replays table: errors, slow pages, rage clicks, impersonated, pinned, device, first page and start date](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/docs/filters.png)

[Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/watching.md)

## Watching a recording

![The watch page: the facts of the recording, the player paused on an edit modal where Save changes was clicked again and again, and the marker list with failed Livewire requests, the rage click and a TypeError](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/docs/watch.png)

The watch page shows the facts, the player and the markers: page views, failed Livewire requests, uncaught errors, `console.error`, web vitals and rage clicks. A click on a marker seeks to a second before it, and `?t=83` opens a replay at 1:23, which makes a good link for a ticket. **Pin** keeps a recording out of pruning, **Export** downloads it as one JSON file with its stylesheets, **Delete** removes it with its files. [Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/watching.md#the-watch-page)

## Around your users

![A customer's page in the panel with a Session replays tab listing that person's recordings](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/docs/person-replays.png)

```php
use Packstub\SessionReplay\Concerns\HasSessionReplays;
use Packstub\SessionReplay\Filament\Actions\WatchLastSessionAction;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\RelationManagers\ReplaysRelationManager;

class User extends Authenticatable
{
    use HasSessionReplays;
}

// In your user resource:
public static function getRelations(): array
{
    return [ReplaysRelationManager::class];
}

// On a row or in a page header:
WatchLastSessionAction::make(),
```

![A customers table with a Watch last session action on the rows of people who have a recording](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/docs/watch-last-session.png)

`->widget()` adds the last seven days to the dashboard, each number linked to the filtered list:

![The stats widget: recordings in the last 7 days, with errors, slow pages](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/docs/stats-widget.png)

[Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/people.md)

## Masking where the field is

Every input is masked by default and passwords always are. For what a page *displays*, say so where the field is declared:

```php
TextColumn::make('customer')->maskInReplay(),
TextEntry::make('iban')->maskInReplay(),
Section::make('Payout details')->blockInReplay(),
```

![A replayed Orders table whose Customer column shows asterisks while the rest of the page stays readable](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/docs/masking.png)

Mask keeps the page readable for whoever watches (the value is there, its content is not); block records an empty box of the same size. Masking happens in the browser, before anything is uploaded. [Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/masking.md)

## Workspaces and impersonation

In a panel with tenancy the resource and the widget list the current workspace's recordings; a panel without a tenant lists all of them with a **Workspace** column (`->tenantLabelUsing()` when your tenant has no `name`). Sessions made while impersonating carry who was behind them: Packstub's [Account Switcher](https://github.com/packstub/filament-account-switcher) is detected, `->impersonatorUsing()` covers anything else. Database-per-tenant apps keep the index on the central connection. [Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/tenancy.md)

## Who may watch

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    return $user->is_admin;
});
```

The gate is asked without a recording for the list and with it for a single replay and every file behind it, in the panel and on the core's data routes alike. Until it is defined only the local environment is let in. A policy registered for `ReplaySession` takes over inside the panel (Filament Shield), `deleteSessionReplay` separates deleting from watching, and `SessionReplay::visibleUsing()` keeps rows the gate would refuse out of every list. [Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/watching.md#who-may-do-what)

## Two packages, one picture

| Package | What it does |
| --- | --- |
| [`packstub/session-replay`](https://github.com/packstub/session-replay) | Records (rrweb, masked inputs, markers), ingests, stores gzip chunks on your disk with an index in your database, prunes, links the recording into Laravel's log context, and guards every byte with the `viewSessionReplay` gate. Works in any Laravel app. |
| `packstub/filament-session-replay` (this one) | The panel experience: recording wired to the panel's guard and tenant, the Sessions resource, the watch page, the relation manager, the action, the widget and the masking macros. |

Everything about privacy, storage, consent and configuration lives in the core and applies here unchanged. Hosted replay products are a great fit for product analytics and funnels; this pair is for replay inside your own app, and the two run happily side by side.

## Documentation

| Guide | What it covers |
| --- | --- |
| [Overview](https://github.com/packstub/filament-session-replay/tree/main/docs) | What you get, at a glance |
| [Installation](https://github.com/packstub/filament-session-replay/blob/main/docs/installation.md) | Install, register, the gate, the plugin's options, languages, recording in one panel and watching in another |
| [Watching](https://github.com/packstub/filament-session-replay/blob/main/docs/watching.md) | The resource, its filters, the watch page, Pin, Export, Delete, who may do what |
| [Masking](https://github.com/packstub/filament-session-replay/blob/main/docs/masking.md) | `maskInReplay()` and `blockInReplay()` |
| [People](https://github.com/packstub/filament-session-replay/blob/main/docs/people.md) | The relation manager, "Watch last session", the stats widget, naming and finding people |
| [Tenancy](https://github.com/packstub/filament-session-replay/blob/main/docs/tenancy.md) | Workspaces, operator panels, database-per-tenant apps, impersonation |

The core's guides cover the rest: [Recording](https://github.com/packstub/session-replay/blob/main/docs/recording.md), [Privacy](https://github.com/packstub/session-replay/blob/main/docs/privacy.md), [Watching replays](https://github.com/packstub/session-replay/blob/main/docs/watching.md), [Storage](https://github.com/packstub/session-replay/blob/main/docs/storage.md), [Error tracking](https://github.com/packstub/session-replay/blob/main/docs/error-tracking.md), [Configuration](https://github.com/packstub/session-replay/blob/main/docs/configuration.md).

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Security

Please report security issues to support@packstub.dev instead of the issue tracker.

## Credits

- [Packstub](https://packstub.dev)
- Recording and playback are [rrweb](https://github.com/rrweb-io/rrweb) (MIT), through the core package.

## License

MIT. See [LICENSE.md](LICENSE.md).
