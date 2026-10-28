# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased

### Added

- **Assist in the panel: help requests, notifications, Offer help and the live view.** With the core's Assist on (`SESSION_REPLAY_ASSIST`), the Sessions resource gets **Help requests** (a page under it in the navigation, with the number of visitors waiting as the badge): a table over the core's queue for the signed-in agent (status, person, message as text, page, waiting time, still here), polled every five seconds, with **Claim**, **Open live view**, **Withdraw** and **End**, each asking the core again (the gate or policy, `visibleUsing()`, `canAssistUsing()`, the panel's workspace). Everyone who may take a new request gets a toast on any panel page, with **Claim** and **View requests**, once per browser session, and in the bell too when the panel has database notifications on (`->assistNotifications(false)` turns them off). The recordings list gets a **Live now** tab and says when a live recording's page was last heard from ("last seen 40 s ago"); a live recording of someone else has **Offer help** in its row and on its watch page, and the agent's own has **Open live view**. With [`packstub/calls`](https://github.com/packstub/calls) and [Filament Calls](https://github.com/packstub/filament-calls) (both suggested), Claim and Offer help lead to the **live view**: the core's stage (the visitor's page mirrored live, remote control, drawing, audio, video, screen share) on a page of the panel, its tools as the panel's own buttons and button groups, a status badge in the panel's colours, dark mode, beside the Filament Calls widget (the stage runs its own Calls client over the panel's Calls routes with `kind=assist`; the widget polls `kind=call`). The live view is a page of its own, loaded in full and not kept across `wire:navigate`: leaving it leaves the call, the request stays the agent's and Open live view picks it up again, on a new call when the agent was away longer than Calls waits (the visitor's page says they are reconnecting meanwhile). Who holds a request is the core's check (key, model type and guard, `Assist::holds()`) for Open live view, Withdraw, End and the live view, so a panel user and a user of another model with the same key are different people; the live view polls Calls as often as Calls' `poll.active` says, and the queue is narrowed to the panel's workspace in the core's query. `->assist(false)` keeps a panel to recordings. Icons under aliases (`SessionReplayIconAlias`), strings in all four languages, `workbench/lab/assist.mjs` runs a visitor and an agent in two browsers through the toast, Claim, the live view, a `wire:navigate` away and back, and End. See [Assist](docs/assist.md).

### Changed

- **Requires Session Replay 1.1** (`packstub/session-replay: ^1.1`) for Assist: its requests, routes, queue and the stage's host seam (`[data-label]` on a button, the `session-replay:stage` event, leaving the call on `livewire:navigating`). The live view also needs Calls for Laravel and Filament Calls, which are released separately (suggested in `composer.json`).

## 1.1.0 — 2026-10-28

### Added

- **Person filter and a deep link to one person's recordings.** The Sessions list gets a **Person** filter (`person`: the person's key, and their morph type in a hidden field), with an indicator that names the person the way the list does (`userLabelUsing()` included), recording or not. `ReplaySessionResource::getUrlForPerson($user, ?$panel, ?$tenant)` is the URL of the list with that filter on, for your own pages and for [Account Switcher](https://packstub.dev/docs/filament-account-switcher)'s impersonation banner, whose **Session replays** button (Account Switcher 4.4) lands on it. See [Watching](docs/watching.md#filters) and [People](docs/people.md#one-persons-recordings).

### Changed

- **Docs.** The README shows an animation of the watch page: a rage click, the server error's details and "Open in logs". Docs sync to packstub.dev on every change to `docs/` (`docs-sync.yml`).
- **Docs.** The README links the full documentation on packstub.dev, and its Changelog and License links are absolute, so they work on the filamentphp.com listing.
- **Docs.** A "Listed on filamentphp.com" badge in the README.

## 1.0.0 — 2026-10-22

### Added

- **Reported recordings.** A **Reported** column (a badge on recordings where the person pressed a "report a problem" button, `SessionReplay.send()`) and a **Reported a problem** filter (`reported`), so support finds what people reported. New strings in all four languages.
- **The impersonator named like a person.** When the core keeps the impersonator as a model (Session Replay 1.0: `impersonatorUsing()` may return the model; the plugin passes Packstub's Account Switcher's impersonator model through), the list says "Impersonated by grace@example.com" (`viewer.user_label`, name, email) and the watch page names them too; a bare key is shown as `#7`, as before. `SessionReplayPlugin::impersonatorLabel($session)` is the helper.

### Changed

- **Requires Session Replay 1.0** (`packstub/session-replay: ^1.0`): the `report` marker, the impersonator model and the policy path in `SessionReplay::check()` are from there.
- **The gate decides before a policy, as it does for the player's data.** The resource asked a `ReplaySession` policy first and the `viewSessionReplay` gate only without one, while the core's data routes ask the gate first and the policy only without a gate, so an app with the installer's gate and a Shield policy opened the watch page to a person the policy allowed and the player answered "You are not allowed to watch this recording". The resource now asks `SessionReplay::check()` for the list, the watch page and the export: the gate when the app defined it, else the policy (`viewAny`, `view`), else the local environment only. `delete` and `deleteAny` keep asking the policy when there is one, else the `deleteSessionReplay` gate.
- **Methods that serve the resource are marked `@internal`** (`SessionReplayPlugin::recorder()` and its `get*()` readers, `ReplaySessionResource::searchPeople()`, `shortUrl()`, `listsEveryWorkspace()`, `pinAction()`); `scope()`, `userLabel()`, `tenantLabel()`, `impersonatorLabel()` and `WatchLastSessionAction::lastSession()` are the extension points.
- **Export carries shared snapshots.** A recording made on a page with shared snapshots (`snapshots.share_routes`, `snapshots.share_paths`) keeps only the snapshot's hash in its events (`{"type": 2, "data": {"srSnapshot": "<sha256>"}}`). The file now has a `snapshots` key with the trees the recording points at, and `assets` includes the stylesheets inside those trees, so the export replays on its own as promised. The key is new; nothing else in the file changed.
- **Lab.** `workbench/lab/navigate.mjs` checks in a real panel what the recorder does across `wire:navigate`: pages the app does not record stay out of the recording, console messages during a swap are kept, and a page signed for another identity starts a new recording (also in mode `on_error`). The workbench takes `LAB_EXCEPT_ROUTES` and `LAB_IMPERSONATE_ON` for it.
- **Docs.** A shorter Features list in the README and on the docs index, one line per area. The installation guide and the docs index link Packstub's Account Switcher where the impersonator is explained.
- **Docs.** The installation guide covers turning off the core's built-in viewer for panel-only apps (`viewer.enabled = false`), a panel on its own guard (`viewer.middleware`, `viewer.guard`) and a shared, persistent recording disk before production. Watching says what Pin allows: whoever may watch a recording may keep it past retention.
- **Tests.** Session Replay 1.0.0-beta.2's `privacy.anonymous` and `except_routes` checked in a real panel: the token keeps the workspace and drops the person and the impersonator, and the default route-name patterns match the password-reset and verification pages Filament registers.

### Fixed

- **Export copies only the stylesheets the recording points at**, as the core serves them: a stylesheet may carry a workspace's own theme, and a chunk that named another workspace's stylesheet by hash used to copy it into the file. A recording from before stylesheets were referenced (core 1.0.0-beta.5 and earlier) points at none and keeps every stylesheet it names, as before.
- **Replay links with the core's pages off.** With `viewer.enabled = false` and no `SessionReplay::urlUsing()` of the app's own, `ReplaySession::url()` and the log context's `session_replay_url` were empty. A panel with the Sessions resource now links to its watch page (with the recording's workspace in a panel with tenancy), and a URL built for a named panel takes that panel's `slug()`.
- **Export copies stylesheets only.** Assets are looked up by hash and `kind = stylesheet`, so an `/*sr-asset:<sha256>*/` placeholder in an uploaded chunk can no longer copy a stored shared snapshot of another page into the file.
- **A workspace stays a workspace with `SessionReplay::visibleUsing()`.** The app's rule is applied as a nested group, so an `orWhere` in it no longer lists other workspaces' recordings in a panel with tenancy, or other people's in a person's Session replays tab.
- **Search reads the people table once.** A search looked for the text in the whole people table, 500 people at a time, until enough of them had recordings in the list, which was up to forty queries per keystroke on a large table. The list's own people are usually far fewer, so the search now reads their keys from the list (5,000 at most) and looks for the text among them, one query per 500 keys; only a list with more people than that falls back to reading the people table in batches. Still no join, so the index may stay on its own connection.
- **Search finds this list's people.** The people types are read from the rows the list can show instead of the whole table, and the 200-person limit now counts people with recordings in the current workspace (and what `visibleUsing()` allows), read 500 matching people at a time and at most 10,000, so namesakes in other workspaces no longer crowd them out. Still no join, so the index may stay on its own connection.
- **Workspace column.** Shown whenever the list holds every workspace, `scopeToTenant(false)` included, not only in a panel without a current tenant; and the workspaces are loaded with the rows (one query per tenant type) instead of one query per row.

## 1.0.0-beta.1 — 2026-10-02

First beta. Names and config may still change before 1.0; every change will be listed here with how to upgrade.

### Added

- **`SessionReplayPlugin`.** Records the panel's pages without a directive: the recorder is added through a render hook with the panel's own guard user, the Filament tenant, the panel id and whoever is impersonating (Packstub's Account Switcher is detected; `impersonatorUsing()` for anything else). `record()`, `resource()`, `properties()`, navigation options, `slug()`, `scopeToTenant()`. The pages recordings are watched on are never recorded.
- **Sessions resource.** Person, workspace, started, length, pages, errors, rage clicks, vitals, device, first page, pinned, size; filters for errors, poor vitals, rage clicks, impersonated, pinned, device, first page and date; watch, pin, delete. The first page shows as a path when it is a page of this app.
- **Watch page.** The facts of the recording, the player with its markers, Pin, Delete and Export (one JSON file with the events, the markers and the stylesheets). The mouse trail and clicks are drawn in the panel's primary colour.
- **Authorization.** The core's `viewSessionReplay` gate, with the recording; a registered `ReplaySession` policy takes over (Shield); `deleteSessionReplay` when the app defines it. A bulk delete asks about every selected recording, and Export asks the gate as well as the policy, like the core's data routes, and leaves out a stored chunk that is not a JSON array.
- **People.** `ReplaysRelationManager`, `WatchLastSessionAction`, `ReplayStatsWidget` (`->widget()`).
- **Masking where the field is.** `->maskInReplay()` and `->blockInReplay()` on form fields, table columns, infolist entries and layout components.
- **Workspaces.** In a panel with tenancy the resource, a person's tab, the widget and `WatchLastSessionAction` keep to the current workspace's recordings; a Workspace column everywhere else, named by `tenantLabelUsing()` when the tenant has no `name`.
- **Finding people.** The table search looks in the `name` and `email` columns of the recorded people's own tables (`searchPeopleBy()`), per model and without a join, next to the exact key.
- **Rows the gate would refuse.** The resource, the relation manager, the widget and `WatchLastSessionAction` start from the core's `SessionReplay::visibleUsing()`, so a rule that narrows single recordings narrows every list too, in SQL.
- **Languages.** English, German, Spanish, Romanian and Russian.
