# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased

### Changed

- **Requires Session Replay 1.0.0-beta.3 or newer** (`packstub/session-replay: ^1.0.0-beta.3`): Export reads the shared page snapshots that version introduced.
- **Export carries shared snapshots.** A recording made on a page with shared snapshots (`snapshots.share_routes`, `snapshots.share_paths`) keeps only the snapshot's hash in its events (`{"type": 2, "data": {"srSnapshot": "<sha256>"}}`). The file now has a `snapshots` key with the trees the recording points at, and `assets` includes the stylesheets inside those trees, so the export replays on its own as promised. The key is new; nothing else in the file changed.
- **Lab.** `workbench/lab/navigate.mjs` checks in a real panel what the recorder does across `wire:navigate`: pages the app does not record stay out of the recording, console messages during a swap are kept, and a page signed for another identity starts a new recording (also in mode `on_error`). The workbench takes `LAB_EXCEPT_ROUTES` and `LAB_IMPERSONATE_ON` for it.
- **Docs.** A shorter Features list in the README and on the docs index, one line per area.
- **Docs.** The installation guide covers turning off the core's built-in viewer for panel-only apps (`viewer.enabled = false`), a panel on its own guard (`viewer.middleware`, `viewer.guard`) and a shared, persistent recording disk before production. Watching says what Pin allows: whoever may watch a recording may keep it past retention.
- **Tests.** Session Replay 1.0.0-beta.2's `privacy.anonymous` and `except_routes` checked in a real panel: the token keeps the workspace and drops the person and the impersonator, and the default route-name patterns match the password-reset and verification pages Filament registers.

### Fixed

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
