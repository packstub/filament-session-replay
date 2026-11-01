# Assist

A visitor asks for help from the page they are on, and someone from support takes the request in the panel. Or support sees a live recording and offers help. With [Calls for Laravel](https://github.com/packstub/calls) installed, the two meet on a **live view**: the visitor's page mirrored as it happens, with remote control, drawing, audio, video and screen share, each asked for. Without it, help requests are a queue your team answers through its own channels.

The requests, the routes, the visitor's side and the rules all live in the core, [Session Replay for Laravel](https://github.com/packstub/session-replay/blob/main/docs/assist.md) (Assist needs version 1.1 or later, which this plugin requires): read its Assist guide for what the visitor sees, the timeouts, the events, privacy and configuration. This page covers the panel.

## Turning it on

```env
SESSION_REPLAY_ASSIST=true
```

Run `php artisan migrate` for the core's `replay_assist_requests` table. Assist is on in every panel where the Sessions resource is; `->assist(false)` keeps a panel to recordings:

```php
SessionReplayPlugin::make()->assist(false);
```

For the live view, add [Calls for Laravel](https://github.com/packstub/calls) (`packstub/calls`) and [Filament Calls](https://github.com/packstub/filament-calls) (`packstub/filament-calls`). Both are suggested, not required, and released on their own schedule, so follow their READMEs to install them. Then register Filament Calls on the same panel. The live view then talks over the panel's own Calls routes, as the panel's user. Without Filament Calls on the panel, register `Calls::routes()` in `routes/web.php` as the core's guide says, and make `packstub-calls.guard` the panel's guard.

When the panel signs people in with a guard of its own (`->authGuard('admin')`), set `session-replay.viewer.guard` to it, as for the player: the live view asks the core's agent routes for the request's status, and those run the viewer's middleware and gate.

## Who may help

Whoever the `viewSessionReplay` gate (or your `ReplaySession` policy) lets watch a recording may help its person, unless you narrow it with the core's rule:

```php
use Packstub\SessionReplay\Facades\SessionReplay;

SessionReplay::canAssistUsing(fn ($agent, $session, $request): bool => $agent->hasRole('support'));
```

Every list, badge, notification and action in the panel reads the core's queue for the signed-in user, so a request the rule refuses is never listed, counted, announced or claimable; each action asks the core again when it runs. Nobody is offered their own visit. In a panel with tenancy, the requests are the current workspace's, like the recordings (`scopeToTenant(false)` lists every workspace's).

## Help requests

**Help requests** sits under **Session replays** in the navigation, with the number of visitors waiting as its badge. The table lists the visitors who wait and your own requests (claimed, offered, live):

- **Status**: Waiting, Offered (your offer the visitor has not answered), Claimed or Live, and who holds it.
- **Person**, named like the recordings list names people (`userLabelUsing()` included).
- **Message**, the visitor's own words, shown as text.
- **Page**, the URL with the core's `privacy.redact_query` applied.
- **Waiting**, how long since they asked, and **Still here**, whether their page is still polling (the tooltip says when it was last heard from).

The table refreshes every five seconds. Its actions:

| Action | When | Does |
| --- | --- | --- |
| **Claim** | a visitor's request nobody took | takes it (two agents cannot both get it) and opens the live view; without Calls it stays a queue entry your team answers its own way |
| **Open live view** | your request, with Calls | back to the live view |
| **Withdraw** | your claim or offer | a claim goes back to the list for someone else, an offer ends |
| **End** | your claimed or live request | ends it, and the call, for both of you |

### Notifications

While someone waits, everyone who may take the request gets a toast on whatever panel page they are on: who asked, their message, **Claim** and **View requests**. Each request is announced once per browser session (again if it comes back to the list). With database notifications on in the panel (`->databaseNotifications()`), it also lands in the bell. A burst shows three toasts at most, the last saying how many more wait. `->assistNotifications(false)` turns them off; the badge stays.

The notifier asks the queue every ten seconds while the tab is visible (Livewire slows it down in a hidden tab).

## Offer help

The recordings list has a **Live now** tab: recordings with an upload or a heartbeat in the last two minutes, each saying when its page was last heard from ("last seen 40 s ago"). A live recording of someone else, with Calls installed, has **Offer help** in its row and on its watch page. The visitor sees the offer on their page's next upload or heartbeat and may accept it or say not now; you wait on the live view meanwhile. A recording with a request going shows **Open live view** to the agent who holds it, and nothing to anyone else.

## The live view

The live view is the core's stage (`stage.js` and `stage.css` from its script route: rrweb's replayer in live mode and the calls engine) on a page of the panel, with its tools as the panel's own buttons:

- A status badge and line: waiting for the visitor, connecting, live, live with the visitor's tab hidden, reconnecting, over and why.
- The visitor's page, replayed as it happens in a sandboxed frame without scripts, masked like the recording.
- **Page / Ask to see their screen**: the view switch; their screen shows as video once they share it.
- **Request control / Draw**: remote control (pointer, drags, wheel and arrow keys; a click on a field opens a box to type into it) once the visitor allows it, and strokes that fade on their page.
- **Start audio**, **Start video** (while Calls allows video), **Share my screen**, then Mute and End audio.
- **Withdraw** and **End assist**.
- A disabled tool says why under it; what the visitor answered, refused or took back is listed under **What happened**.

The live view runs its own Calls client (calls of kind `assist`), so the Filament Calls widget on the same page, which only looks at calls of kind `call`, never takes the assist call, and keeps working for regular calls. An agent helps one visitor at a time: starting a regular call from the widget ends the assist, and the live view says so.

### A page of its own

The live view is a page of its own, always loaded in full: Claim, Offer help and Open live view go to it with a page load, never with `wire:navigate`. It is **not** kept across navigation. Leaving it (a link in the sidebar, the back button, closing the tab) leaves the call, and the request stays yours:

- Back through **Open live view** within Calls' `reconnect_timeout` (15 s): the same call, with a fresh picture of their page.
- Away longer: Calls lets go of your side, but the request waits for you. The visitor's page says you stepped away and are reconnecting, and when you open the live view again a new call starts on its own, live again.
- Not back within `assist.claim_timeout_seconds` (30) after that: the request goes back to the list, waiting, for anyone to claim.

To keep working in the panel during an assist, open the panel in another tab.

Why not `@persist`: Livewire moves a persisted element into the next page, and a frame moved in the page reloads its document, which would blank the mirror; the replayer, the call and the overlay also need the room the page gives them. A page of its own keeps one simple rule: the live view is where the stage is.

### Icons

Each Assist icon has an alias an app can point elsewhere:

```php
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Packstub\SessionReplay\Filament\View\SessionReplayIconAlias;

FilamentIcon::register([
    SessionReplayIconAlias::ASSIST_NAVIGATION => Heroicon::OutlinedChatBubbleLeftRight,
    SessionReplayIconAlias::ASSIST_CLAIM => Heroicon::OutlinedHandThumbUp,
]);
```

The aliases: `ASSIST_NAVIGATION`, `ASSIST_CLAIM`, `ASSIST_OFFER`, `ASSIST_OPEN_STAGE`, `ASSIST_WITHDRAW`, `ASSIST_END`, `ASSIST_REQUESTED` (the notification), and the live view's `STAGE_MIRROR`, `STAGE_SCREEN`, `STAGE_CONTROL`, `STAGE_DRAW`, `STAGE_AUDIO`, `STAGE_VIDEO`, `STAGE_SHARE`, `STAGE_MUTE`, `STAGE_END_AUDIO`.

## Plugin options

| Option | Default | |
| --- | --- | --- |
| `->assist(bool\|Closure)` | `true` | Help requests, Offer help and the live view in this panel, while the core has Assist on. |
| `->assistNotifications(bool\|Closure)` | `true` | The toast (and the bell) about each new request. |

The strings come in the five languages of the plugin; the live view's are the core's (`session-replay::viewer.stage.*`), so it says the same in the panel and in the built-in viewer.
