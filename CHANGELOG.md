# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased

### Added

- **Assist in the panel: help requests, notifications, Offer help and the live view.** With the core's Assist on (`SESSION_REPLAY_ASSIST`), the Sessions resource gets **Help requests** (a page under it in the navigation, with the number of visitors waiting as the badge): a table over the core's queue for the signed-in agent (status, person, message as text, page, waiting time, still here), polled every five seconds, with **Claim**, **Open live view**, **Withdraw** and **End**, each asking the core again (the gate or policy, `visibleUsing()`, `canAssistUsing()`, the panel's workspace). Everyone who may take a new request gets a toast on any panel page, with **Claim** and **View requests**, once per browser session, and in the bell too when the panel has database notifications on (`->assistNotifications(false)` turns them off). The recordings list gets a **Live now** tab and says when a live recording's page was last heard from ("last seen 40 s ago"); a live recording of someone else has **Offer help** in its row and on its watch page, and the agent's own has **Open live view**. With [`packstub/calls`](https://github.com/packstub/calls) and [Filament Calls](https://github.com/packstub/filament-calls) (both suggested), Claim and Offer help lead to the **live view**: the core's stage (the visitor's page mirrored live, remote control, drawing, audio, video, screen share) on a page of the panel, its tools as the panel's own buttons and button groups, a status badge in the panel's colours, dark mode, beside the Filament Calls widget (the stage runs its own Calls client over the panel's Calls routes with `kind=assist`; the widget polls `kind=call`). The live view is a page of its own, loaded in full and not kept across `wire:navigate`: leaving it leaves the call, the request stays the agent's and Open live view picks it up again, on a new call when the agent was away longer than Calls waits (the visitor's page says they are reconnecting meanwhile). Who holds a request is the core's check (key, model type and guard, `Assist::holds()`) for Open live view, Withdraw, End and the live view, so a panel user and a user of another model with the same key are different people; the live view polls Calls as often as Calls' `poll.active` says, and the queue is narrowed to the panel's workspace in the core's query. `->assist(false)` keeps a panel to recordings. Icons under aliases (`SessionReplayIconAlias`), strings in all four languages, `workbench/lab/assist.mjs` runs a visitor and an agent in two browsers through the toast, Claim, the live view, a `wire:navigate` away and back, and End. See [Assist](docs/assist.md).

### Changed

- **Requires Session Replay 1.1** (`packstub/session-replay: ^1.1`) for Assist: its requests, routes, queue and the stage's host seam (`[data-label]` on a button, the `session-replay:stage` event, leaving the call on `livewire:navigating`). The live view also needs Calls for Laravel and Filament Calls, which are released separately (suggested in `composer.json`).

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
