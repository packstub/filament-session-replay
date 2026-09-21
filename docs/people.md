# People

Most questions about a recording start with a person: "what did she just do?". These pieces put recordings next to your users.

## The model

Add the core's trait to the model people sign in with:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Packstub\SessionReplay\Concerns\HasSessionReplays;

class User extends Authenticatable
{
    use HasSessionReplays;
}
```

It adds the `sessionReplays()` relation (`morphMany` to `ReplaySession`), which the relation manager uses:

```php
$user->sessionReplays()->latest('started_at')->first()?->url();
```

## A person's recordings on their page

Add `ReplaysRelationManager` to your user resource:

```php
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\RelationManagers\ReplaysRelationManager;

public static function getRelations(): array
{
    return [
        ReplaysRelationManager::class,
    ];
}
```

The tab is titled "Session replays" and shows the same table as the Sessions resource, columns and filters included, limited to that person. It is read-only: one row action, **Watch**, which opens the recording in the Sessions resource.

The tab is only shown to people who may see the list (`ReplaySessionResource::canViewAny()`), and Watch only on recordings they may open, so the rules in [Watching](watching.md#who-may-do-what) apply here too.

## Watch last session

`WatchLastSessionAction` opens a person's most recent recording. On a table row:

```php
use Packstub\SessionReplay\Filament\Actions\WatchLastSessionAction;

public static function table(Table $table): Table
{
    return $table
        ->columns([/* ... */])
        ->recordActions([
            WatchLastSessionAction::make(),
        ]);
}
```

Or in the header of a person's edit or view page:

```php
protected function getHeaderActions(): array
{
    return [
        WatchLastSessionAction::make(),
    ];
}
```

The action is named `watchLastSession`, labelled "Watch last session", and links to the watch page. It is hidden when the person has no recording or when the viewer may not watch the latest one. It is a regular Filament action, so `->label()`, `->icon()`, `->color()` and `->openUrlInNewTab()` work as usual.

`WatchLastSessionAction::lastSession($user)` returns that recording (`?ReplaySession`) if you need it elsewhere.

## The stats widget

```php
$panel->plugin(SessionReplayPlugin::make()->widget());
```

`ReplayStatsWidget` shows the last seven days in three numbers, each linking to the list with the matching filter:

| Stat | Opens |
| --- | --- |
| Recordings, last 7 days | The list |
| With errors | The list filtered to recordings with errors |
| Slow pages (poor vitals) | The list filtered to poor vitals |

In a panel with tenancy the numbers are the current workspace's (see [Tenancy](tenancy.md)). The widget is hidden from people who may not see the list. It does not poll.

To place it yourself instead of registering it on the whole panel, leave `->widget()` off and add `Packstub\SessionReplay\Filament\Widgets\ReplayStatsWidget::class` to a dashboard's or a page's widgets.

## Naming people

In the list and on the watch page a person is named by the first of these that has a value:

1. What your `userLabelUsing()` closure returns.
2. The attribute in the core's `viewer.user_label` config key (`email` by default).
3. `name`, then `email`.
4. `#` and the person's key, for example `#42`, when the user no longer exists.

A recording without a person is "Guest".

```php
use Packstub\SessionReplay\Models\ReplaySession;

SessionReplayPlugin::make()
    ->userLabelUsing(fn (ReplaySession $session): ?string => $session->user?->full_name);
```

Return null to fall through to the defaults.

## Finding people

The table's search box looks for what you type in the `name` and `email` columns of your people's tables, and matches a person's key exactly. It works per model: recordings of a `User` and of an `Admin` are both found, each in its own table, and a column a table does not have is skipped. There is no join, so it also works when the recordings index lives on another connection than your users.

```php
SessionReplayPlugin::make()
    ->searchPeopleBy(['first_name', 'last_name', 'email']);
```

`searchPeopleBy([])` leaves the search to the key alone.

These pieces link to the Sessions resource, so use them in a panel where the plugin is registered with the resource on (the default).
