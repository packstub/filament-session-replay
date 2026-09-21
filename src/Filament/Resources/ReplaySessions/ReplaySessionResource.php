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
use Filament\Forms\Components\TextInput;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ListReplaySessions;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ViewReplaySession;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;
use UnitEnum;

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

    public static function getSlug(?Panel $panel = null): string
    {
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
        return static::scope(parent::getEloquentQuery()->with('user'));
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

        return SessionReplay::visibleTo($query, Filament::auth()->user());
    }

    /**
     * The person's key, or what the search box holds found in the recorded
     * people's own tables. No join: the index may live on another connection
     * than the people, and a recording names its person by morph type and key.
     *
     * @param  Builder<ReplaySession>  $query
     */
    public static function searchPeople(Builder $query, string $search): void
    {
        $columns = SessionReplayPlugin::get()->getSearchPeopleBy();
        $types = $columns === [] ? [] : ReplaySession::query()->whereNotNull('user_type')->distinct()->pluck('user_type')->all();

        $query->where(function (Builder $query) use ($search, $columns, $types): void {
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

                $keys = $person->newQuery()
                    ->whereAny($known, 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->limit(200)
                    ->pluck($person->getKeyName())
                    ->map(fn (mixed $key): string => (string) $key)
                    ->all();

                if ($keys !== []) {
                    $query->orWhere(fn (Builder $query) => $query->where('user_type', $type)->whereIn('user_id', $keys));
                }
            }
        });
    }

    protected static function hasPolicy(): bool
    {
        return Gate::getPolicyFor(ReplaySession::class) !== null;
    }

    public static function canViewAny(): bool
    {
        return static::hasPolicy() ? parent::canViewAny() : SessionReplay::check(user: Filament::auth()->user());
    }

    public static function canView(Model $record): bool
    {
        return static::hasPolicy() ? parent::canView($record) : SessionReplay::check($record, Filament::auth()->user());
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
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('user_id')
                    ->label(__('Person'))
                    ->formatStateUsing(fn (ReplaySession $record): string => SessionReplayPlugin::get()->userLabel($record))
                    ->default('guest')
                    ->description(fn (ReplaySession $record): ?string => $record->impersonator_id ? __('Impersonated by #:id', ['id' => $record->impersonator_id]) : null)
                    ->searchable(query: fn (Builder $query, string $search) => static::searchPeople($query, $search)),
                TextColumn::make('tenant_id')
                    ->label(__('Workspace'))
                    ->formatStateUsing(fn (ReplaySession $record): string => (string) SessionReplayPlugin::get()->tenantLabel($record))
                    ->placeholder('·')
                    ->visible(fn (): bool => Filament::getTenant() === null)
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->label(__('Started'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->badge(fn (ReplaySession $record): bool => $record->isLive())
                    ->color(fn (ReplaySession $record): ?string => $record->isLive() ? 'success' : null),
                TextColumn::make('duration')
                    ->label(__('Length'))
                    ->state(fn (ReplaySession $record): string => $record->durationForHumans()),
                TextColumn::make('page_count')->label(__('Pages'))->numeric()->sortable(),
                TextColumn::make('error_count')
                    ->label(__('Errors'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
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
                ViewAction::make()->label(__('Watch'))->icon(Heroicon::OutlinedPlay),
                static::pinAction(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading(__('No recordings yet'))
            ->emptyStateDescription(__('Recordings appear here as soon as someone uses a recorded page.'))
            ->emptyStateIcon(Heroicon::OutlinedPlayCircle);
    }

    /** The path alone for a page of this app (the host says nothing there), host and path for any other. */
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

    /** A pinned recording is never pruned. */
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
            'view' => ViewReplaySession::route('/{record}'),
        ];
    }
}
