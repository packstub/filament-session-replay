# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased

**Security:** on Filament 5.11 and later, the watch page of a recording the `viewSessionReplay` gate (or the policy) refused still opened for anyone who could see the Sessions list, showing whose recording it was and when; the player's data stayed behind the gate. The page asks the gate again on every Filament 5.

## 1.1.1 — 2026-11-04

A security release with Session Replay 1.1.0, which it now requires (`packstub/session-replay: ^1.1`), and faster lists on a large table.

**Security:** a recording that pointed at no stylesheet (from before Session Replay 1.0.0-beta.5, or one whose CSS stayed inline) had every stylesheet its events named copied into the export, by hash, whichever workspace stored it. The export now carries only the stylesheets the recording points at, as the core's stylesheet route serves them; such recordings export without them.

- The Started filter bounds `started_at` itself, so a date range uses the index instead of scanning the table
- The person search no longer asks the schema on every keystroke
- Delete says so when a recording's files could not be removed from the disk (the core keeps the row for the next prune)
- `workbench/lab/memory.mjs` measures what recording a panel costs in memory over a day's work; it found the `wire:navigate` leak Session Replay 1.1.0 fixes. See [What recording a panel costs](docs/installation.md#what-recording-a-panel-costs)
- **Docs:** Installation says what a Content Security Policy needs on the recorded pages and on the watch page

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
