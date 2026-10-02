# Filament Session Replay

Session replay inside a Filament panel: the recordings of [Session Replay for Laravel](https://github.com/packstub/session-replay/tree/main/docs) in a filterable resource, the player with markers on the timeline, and the pieces that connect recordings to your users. Free and open source (MIT).

- Repository: [github.com/packstub/filament-session-replay](https://github.com/packstub/filament-session-replay)
- Support: [GitHub issues](https://github.com/packstub/filament-session-replay/issues)
- Core package: [Session Replay for Laravel](https://github.com/packstub/session-replay/tree/main/docs), installed with this one

## Features

- **[Recording without a directive](installation.md#register-the-plugin)**: register the plugin and the panel's pages are recorded, with its guard, tenant and impersonator.
- **[A Sessions resource](watching.md#the-list)**: every recording with its errors, rage clicks and vitals, searchable and filterable.
- **[The player in the panel](watching.md#the-watch-page)**: markers on the timeline and in a list that seeks on click, with Pin, Export and Delete.
- **[Around your users](people.md)**: a relation manager, a "Watch last session" action and a dashboard widget.
- **[Masking where the field is](masking.md)**: `->maskInReplay()` and `->blockInReplay()` on fields, columns, entries and sections.
- **[Workspace-aware](tenancy.md)**: each workspace sees its own recordings, an operator panel sees them all, impersonated sessions are flagged.
- **[One rule for access](watching.md#who-may-do-what)**: the core's `viewSessionReplay` gate, or your own policy, so Filament Shield permissions plug in.

Privacy defaults, consent, storage, pruning, limits and the log context come from the core and are documented there.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, install, registering the plugin, the gate, the scheduler, the plugin's options, recording in one panel and watching in another |
| [Watching](watching.md) | The resource's columns and filters, the watch page, Pin, Export and Delete, who may do what, policies and Filament Shield |
| [Masking](masking.md) | `maskInReplay()` and `blockInReplay()` on fields, columns, entries and layout components |
| [People](people.md) | `HasSessionReplays`, the relation manager, `WatchLastSessionAction`, `ReplayStatsWidget`, naming people |
| [Tenancy](tenancy.md) | Workspaces, operator panels, database-per-tenant apps, the Account Switcher and impersonation |

From the core: [Recording](https://github.com/packstub/session-replay/blob/main/docs/recording.md), [Privacy](https://github.com/packstub/session-replay/blob/main/docs/privacy.md), [Watching replays](https://github.com/packstub/session-replay/blob/main/docs/watching.md), [Storage](https://github.com/packstub/session-replay/blob/main/docs/storage.md), [Error tracking](https://github.com/packstub/session-replay/blob/main/docs/error-tracking.md), [Configuration](https://github.com/packstub/session-replay/blob/main/docs/configuration.md).
