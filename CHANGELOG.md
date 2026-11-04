# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased
<<<<<<< HEAD

Faster lists on a large table, and a lab that measures what recording costs in memory.

- The Started filter bounds `started_at` itself, so a date range uses the index instead of scanning the table.
- The person search no longer asks the schema on every keystroke.
- `workbench/lab/memory.mjs` samples the JS heap, the DOM nodes kept alive and the renderer's RSS over a day's panel work, with the recorder on and off; it found the `wire:navigate` leak the core fixes in its next release. See [What recording a panel costs](docs/installation.md#what-recording-a-panel-costs).

## 1.1.0 — 2026-10-28

### Added

- **Person filter and a deep link to one person's recordings.** The Sessions list gets a **Person** filter (`person`: the person's key, and their morph type in a hidden field), with an indicator that names the person the way the list does (`userLabelUsing()` included), recording or not. `ReplaySessionResource::getUrlForPerson($user, ?$panel, ?$tenant)` is the URL of the list with that filter on, for your own pages and for [Account Switcher](https://packstub.dev/docs/filament-account-switcher)'s impersonation banner, whose **Session replays** button (Account Switcher 4.4) lands on it. See [Watching](docs/watching.md#filters) and [People](docs/people.md#one-persons-recordings).
=======
>>>>>>> origin/main

### Changed

- **Docs.** Installation says what a Content Security Policy needs: the recorder's tags carry `Vite::cspNonce()` like Livewire's, and the watch page needs `'unsafe-inline'` in `style-src`, as a panel's own layout already does.

## 1.1.0 — 2026-10-28

- A **Person** filter on the Sessions list, and `ReplaySessionResource::getUrlForPerson()` to link to one person's recordings
- Account Switcher 4.4's impersonation banner uses that link for its **Session replays** button
- The README shows an animation of the watch page and links the full docs on packstub.dev

## 1.0.0 — 2026-10-22

The first stable release. Requires Session Replay 1.0 (`packstub/session-replay: ^1.0`).

- A **Reported** column and filter for recordings where someone pressed a "report a problem" button
- The impersonator is named like a person when the core keeps them as a model
- The `viewSessionReplay` gate now decides before a `ReplaySession` policy, as it does for the player, so the watch page and the player agree
- Export includes shared snapshots, so an exported recording replays on its own
- Privacy: no recordings from other workspaces with a `visibleUsing()` rule that uses `orWhere`, and Export copies only the recording's own stylesheets
- Faster people search on large tables, and replay links that work with the core's pages turned off

Docs: https://packstub.dev/docs/filament-session-replay

## 1.0.0-beta.1 — 2026-10-02

First beta of Filament Session Replay: record your panel's pages and watch them in a Sessions resource. Names and config may still change before 1.0.

- `SessionReplayPlugin` records the panel with its own user, tenant and impersonator, no directive needed
- A Sessions resource with filters for errors, poor vitals, rage clicks and more; watch, pin, delete and export
- People: a relation manager, a "watch last session" action and a stats widget
- `->maskInReplay()` and `->blockInReplay()` on form fields, columns, entries and layout components
- Recordings kept to the current workspace in a panel with tenancy, and to what the gate allows
- In English, German, Spanish, Romanian and Russian

Docs: https://packstub.dev/docs/filament-session-replay
