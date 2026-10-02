# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased

### Changed

- **Docs.** A shorter Features list in the README and on the docs index, one line per area.
- **Tests.** Session Replay 1.0.0-beta.2's `privacy.anonymous` and `except_routes` checked in a real panel: the token keeps the workspace and drops the person and the impersonator, and the default route-name patterns match the password-reset and verification pages Filament registers.

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
