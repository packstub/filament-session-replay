# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased

First version, not tagged yet.

### Added

- **`SessionReplayPlugin`.** Records the panel's pages without a directive: the recorder is added through a render hook with the panel's own guard user, the Filament tenant, the panel id and whoever is impersonating (Packstub's Account Switcher is detected; `impersonatorUsing()` for anything else). `record()`, `resource()`, `properties()`, navigation options, `slug()`, `scopeToTenant()`. The pages recordings are watched on are never recorded.
- **Sessions resource.** Person, workspace, started, length, pages, errors, rage clicks, vitals, device, first page, pinned, size; filters for errors, poor vitals, rage clicks, impersonated, pinned, device, first page and date; watch, pin, delete.
- **Watch page.** The facts of the recording, the player with its markers, Pin, Delete and Export (one JSON file with the events, the markers and the stylesheets).
- **Authorization.** The core's `viewSessionReplay` gate, with the recording; a registered `ReplaySession` policy takes over (Shield); `deleteSessionReplay` when the app defines it.
- **People.** `ReplaysRelationManager`, `WatchLastSessionAction`, `ReplayStatsWidget` (`->widget()`).
- **Masking where the field is.** `->maskInReplay()` and `->blockInReplay()` on form fields, table columns, infolist entries and layout components.
- **Workspaces.** In a panel with tenancy the resource and the widget list the current workspace's recordings; a Workspace column everywhere else.
