# Changelog

All notable changes to `packstub/filament-session-replay` are documented here.

## Unreleased

Assist in the panel: help requests and a live view of the visitor's page. Needs Session Replay 1.2 (`packstub/session-replay: ^1.2`) with `SESSION_REPLAY_ASSIST` on; the live view also needs [Holler for Laravel](https://github.com/packstub/holler) and [Filament Holler](https://github.com/packstub/filament-holler) (suggested: `composer require packstub/filament-holler`). See [Assist](docs/assist.md).

**Security:** on Filament 5.11 and later, the watch page of a recording the `viewSessionReplay` gate (or the policy) refused still opened for anyone who could see the Sessions list, showing whose recording it was and when; the player's data stayed behind the gate. The page asks the gate again on every Filament 5.

- **Help requests** under the Sessions resource: who is waiting, their message and page, with Claim, Open live view, Withdraw and End; the number waiting is the navigation badge
- A toast for each new request, with Claim, and a note in the bell when the panel has database notifications, without the person's name or message (`->assistNotifications(false)` turns them off)
- A **Live now** tab on the recordings list, "last seen" on live recordings, and **Offer help** on a live recording and its watch page
- The **live view**: the visitor's page mirrored live on a panel page, with remote control, drawing, audio, video and screen share as the panel's own buttons, beside the Filament Holler widget
- **Show my pointer** (on by default): the visitor sees where you point while you watch, without remote control
- **Put on hold** and **Resume** on the live view, to ring a colleague from the Holler widget, say; the visitor's page says you will be right back
- Leaving the live view leaves the call and keeps the request: Open live view picks it up again
- The live view sends Session Replay's mirror policy, so the visitor's page cannot make your browser load another site (your app's own policy still applies beside it); add hosts the panel itself needs on that page (avatars, say) to `assist.mirror_sources`
- `->assist(false)` keeps a panel to recordings; the Assist icons have aliases (`SessionReplayIconAlias`)
- `composer.json`'s homepage and docs link point at packstub.dev

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
