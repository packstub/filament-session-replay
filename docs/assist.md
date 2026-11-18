# Assist

A visitor asks for help from the page they are on, and someone from support takes the request in the panel. Or support sees a live recording and offers help. With [Holler for Laravel](https://github.com/packstub/holler) installed, the two meet on a **live view**: the visitor's page mirrored as it happens, with remote control, drawing, audio, video and screen share, each asked for. Without it, help requests are a queue your team answers through its own channels.

The requests, the routes, the visitor's side and the rules all live in the core, [Session Replay for Laravel](https://github.com/packstub/session-replay/blob/main/docs/assist.md) (Assist needs version 1.2 or later, which this plugin requires): read its Assist guide for what the visitor sees, the timeouts, the events, privacy and configuration. This page covers the panel.

## Turning it on

```env
SESSION_REPLAY_ASSIST=true
```

Run `php artisan migrate` for the core's `replay_assist_requests` table. Assist is on in every panel where the Sessions resource is; `->assist(false)` keeps a panel to recordings:

```php
SessionReplayPlugin::make()->assist(false);
```

For the live view, add [Holler for Laravel](https://github.com/packstub/holler) (`packstub/holler`) and [Filament Holler](https://github.com/packstub/filament-holler) (`packstub/filament-holler`). Both are suggested, not required, and released on their own schedule, so follow their READMEs to install them. Then register Filament Holler on the same panel. The live view then talks over the panel's own Holler routes, as the panel's user. Without Filament Holler on the panel, register `Holler::routes()` in `routes/web.php` as the core's guide says, and make `holler.guard` the panel's guard.

When the panel signs people in with a guard of its own (`->authGuard('admin')`), set `session-replay.viewer.guard` to it, as for the player: the live view asks the core's agent routes for the request's status, and those run the viewer's middleware and gate.

## Who may help

Whoever the `viewSessionReplay` gate (or your `ReplaySession` policy) lets watch a recording may help its person, unless you narrow it with the core's rule:

```php
use Packstub\SessionReplay\Facades\SessionReplay;

SessionReplay::canAssistUsing(fn ($agent, $session, $request): bool => $agent->hasRole('support'));
```

Every list, badge, notification and action in the panel reads the core's queue for the signed-in user, so a request the rule refuses is never listed, counted, announced or claimable; each action asks the core again when it runs. Nobody is offered their own visit. In a panel with tenancy, the requests are the current workspace's, like the recordings (`scopeToTenant(false)` lists every workspace's): a request of another workspace cannot be claimed or opened from it either, by any route into the panel.

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
| **Claim** | a visitor's request nobody took | takes it (two agents cannot both get it) and opens the live view; without Holler it stays a queue entry your team answers its own way |
| **Open live view** | your request, with Holler | back to the live view |
| **Withdraw** | your claim or offer | a claim goes back to the list for someone else, an offer ends |
| **End** | your claimed or live request | ends it, and the call, for both of you |

### Notifications

While someone waits, everyone who may take the request gets a toast on whatever panel page they are on: who asked, their message, **Claim** and **View requests**. Each request is announced once per browser session (again if it comes back to the list). With database notifications on in the panel (`->databaseNotifications()`), it also lands in the bell. A burst shows three toasts at most, the last saying how many more wait. `->assistNotifications(false)` turns them off; the badge stays.

The notifier asks the queue every ten seconds while the tab is visible (Livewire slows it down in a hidden tab).

## Offer help

The recordings list has a **Live now** tab: recordings with an upload or a heartbeat in the last two minutes, each saying when its page was last heard from ("last seen 40 s ago"). A live recording of someone else, with Holler installed, has **Offer help** in its row and on its watch page. The visitor sees the offer on their page's next upload or heartbeat and may accept it or say not now; you wait on the live view meanwhile. A recording with a request going shows **Open live view** to the agent who holds it, and nothing to anyone else.

## The live view

The live view is the core's stage (`stage.js` and `stage.css` from its script route: rrweb's replayer in live mode and the calls engine) on a page of the panel, with its tools as the panel's own buttons:

- A status badge and line: waiting for the visitor, connecting, live, live with the visitor's tab hidden, on hold, reconnecting, over and why.
- The visitor's page, replayed as it happens in a sandboxed frame without scripts, masked like the recording.
- **Page / Ask to see their screen**: the view switch; their screen shows as video once they share it.
- **Request control / Draw**: remote control (pointer, drags, wheel and arrow keys; a click on a field opens a box to type into it) once the visitor allows it, and strokes that fade on their page.
- **Show my pointer** (on by default): while you watch, the visitor sees where you point, as your named cursor on their page. Nothing is asked, since it changes nothing there; a click still needs control. Turn it off to look around unseen.
- **Start audio**, **Start video** (while Holler allows video), **Share my screen**, then Mute and End audio.
- **Put on hold / Resume**: step out of the call without ending the assist (see below).
- **Withdraw** and **End assist**.
- A disabled tool says why under it; what the visitor answered, refused or took back is listed under **What happened**.

The live view runs its own Holler client (calls of kind `assist`), so the Filament Holler widget on the same page, which only looks at calls of kind `call`, never takes the assist call, and keeps working for regular calls. An agent is in one call at a time, whatever its kind: starting a regular call from the widget ends the assist, and the live view says so, unless the assist is on hold; the other way round, claiming a request leaves a widget call the agent is on once the visitor's page picks the claim up, and a colleague who rings an agent during an assist hears they are busy.

### On hold

Holler lets you put a call on hold, and the live view goes along with it:

- **Put on hold** on the live view steps out of the assist without ending it. The badge says On hold, the visitor's page says you put the session on hold and will be right back, and nothing is mirrored or heard meanwhile. Now a call from the Holler widget (to ask a colleague, say) leaves the assist waiting. **Resume** brings you back, from a fresh picture of their page; remote control is asked again, audio allowed before comes back on its own.
- A call you put on hold in the widget before claiming waits through the assist. Resuming it during the assist is Holler's swap: the assist goes on hold in its place, and Resume on the live view swaps back. Holler keeps one call on hold per person, so Put on hold is refused while a widget call is on hold.
- The widget never lists the assist, on hold or not; only the live view shows it.
- A hold lasts while the live view is open. Leave the page with the assist on hold for longer than Holler's `hold_timeout` (120 seconds, more than the 15 a dropped call gets, since you stepped away on purpose) and you have left the live view, as in [A page of its own](#a-page-of-its-own): the request waits for you, then goes back to the list.
- You are live in one assist at a time. Claim and Offer help are refused while you are in another one ("You are in another assist. Put it on hold or end it first."), and a request of yours that comes back while you are live elsewhere waits, with the reason in its status line, until the other is on hold or over.

The visitor's page is outside the panel, so it takes its ICE servers from Holler's config (`HOLLER_TURN_*`), not from `FilamentHollerPlugin::make()->iceServers()`. When the panel uses a TURN server, put it in the config too, or visitors behind a strict NAT cannot connect.

### A page of its own

The live view is a page of its own, always loaded in full: Claim, Offer help and Open live view go to it with a page load, never with `wire:navigate`. It is **not** kept across navigation. Leaving it (a link in the sidebar, the back button, closing the tab) leaves the call, and the request stays yours:

- Back through **Open live view** within Holler's `reconnect_timeout` (15 s): the same call, with a fresh picture of their page.
- Away longer: Holler lets go of your side, but the request waits for you. The visitor's page says you stepped away and are reconnecting, and when you open the live view again a new call starts on its own, live again.
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

The aliases: `ASSIST_NAVIGATION`, `ASSIST_CLAIM`, `ASSIST_OFFER`, `ASSIST_OPEN_STAGE`, `ASSIST_WITHDRAW`, `ASSIST_END`, `ASSIST_REQUESTED` (the notification), and the live view's `STAGE_MIRROR`, `STAGE_SCREEN`, `STAGE_CONTROL`, `STAGE_DRAW`, `STAGE_POINTER`, `STAGE_HOLD`, `STAGE_AUDIO`, `STAGE_VIDEO`, `STAGE_SHARE`, `STAGE_MUTE`, `STAGE_END_AUDIO`.

## Plugin options

| Option | Default | |
| --- | --- | --- |
| `->assist(bool\|Closure)` | `true` | Help requests, Offer help and the live view in this panel, while the core has Assist on. |
| `->assistNotifications(bool\|Closure)` | `true` | The toast (and the bell) about each new request. |

The strings come in the five languages of the plugin; the live view's are the core's (`session-replay::viewer.stage.*`), so it says the same in the panel and in the built-in viewer.
