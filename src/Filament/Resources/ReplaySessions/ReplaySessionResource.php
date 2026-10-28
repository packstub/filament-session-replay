<?php

namespace Packstub\SessionReplay\Filament\Resources\ReplaySessions;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Assist\AssistActions;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\AssistRequests;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\AssistStage;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ListReplaySessions;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ViewReplaySession;
use Packstub\SessionReplay\Filament\View\SessionReplayIconAlias;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;
use UnitEnum;

use function Filament\Support\original_request;

/**
 * The recordings. Who may open them is the app's viewSessionReplay gate, the
 * same one the core viewer and every data route ask, unless the app
 * registered a policy for ReplaySession (Shield does), which then decides.
 */
class ReplaySessionResource extends Resource
{
    protected static ?string $model = ReplaySession::class;

    // Recordings carry their workspace themselves (tenant_type / tenant_id), not through an ownership relationship.
    protected static bool $isScopedToTenant = false;

    // The search box looks for the search among the people of this list first (SEARCH_PEOPLE of them at most, read
    // SEARCH_KEYS at a time). A list with more people than that reads the matching people SEARCH_BATCH at a time
    // instead, until SEARCH_LIMIT of them have recordings in the list or SEARCH_MAX_BATCHES batches were read.
    protected const SEARCH_LIMIT = 200;

    protected const SEARCH_PEOPLE = 5000;

    protected const SEARCH_KEYS = 500;

    protected const SEARCH_BATCH = 500;

    protected const SEARCH_MAX_BATCHES = 20;

    public static function getSlug(?Panel $panel = null): string
    {
        // A URL for another panel (a replay link built outside a panel request) takes that panel's slug.
        if ($panel !== null && $panel->hasPlugin(SessionReplayPlugin::get()->getId())) {
            /** @var SessionReplayPlugin $plugin */
            $plugin = $panel->getPlugin(SessionReplayPlugin::get()->getId());

            return $plugin->getSlug();
        }

        return SessionReplayPlugin::get()->getSlug();
    }

    public static function getNavigationLabel(): string
    {
        return SessionReplayPlugin::get()->getNavigationLabel() ?? __('Session replays');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return SessionReplayPlugin::get()->getNavigationIcon() ?? Heroicon::OutlinedPlayCircle;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return SessionReplayPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return SessionReplayPlugin::get()->getNavigationSort();
    }

    /**
     * The recordings, and Help requests below them while Assist is on, with the number of visitors waiting as the
     * badge. The requests are the core's queue for this agent, so the badge counts only what they may take.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $items = parent::getNavigationItems();

        if (! PanelAssist::mayAssist()) {
            return $items;
        }

        $assistRoutes = [static::getRouteBaseName().'.requests', static::getRouteBaseName().'.stage'];

        foreach ($items as $item) {
            $item->isActiveWhen(fn (): bool => original_request()->routeIs(static::getRouteBaseName().'.*') && ! original_request()->routeIs(...$assistRoutes));
        }

        $waiting = PanelAssist::waitingCount();

        $items[] = NavigationItem::make(__('session-replay::viewer.assist.nav'))
            ->group(static::getNavigationGroup())
            ->parentItem(static::getNavigationParentItem())
            ->icon(AssistActions::icon(SessionReplayIconAlias::ASSIST_NAVIGATION, Heroicon::OutlinedLifebuoy))
            ->key(static::class.'.assist')
            ->isActiveWhen(fn (): bool => original_request()->routeIs(...$assistRoutes))
            ->sort(static::getNavigationSort())
            ->badge($waiting > 0 ? (string) $waiting : null, 'warning')
            ->badgeTooltip(__('Visitors waiting for help'))
            ->url(static::getUrl('requests'));

        return $items;
    }

    public static function getModelLabel(): string
    {
        return __('session replay');
    }

    public static function getPluralModelLabel(): string
    {
        return __('session replays');
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record instanceof ReplaySession ? SessionReplayPlugin::get()->userLabel($record) : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scope(parent::getEloquentQuery()->with(['user', 'impersonator']));
    }

    /**
     * What every list of recordings in the panel starts from: the current
     * workspace, and whatever the app told SessionReplay::visibleUsing().
     *
     * @param  Builder<ReplaySession>  $query
     * @return Builder<ReplaySession>
     */
    public static function scope(Builder $query): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Model && SessionReplayPlugin::get()->isScopedToTenant()) {
            $query->forTenant($tenant);
        }

        $viewer = Filament::auth()->user();

        // Nested, so an orWhere in the app's rule stays inside it instead of undoing the workspace (or the person) above.
        return $query->where(fn (Builder $query) => SessionReplay::visibleTo($query, $viewer));
    }

    /**
     * Every workspace's recordings in one list (no current tenant, or scopeToTenant(false)): the rows need their workspace named.
     *
     * @internal
     */
    public static function listsEveryWorkspace(): bool
    {
        return Filament::getTenant() === null || ! SessionReplayPlugin::get()->isScopedToTenant();
    }

    /**
     * How the Person filter names its person: the way the list names them
     * (userLabelUsing() included), from a recording of theirs in it, else
     * from their model when the type names one, else by key.
     *
     * @internal
     *
     * @param  array{type?: ?string, id?: ?string}  $data
     */
    public static function personLabel(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $type = (string) ($data['type'] ?? '');

        $session = static::scope(ReplaySession::query())
            ->where('user_id', $id)
            ->when($type !== '', fn (Builder $query) => $query->where('user_type', $type))
            ->with('user')
            ->first();

        if ($session !== null) {
            return SessionReplayPlugin::get()->userLabel($session);
        }

        $class = $type === '' ? null : (Relation::getMorphedModel($type) ?? $type);
        $person = is_string($class) && is_subclass_of($class, Model::class) ? $class::query()->find($id) : null;

        // An unsaved recording of the person, so userLabelUsing() names them here as it does in the list.
        $session = (new ReplaySession)->forceFill(['user_type' => $type === '' ? null : $type, 'user_id' => $id])->setRelation('user', $person);

        return SessionReplayPlugin::get()->userLabel($session);
    }

    /**
     * The URL of the list, filtered to one person's recordings (the current
     * panel and workspace unless given). The list page reads its filters from
     * the `filters` query parameter.
     */
    public static function getUrlForPerson(Model $person, ?string $panel = null, ?Model $tenant = null): string
    {
        return static::getUrl('index', [
            'filters' => ['person' => ['type' => $person->getMorphClass(), 'id' => (string) $person->getKey()]],
        ], panel: $panel, tenant: $tenant);
    }

    /**
     * The person's key, or what the search box holds found in the recorded
     * people's own tables. No join: the index may live on another connection
     * than the people, and a recording names its person by morph type and key.
     *
     * @internal The Person column's search.
     *
     * @param  Builder<ReplaySession>  $query
     */
    public static function searchPeople(Builder $query, string $search): void
    {
        $columns = SessionReplayPlugin::get()->getSearchPeopleBy();
        // The people types of the rows this list can show, not of every workspace's.
        $scoped = $columns === [] ? null : static::scope(ReplaySession::query());
        $types = $scoped === null ? [] : (clone $scoped)->whereNotNull('user_type')->distinct()->pluck('user_type')->all();

        $query->where(function (Builder $query) use ($search, $columns, $types, $scoped): void {
            $query->where('user_id', $search);

            foreach ($types as $type) {
                $class = Relation::getMorphedModel($type) ?? $type;

                if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                    continue;
                }

                $person = new $class;
                $known = array_values(array_filter($columns, fn (string $column): bool => $person->getConnection()->getSchemaBuilder()->hasColumn($person->getTable(), $column)));

                if ($known === []) {
                    continue;
                }

                $keys = static::searchAmongListedPeople($person, $known, $search, $scoped, $type);

                if ($keys !== null) {
                    if ($keys !== []) {
                        $query->orWhere(fn (Builder $query) => $query->where('user_type', $type)->whereIn('user_id', $keys));
                    }

                    continue;
                }

                // More people in the list than SEARCH_PEOPLE: the people who match, a batch at a time, kept when they
                // have recordings in this list (a limit on the people alone would fill up with other workspaces'
                // namesakes). Bounded, so a one-letter search stays quick.
                $keys = [];
                $batches = 0;

                $person->newQuery()
                    ->whereAny($known, 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->select($person->getKeyName())
                    ->chunkById(static::SEARCH_BATCH, function (Collection $people) use (&$keys, &$batches, $scoped, $type): bool {
                        $keys = [...$keys, ...(clone $scoped)
                            ->where('user_type', $type)
                            ->whereIn('user_id', $people->map(fn (Model $person): string => (string) $person->getKey())->all())
                            ->distinct()
                            ->pluck('user_id')
                            ->all()];

                        return count($keys) < static::SEARCH_LIMIT && ++$batches < static::SEARCH_MAX_BATCHES;
                    }, $person->getKeyName());

                if ($keys !== []) {
                    $query->orWhere(fn (Builder $query) => $query->where('user_type', $type)->whereIn('user_id', $keys));
                }
            }
        });
    }

    /**
     * The keys of the people with recordings in this list whose columns match
     * the search: the list's people are usually far fewer than the people
     * table, so one query over the list and one over the people (a few, for
     * a long list) find them. Null when the list has more than SEARCH_PEOPLE
     * people of this type, which is the caller's cue to search the people
     * table instead.
     *
     * @param  array<int, string>  $columns
     * @param  Builder<ReplaySession>  $scoped
     * @return array<int, string>|null
     */
    protected static function searchAmongListedPeople(Model $person, array $columns, string $search, Builder $scoped, string $type): ?array
    {
        $listed = (clone $scoped)
            ->where('user_type', $type)
            ->whereNotNull('user_id')
            ->distinct()
            ->limit(static::SEARCH_PEOPLE + 1)
            ->pluck('user_id')
            ->all();

        if (count($listed) > static::SEARCH_PEOPLE) {
            return null;
        }

        $keys = [];

        foreach (array_chunk($listed, static::SEARCH_KEYS) as $chunk) {
            $keys = [...$keys, ...$person->newQuery()
                ->whereKey($chunk)
                ->whereAny($columns, 'like', '%'.addcslashes($search, '%_\\').'%')
                ->limit(static::SEARCH_LIMIT - count($keys))
                ->pluck($person->getKeyName())
                ->map(fn ($key): string => (string) $key)
                ->all()];

            if (count($keys) >= static::SEARCH_LIMIT) {
                break;
            }
        }

        return $keys;
    }

    protected static function hasPolicy(): bool
    {
        return Gate::getPolicyFor(ReplaySession::class) !== null;
    }

    /**
     * What the core's data routes ask, so the list, the watch page and the player agree: the viewSessionReplay gate
     * when the app defined it, else the ReplaySession policy (viewAny, view), else the local environment only.
     */
    public static function canViewAny(): bool
    {
        return SessionReplay::check(user: Filament::auth()->user());
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ReplaySession && SessionReplay::check($record, Filament::auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** A policy decides when there is one; else the deleteSessionReplay gate when the app defined it; else whoever may watch. */
    public static function canDelete(Model $record): bool
    {
        if (static::hasPolicy()) {
            return parent::canDelete($record);
        }

        if (Gate::has('deleteSessionReplay')) {
            return Gate::forUser(Filament::auth()->user())->allows('deleteSessionReplay', [$record]);
        }

        return static::canView($record);
    }

    public static function canDeleteAny(): bool
    {
        if (static::hasPolicy()) {
            return parent::canDeleteAny();
        }

        return Gate::has('deleteSessionReplay') ? Gate::forUser(Filament::auth()->user())->allows('deleteSessionReplay') : static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            // The Workspace column names every row's tenant: one query per tenant type instead of one per row;
            // the Reported badge is one exists per row, not a query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withExists(['markers as reported' => fn (Builder $markers) => $markers->where('type', ReplayMarker::REPORT)])
                ->when(static::listsEveryWorkspace(), fn (Builder $query) => $query->with('tenant')))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('user_id')
                    ->label(__('Person'))
                    ->formatStateUsing(fn (ReplaySession $record): string => SessionReplayPlugin::get()->userLabel($record))
                    ->default('guest')
                    ->description(fn (ReplaySession $record): ?string => ($who = SessionReplayPlugin::get()->impersonatorLabel($record)) === null ? null : __('Impersonated by :who', ['who' => $who]))
                    ->searchable(query: fn (Builder $query, string $search) => static::searchPeople($query, $search)),
                TextColumn::make('tenant_id')
                    ->label(__('Workspace'))
                    ->formatStateUsing(fn (ReplaySession $record): string => (string) SessionReplayPlugin::get()->tenantLabel($record))
                    ->placeholder('·')
                    ->visible(fn (): bool => static::listsEveryWorkspace())
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->label(__('Started'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->badge(fn (ReplaySession $record): bool => $record->isLive())
                    ->color(fn (ReplaySession $record): ?string => $record->isLive() ? 'success' : null)
                    // Live is a window (the last two minutes), not a promise: say when the page was last heard from.
                    ->description(fn (ReplaySession $record): ?string => $record->isLive() && $record->lastSeenSeconds() !== null ? __('last seen :ago ago', ['ago' => AssistRequests::duration($record->lastSeenSeconds())]) : null),
                TextColumn::make('duration')
                    ->label(__('Length'))
                    ->state(fn (ReplaySession $record): string => $record->durationForHumans()),
                TextColumn::make('page_count')->label(__('Pages'))->numeric()->sortable(),
                TextColumn::make('error_count')
                    ->label(__('Errors'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('reported')
                    ->label(__('Reported'))
                    ->badge()
                    ->color('warning')
                    ->state(fn (ReplaySession $record): ?string => $record->getAttribute('reported') ? __('Reported') : null)
                    ->placeholder('·')
                    ->toggleable(),
                TextColumn::make('rage_click_count')
                    ->label(__('Rage clicks'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vitals')
                    ->label(__('Vitals'))
                    ->state(fn (ReplaySession $record): ?string => $record->vitalsRating())
                    ->formatStateUsing(fn (string $state): string => __(Str::of($state)->replace('-', ' ')->ucfirst()->toString()))
                    ->badge()
                    ->color(fn (string $state): string => ['good' => 'success', 'needs-improvement' => 'warning', 'poor' => 'danger'][$state] ?? 'gray')
                    ->tooltip(fn (ReplaySession $record): string => collect([
                        'LCP' => $record->lcp_ms === null ? null : $record->lcp_ms.' ms',
                        'INP' => $record->inp_ms === null ? null : $record->inp_ms.' ms',
                        'CLS' => $record->cls === null ? null : number_format($record->cls, 3),
                    ])->filter()->map(fn (string $value, string $name): string => "{$name} {$value}")->implode(' · '))
                    ->placeholder('·'),
                TextColumn::make('device')
                    ->label(__('Device'))
                    ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                    ->toggleable(),
                TextColumn::make('entry_url')
                    ->label(__('First page'))
                    ->formatStateUsing(fn (string $state): string => static::shortUrl($state))
                    ->limit(40)
                    ->tooltip(fn (ReplaySession $record): ?string => $record->entry_url)
                    ->color('gray')
                    ->toggleable(),
                IconColumn::make('pinned')
                    ->label(__('Pinned'))
                    // A bookmark on the pinned ones and nothing on the rest: the cross a boolean column puts in every other row reads as a fault.
                    ->state(fn (ReplaySession $record): ?string => $record->pinned ? 'pinned' : null)
                    ->icon(Heroicon::Bookmark)
                    ->color('primary')
                    ->toggleable(),
                TextColumn::make('bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // One person's recordings: the deep link getUrlForPerson() builds (Account Switcher's banner lands
                // on it), or a key typed by hand.
                Filter::make('person')
                    ->label(__('Person'))
                    ->schema([Hidden::make('type'), TextInput::make('id')->label(__('Person ID'))])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['id'] ?? null), fn (Builder $query) => $query
                        ->where('user_id', (string) $data['id'])
                        ->when(filled($data['type'] ?? null), fn (Builder $query) => $query->where('user_type', (string) $data['type']))))
                    ->indicateUsing(fn (array $data): ?string => filled($data['id'] ?? null) ? __('Person: :who', ['who' => static::personLabel($data)]) : null),
                TernaryFilter::make('errors')
                    ->label(__('Errors'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('With errors'))
                    ->falseLabel(__('Without errors'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('error_count', '>', 0),
                        false: fn (Builder $query) => $query->where('error_count', 0),
                    ),
                Filter::make('poor_vitals')->label(__('Slow pages (poor vitals)'))->toggle()->query(fn (Builder $query) => $query->poorVitals()),
                Filter::make('reported')->label(__('Reported a problem'))->toggle()->query(fn (Builder $query) => $query->reported()),
                Filter::make('rage_clicks')->label(__('Rage clicks'))->toggle()->query(fn (Builder $query) => $query->where('rage_click_count', '>', 0)),
                Filter::make('impersonated')->label(__('Impersonated'))->toggle()->query(fn (Builder $query) => $query->whereNotNull('impersonator_id')),
                Filter::make('pinned')->label(__('Pinned'))->toggle()->query(fn (Builder $query) => $query->where('pinned', true)),
                SelectFilter::make('device')->label(__('Device'))->options(['desktop' => __('Desktop'), 'tablet' => __('Tablet'), 'mobile' => __('Mobile')]),
                Filter::make('url')
                    ->schema([TextInput::make('contains')->label(__('First page contains'))])
                    ->query(fn (Builder $query, array $data) => $query->when($data['contains'] ?? null, fn (Builder $query, string $value) => $query->where('entry_url', 'like', '%'.addcslashes($value, '%_\\').'%')))
                    ->indicateUsing(fn (array $data): ?string => ($data['contains'] ?? null) ? __('First page contains ":value"', ['value' => $data['contains']]) : null),
                Filter::make('started')
                    ->schema([DatePicker::make('from')->label(__('From')), DatePicker::make('until')->label(__('Until'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('started_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('started_at', '<=', $date))),
            ])
            ->recordActions([
                AssistActions::offer(),
                AssistActions::openStageForSession(),
                ViewAction::make()->label(__('Watch'))->icon(Heroicon::OutlinedPlay),
                static::pinAction(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                // Each selected recording is asked again: being allowed to delete some is not being allowed to delete these.
                BulkActionGroup::make([DeleteBulkAction::make()->authorizeIndividualRecords(fn (ReplaySession $record): bool => static::canDelete($record))]),
            ])
            ->emptyStateHeading(__('No recordings yet'))
            ->emptyStateDescription(__('Recordings appear here as soon as someone uses a recorded page.'))
            ->emptyStateIcon(Heroicon::OutlinedPlayCircle);
    }

    /**
     * The path alone for a page of this app (the host says nothing there), host and path for any other.
     *
     * @internal
     */
    public static function shortUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && in_array($host, [request()->getHost(), parse_url((string) config('app.url'), PHP_URL_HOST)], true)) {
            $port = parse_url($url, PHP_URL_PORT);
            $path = Str::after(Str::after($url, '://'), $host.($port ? ':'.$port : ''));

            return $path === '' ? '/' : $path;
        }

        return Str::after($url, '://');
    }

    /**
     * A pinned recording is never pruned.
     *
     * @internal The row and watch-page action.
     */
    public static function pinAction(): Action
    {
        return Action::make('pin')
            ->label(fn (ReplaySession $record): string => $record->pinned ? __('Unpin') : __('Pin'))
            ->icon(fn (ReplaySession $record): Heroicon => $record->pinned ? Heroicon::OutlinedBookmarkSlash : Heroicon::OutlinedBookmark)
            ->color('gray')
            ->authorize(fn (ReplaySession $record): bool => static::canView($record))
            ->action(fn (ReplaySession $record) => $record->forceFill(['pinned' => ! $record->pinned])->save());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReplaySessions::route('/'),
            // Before {record}, which would take "requests" for a recording id.
            'requests' => AssistRequests::route('/requests'),
            'stage' => AssistStage::route('/requests/{assistRequest}/stage'),
            'view' => ViewReplaySession::route('/{record}'),
        ];
    }
}
