# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

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
