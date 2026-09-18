# Watching

The plugin adds a **Session replays** resource (`Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource`) to the panel: a list and a watch page. Recordings are never created or edited by hand, so there are no create and edit pages.

## The list

Newest first (`started_at`, descending).

| Column | What it shows |
| --- | --- |
| **Person** | The person's label (see [People](people.md#naming-people)), "Guest" for a recording without a person, and "Impersonated by #id" underneath when someone was impersonating. Searching the table matches the person's key exactly: type `42` to find user 42. |
| **Workspace** | The tenant's `name`, or `#id`. Only visible when the panel has no current tenant, which is the operator's view. Toggleable. |
| **Started** | Relative time with the full date in the tooltip; a green badge while the recording is still receiving events. Sortable. |
| **Length** | Wall-clock length, for example `4m 12s`. |
| **Pages** | Page views, `wire:navigate` swaps included. Sortable. |
| **Errors** | Uncaught errors plus failed requests; red when above zero. Sortable. |
| **Rage clicks** | Hidden by default, toggleable, sortable. |
| **Vitals** | Good, Needs improvement or Poor: the worst rating among the measured LCP, INP and CLS, with the values in the tooltip. |
| **Device** | Desktop, Tablet or Mobile. Toggleable. |
| **First page** | The entry URL without the scheme, full URL in the tooltip. Toggleable. |
| **Pinned** | A bookmark icon on pinned recordings. Toggleable. |
| **Size** | As stored (gzip). Hidden by default, sortable. |

### Filters

| Filter | Name | What it does |
| --- | --- | --- |
| **Errors** | `errors` | Ternary: all, "With errors" (`error_count > 0`), "Without errors". |
| **Slow pages (poor vitals)** | `poor_vitals` | Toggle. LCP above 4000 ms, INP above 500 ms or CLS above 0.25. |
| **Rage clicks** | `rage_clicks` | Toggle. At least one rage click. |
| **Impersonated** | `impersonated` | Toggle. Recordings made while someone was impersonating. |
| **Pinned** | `pinned` | Toggle. |
| **Device** | `device` | Select: Desktop, Tablet, Mobile. |
| **First page contains** | `url` | Text. Matches part of the entry URL, for example `invoices`. |
| **From / Until** | `started` | Two dates on `started_at`. |

The filter names are what you use in a URL or a test, for example `->filterTable('poor_vitals')`.

### Row actions

**Watch** opens the watch page. **Pin** / **Unpin** keeps a recording out of pruning. **Delete** removes the recording, its chunks, its markers and its files on the disk. Delete is also available as a bulk action.

## The watch page

The title is the person, the subheading the start time.

- **The facts**: length (with a badge while live), pages, errors, vitals (`LCP 1840 ms · INP 96 ms · CLS 0.020`), device and viewport, size, who impersonated (when someone did), the first page (copyable), and a note when the recording reached `ingest.max_session_mb` and was cut short.
- **The player**: the core's `<x-session-replay::player>` inside a `wire:ignore` wrapper, so a Livewire re-render of the page leaves it alone. Play, speed (1x to 8x), skip inactivity and fullscreen. Markers are drawn on the timeline in their colour.
- **The marker list** next to the player: one chip per kind of marker with its count (Page, Error, Request, Console, Rage click, Vital, Custom) to show or hide that kind, and the markers themselves with their time. A click seeks to a second before the moment. Vitals start hidden.

Adding `?t=83` to the URL opens the replay at 1:23, which is handy in a ticket. The marker kinds, their colours and the player's events are described in the core's [Watching replays](https://packstub.dev/docs/session-replay/watching#the-player).

### Header actions

| Action | What it does |
| --- | --- |
| **Pin** / **Unpin** | A pinned recording is never pruned. |
| **Export** | Downloads `session-replay-{id}.json`: `session` (who, where, when, properties), `markers`, `chunks` (the rrweb events as stored, one array per chunk) and `assets` (the stylesheets the events point at, keyed by their SHA-256). The file holds everything needed to replay the recording elsewhere; stylesheet placeholders in the events look like `/*sr-asset:<sha256>*/`. |
| **Delete** | Removes the recording and its files. |

## Who may do what

The resource asks the same question as the core's viewer and its data routes: the `viewSessionReplay` gate, for the person signed in to the panel (`Filament::auth()->user()`).

| Ability | Without a policy |
| --- | --- |
| See the resource and the list | `viewSessionReplay` without a recording |
| Open a watch page | `viewSessionReplay` with the recording |
| Pin and unpin | Whoever may open that recording |
| Delete, bulk delete | The `deleteSessionReplay` gate when your app defines it (with the recording for one, without for bulk); otherwise whoever may watch |
| Create, edit | Never |

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

// Support watches customers, never colleagues.
Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null): bool
    => $user->is_staff && ! $session?->user?->is_staff);

// Only leads delete.
Gate::define('deleteSessionReplay', fn ($user, ?ReplaySession $session = null): bool
    => $user->is_lead);
```

Until `viewSessionReplay` is defined, only the local environment is let in, so the resource is hidden in production until you decide.

The gate is asked for the list as a whole. A rule that depends on the recording (like the one above) blocks the watch page and the data, while the row still appears in the list. To narrow the list too, scope the panel to a workspace ([Tenancy](tenancy.md)) or extend the resource and override `getEloquentQuery()`.

### Policies and Filament Shield

When a policy is registered for `Packstub\SessionReplay\Models\ReplaySession`, the resource follows Filament's usual policy checks instead of the gate: `viewAny`, `view`, `delete` and `deleteAny`. That is how [Filament Shield](https://github.com/bezhanSalleh/filament-shield) permissions plug in: generate the policy for the resource and assign the permissions to roles.

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

Gate::policy(ReplaySession::class, \App\Policies\ReplaySessionPolicy::class);
```

One thing to keep in step: the player loads the manifest, the chunks and the stylesheets from the core's routes, and those always ask the `viewSessionReplay` gate. With a policy in place, define the gate as well and let it delegate, so the page and the data agree:

```php
Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    return $session === null
        ? $user->can('viewAny', ReplaySession::class)
        : $user->can('view', $session);
});
```

Without the gate, a person the policy allows would open the watch page and see "You are not allowed to watch this recording" in the player.
