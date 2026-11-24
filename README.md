# Filament Session Replay

<div class="filament-hidden">

![Filament Session Replay — watch what happened, inside your own panel](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-session-replay.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-session-replay)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-session-replay/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-session-replay/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-session-replay.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-session-replay)
[![License](https://img.shields.io/packagist/l/packstub/filament-session-replay.svg?style=flat-square)](https://github.com/packstub/filament-session-replay/blob/main/LICENSE.md)
[![Listed on filamentphp.com](https://img.shields.io/badge/filamentphp.com-listed-fb7185?style=flat-square&logo=filament&logoColor=white)](https://filamentphp.com/plugins/packstub-session-replay)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

Session replay inside your Filament panel. "The form did nothing when I clicked save" becomes a replay you watch next to the user it belongs to, with the failed Livewire request, the JavaScript error and the rage click marked on the timeline. And when someone needs help right now, **Assist** brings support onto their page live: see it as it happens, point, draw, take control with their permission, and talk over audio, video and screen share, all from the panel. Recordings stay on your own disk and database, and who may watch or help is a gate your app defines. Free and open source (MIT), built on [Session Replay for Laravel](https://github.com/packstub/session-replay), [rrweb](https://github.com/rrweb-io/rrweb) and [Filament Holler](https://github.com/packstub/filament-holler).

![A replay on the watch page: the pointer clicks "Save changes" again and again, the save fails, and the server error's details open with its report id and "Open in logs"](https://raw.githubusercontent.com/packstub/art/main/filament-session-replay/demo.gif)

<div class="filament-hidden">

Featured on Laravel News: [Session Replay for Laravel: Self-Hosted Browser Recordings](https://laravel-news.com/laravel-session-replay).

</div>

## Features

- **[Recording without a directive](#recording-a-panel)**: register the plugin and the panel's pages are recorded, with its guard, tenant and impersonator.
- **[A Sessions resource](#the-sessions-resource)**: every recording with its errors, rage clicks and vitals, searchable and filterable.
- **[The player in the panel](#watching-a-recording)**: markers on the timeline and in a list that seeks on click, with Pin, Export and Delete.
- **[Help requests](#assist-live-help-on-the-visitors-page)**: visitors ask from the page they are on, support gets a toast and a badge, claims the request or offers help on a recording that is live now.
- **[Live co-browsing, calls and remote control](#the-live-view)**: the visitor's page mirrored as it happens, your pointer and drawings on their page, control of it, audio, video and screen share, each with their yes.
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
composer require packstub/filament-session-replay
php artisan session-replay:install
```

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

## Assist: live help on the visitor's page

A replay tells you what happened. Assist lets you help while it is happening: the visitor asks for help from the page they are on, or you see them on a live recording and offer it, and you are on their page together, without leaving the panel.

```env
SESSION_REPLAY_ASSIST=true
```

```bash
composer require packstub/filament-holler   # for the live view: calls, co-browsing, remote control
php artisan migrate && php artisan filament:assets
```

```php
use Packstub\FilamentHoller\FilamentHollerPlugin;
use Packstub\SessionReplay\SessionReplayPlugin;

$panel->plugins([
    SessionReplayPlugin::make(),
    FilamentHollerPlugin::make(),
]);
```

### Help requests

- **Asking.** A visitor asks from your own "Contact support" button (`SessionReplay.assist.request({ message })`) or the core's floating **Get help** button, signed in or a guest. A small panel on their page says they are waiting, with Cancel.
- **Help requests** in the navigation lists who waits, with their message, the page they are on, how long they have waited and whether they are still there. The number waiting is the navigation badge.
- **Notifications.** Everyone who may take the request gets a toast on whatever panel page they are on, with **Claim**, and a note in the bell when the panel has database notifications.
- **Claim** takes a request (two agents never both get it); **Withdraw** hands it back, **End** finishes it.
- **Live now and Offer help.** The recordings list has a **Live now** tab ("last seen 40 s ago"). Offer help on a live recording and the visitor sees "Ana would like to help you with this page", with Accept and Not now.

Without Holler, help requests are a queue your team answers through its own channels.

### The live view

With [Filament Holler](https://github.com/packstub/filament-holler) on the panel, Claim and Offer help open the **live view**, in the panel's own buttons and colours:

- **Co-browsing.** The visitor's page, mirrored as it happens in a sandboxed frame without scripts, masked exactly like the recording. Peer to peer over WebRTC, about 300 ms behind them.
- **Your pointer on their page.** While you watch, they see your named cursor where you point ("this button, up here"). **Draw** leaves strokes on their page that fade after a few seconds.
- **Remote control.** Once they allow it: clicks, drags, scrolling and typing into fields, across the pages of a flow such as a checkout, with a dashed frame round their page the whole time. Passwords are never filled; their own mouse or keys pause you, and Escape takes control back.
- **Audio and video calls.** Talk it through with **Start audio** and **Start video**, with Mute and End.
- **Screen share, both ways.** Ask to see their screen when the problem is outside the page, or share yours to show them how.
- **Put on hold.** Step out to ask a colleague on a regular Holler call and come back with **Resume**; the visitor's page says you will be right back.
- **What happened.** Every tool that cannot be used now says why, and what the visitor allowed, refused or took back is listed as it happens.

Each step asks the visitor first, in its own question that cannot be answered by a stray keypress, and every answer goes to the request's grants log, your record of consent. Audio, video and screens are never stored. Who may help is the same gate as who may watch, narrowed with `SessionReplay::canAssistUsing()`; in a panel with tenancy each workspace sees its own requests. Assist needs Session Replay for Laravel 1.2, which this plugin requires. [Read more](https://github.com/packstub/filament-session-replay/blob/main/docs/assist.md)

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

## The packages, one picture

| Package | What it does |
| --- | --- |
| [`packstub/session-replay`](https://github.com/packstub/session-replay) | Records (rrweb, masked inputs, markers), ingests, stores gzip chunks on your disk with an index in your database, prunes, links the recording into Laravel's log context, and guards every byte with the `viewSessionReplay` gate. Works in any Laravel app. |
| `packstub/filament-session-replay` (this one) | The panel experience: recording wired to the panel's guard and tenant, the Sessions resource, the watch page, Help requests and the live view, the relation manager, the action, the widget and the masking macros. |
| [`packstub/filament-holler`](https://github.com/packstub/filament-holler) (optional) | The calls behind Assist's live view: audio, video, screen share and the data channel the mirror and remote control travel on, plus a calls widget for your team. |

Everything about privacy, storage, consent and configuration lives in the core and applies here unchanged. Hosted replay products are a great fit for product analytics and funnels; this pair is for replay inside your own app, and the two run happily side by side.

## Documentation

The full documentation, both packages' guides in one place, is on [packstub.dev/docs/filament-session-replay](https://packstub.dev/docs/filament-session-replay). On GitHub:

| Guide | What it covers |
| --- | --- |
| [Overview](https://github.com/packstub/filament-session-replay/tree/main/docs) | What you get, at a glance |
| [Installation](https://github.com/packstub/filament-session-replay/blob/main/docs/installation.md) | Install, register, the gate, the plugin's options, languages, recording in one panel and watching in another |
| [Watching](https://github.com/packstub/filament-session-replay/blob/main/docs/watching.md) | The resource, its filters, the watch page, Pin, Export, Delete, who may do what |
| [Assist](https://github.com/packstub/filament-session-replay/blob/main/docs/assist.md) | Help requests, notifications, Offer help, the live view beside Filament Holler |
| [Masking](https://github.com/packstub/filament-session-replay/blob/main/docs/masking.md) | `maskInReplay()` and `blockInReplay()` |
| [People](https://github.com/packstub/filament-session-replay/blob/main/docs/people.md) | The relation manager, "Watch last session", the stats widget, naming and finding people |
| [Tenancy](https://github.com/packstub/filament-session-replay/blob/main/docs/tenancy.md) | Workspaces, operator panels, database-per-tenant apps, impersonation |

The core's guides cover the rest: [Recording](https://github.com/packstub/session-replay/blob/main/docs/recording.md), [Privacy](https://github.com/packstub/session-replay/blob/main/docs/privacy.md), [Watching replays](https://github.com/packstub/session-replay/blob/main/docs/watching.md), [Storage](https://github.com/packstub/session-replay/blob/main/docs/storage.md), [Error tracking](https://github.com/packstub/session-replay/blob/main/docs/error-tracking.md), [Configuration](https://github.com/packstub/session-replay/blob/main/docs/configuration.md).

Guide: [Session replay in Laravel and Filament, on your own server](https://packstub.dev/guides/laravel-session-replay-self-hosted), a walkthrough from the first recording to watching it behind your own gate, with measured storage sizes.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](https://github.com/packstub/filament-session-replay/blob/main/CHANGELOG.md).

## Security

Please report security issues to support@packstub.dev instead of the issue tracker.

<div class="filament-hidden">

## More from Packstub

Other Packstub plugins that sit well next to this one:

- **[Filament Tenancy](https://packstub.dev/plugins/filament-tenancy)** (paid): a database per tenant for Filament panels, built on stancl/tenancy. Session Replay lists each workspace's own recordings and keeps its index on the central connection, so the two fit together.
- **[Filament Account Switcher](https://packstub.dev/plugins/filament-account-switcher)**: impersonation, linked accounts and one-click developer logins in the panel. Session Replay flags the sessions recorded while impersonating with it.
- **[Filament Agents](https://packstub.dev/plugins/filament-agents)**: an AI assistant in the panel and an MCP server from one list of tools, under the panel's own authorization.
- **[Filament Flow](https://packstub.dev/plugins/filament-flow)**: visual workflow automations for a Filament panel, with triggers, conditions and actions drawn on a canvas.
- **[Filament Kanban](https://packstub.dev/plugins/filament-kanban)**: a Kanban board for Filament with instant drag and drop and rules checked on the server.
- **[Nitpick](https://packstub.dev/plugins/filament-nitpick)**: themes for Filament panels, and a way for every person to pick their own.

All plugins: [packstub.dev/plugins](https://packstub.dev/plugins).

Packstub is made by [XLITE](https://xlite.dev), a small studio that also builds Laravel and Filament apps for clients.

</div>

## Credits

- [Packstub](https://packstub.dev)
- Recording and playback are [rrweb](https://github.com/rrweb-io/rrweb) (MIT), through the core package.

## License

MIT. See [LICENSE.md](https://github.com/packstub/filament-session-replay/blob/main/LICENSE.md).
