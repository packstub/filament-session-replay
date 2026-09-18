# Filament Session Replay

Session replay inside a Filament panel: the recordings of [Session Replay for Laravel](https://packstub.dev/docs/session-replay) in a filterable resource, the player with markers on the timeline, and the pieces that connect recordings to your users. Free and open source (MIT).

- Repository: [github.com/packstub/filament-session-replay](https://github.com/packstub/filament-session-replay)
- Support: [GitHub issues](https://github.com/packstub/filament-session-replay/issues)
- Core package: [Session Replay for Laravel](https://packstub.dev/docs/session-replay), installed with this one

## What you get

| Feature | What it means for you |
| --- | --- |
| **Recording from the plugin** | `->plugin(SessionReplayPlugin::make())` records the panel's pages. No Blade directive: a render hook adds the recorder with the panel's guard user, the current Filament tenant, the panel id and the impersonator. |
| **A Sessions resource** | Every recording with person, workspace, start, length, pages, errors, rage clicks, vitals, device and first page. Filters for errors, slow pages, rage clicks, impersonated, pinned, device, first page and start date. |
| **The watch page** | The facts of the recording and the player, with errors, failed requests, console output, vitals, rage clicks and page views on the timeline and in a list that seeks on click. Pin, Export, Delete. |
| **Around your users** | A relation manager for a person's page, a "Watch last session" action for a row, a stats widget for the dashboard. |
| **Masking on the field** | `->maskInReplay()` and `->blockInReplay()` on form fields, table columns, infolist entries and layout components. |
| **Workspace-aware** | A panel with tenancy lists the current workspace's recordings; an operator panel lists all of them with a Workspace column. Impersonated sessions are flagged. |
| **One access rule** | The core's `viewSessionReplay` gate, asked with the recording. A policy for `ReplaySession` takes over when you register one. |

Privacy defaults, consent, storage, pruning, limits and the log context come from the core and are documented there.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, install, registering the plugin, the gate, the scheduler, the plugin's options, recording in one panel and watching in another |
| [Watching](watching.md) | The resource's columns and filters, the watch page, Pin, Export and Delete, who may do what, policies and Filament Shield |
| [Masking](masking.md) | `maskInReplay()` and `blockInReplay()` on fields, columns, entries and layout components |
| [People](people.md) | `HasSessionReplays`, the relation manager, `WatchLastSessionAction`, `ReplayStatsWidget`, naming people |
| [Tenancy](tenancy.md) | Workspaces, operator panels, database-per-tenant apps, the Account Switcher and impersonation |

From the core: [Recording](https://packstub.dev/docs/session-replay/recording), [Privacy](https://packstub.dev/docs/session-replay/privacy), [Watching replays](https://packstub.dev/docs/session-replay/watching), [Storage](https://packstub.dev/docs/session-replay/storage), [Error tracking](https://packstub.dev/docs/session-replay/error-tracking), [Configuration](https://packstub.dev/docs/session-replay/configuration).
