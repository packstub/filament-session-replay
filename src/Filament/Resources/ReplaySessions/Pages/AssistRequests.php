<?php

namespace Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Filament\Assist\AssistActions;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Filament\View\SessionReplayIconAlias as Icon;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;

/**
 * Help requests: the visitors who asked and wait, and the agent's own requests
 * (claimed, offered, live). The rows are the core's queue for this agent, so
 * the gate, visibleUsing(), canAssist and the panel's workspace decide what is
 * listed; the actions ask again. Polled, so a new request shows up on its own.
 */
class AssistRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ReplaySessionResource::class;

    /** How often the list asks for news; the visitor's page polls every 3 s while it waits. */
    public const POLL = '5s';

    public static function canAccess(array $parameters = []): bool
    {
        return PanelAssist::mayAssist();
    }

    public function getTitle(): string|Htmlable
    {
        return __('session-replay::viewer.assist.title');
    }

    public function getBreadcrumb(): string
    {
        return __('session-replay::viewer.assist.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return PanelAssist::hasLiveView()
            ? __('Claim a request to see the visitor’s page live and talk to them.')
            : __('Without packstub/calls there is no live view: claim a request and reach the person through your own channels.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordings')
                ->label(ReplaySessionResource::getNavigationLabel())
                ->icon(Heroicon::OutlinedPlayCircle)
                ->color('gray')
                ->url(ReplaySessionResource::getUrl()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AssistRequest::query()
                ->whereKey(PanelAssist::queue()->modelKeys())
                ->with(['session.user', 'user']))
            ->poll(static::POLL)
            ->defaultSort('created_at')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('status')
                    ->label(__('session-replay::viewer.assist.status'))
                    ->badge()
                    ->formatStateUsing(fn (AssistRequest $record): string => static::statusLabel($record))
                    ->color(fn (AssistRequest $record): string => match (true) {
                        $record->status === AssistRequest::ACTIVE => 'success',
                        $record->status === AssistRequest::CLAIMED => 'info',
                        $record->openedByAgent() => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (AssistRequest $record): ?string => static::holder($record)),
                TextColumn::make('user_id')
                    ->label(__('Person'))
                    ->state(fn (AssistRequest $record): string => PanelAssist::personLabel($record))
                    ->description(fn (AssistRequest $record): ?string => $record->openedByAgent() ? __('You offered help') : null)
                    ->weight('medium'),
                // The visitor's own words: Filament escapes column text, so it is never markup.
                TextColumn::make('message')
                    ->label(__('session-replay::viewer.assist.message'))
                    ->placeholder('·')
                    ->wrap()
                    ->lineClamp(3)
                    ->limit(200),
                TextColumn::make('page_url')
                    ->label(__('session-replay::viewer.assist.page'))
                    ->formatStateUsing(fn (string $state): string => ReplaySessionResource::shortUrl($state))
                    ->limit(40)
                    ->tooltip(fn (AssistRequest $record): ?string => $record->page_url)
                    ->color('gray')
                    ->placeholder('·'),
                TextColumn::make('created_at')
                    ->label(__('session-replay::viewer.assist.waiting'))
                    ->state(fn (AssistRequest $record): string => static::duration($record->waitingFor()))
                    ->tooltip(fn (AssistRequest $record): string => $record->created_at->toDayDateTimeString())
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('created_at', $direction)),
                IconColumn::make('visitor_seen_at')
                    ->label(__('Still here'))
                    ->state(fn (AssistRequest $record): bool => $record->visitorIsHere())
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedSignal)
                    ->falseIcon(Heroicon::OutlinedSignalSlash)
                    ->tooltip(fn (AssistRequest $record): string => $record->visitor_seen_at === null
                        ? __('The page has not polled yet')
                        : __('Last heard from :ago ago', ['ago' => static::duration($record->visitorQuietFor())])),
            ])
            ->recordActions([
                AssistActions::claim(),
                AssistActions::openStage(),
                AssistActions::withdraw(),
                AssistActions::end(),
                Action::make('watchRecording')
                    ->label(__('Watch recording'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip(__('Watch recording'))
                    ->url(fn (AssistRequest $record): ?string => $record->session instanceof ReplaySession ? ReplaySessionResource::getUrl('view', ['record' => $record->session]) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (AssistRequest $record): bool => $record->session instanceof ReplaySession && ReplaySessionResource::canView($record->session)),
            ])
            ->emptyStateIcon(AssistActions::icon(Icon::ASSIST_NAVIGATION, Heroicon::OutlinedLifebuoy))
            ->emptyStateHeading(__('session-replay::viewer.assist.empty'))
            ->emptyStateDescription(__('A request shows up here as soon as someone asks for help on a recorded page.'));
    }

    /** Waiting, offered, claimed or live, as a badge. */
    public static function statusLabel(AssistRequest $record): string
    {
        if ($record->status === AssistRequest::OPEN && $record->openedByAgent()) {
            return __('Offered');
        }

        return Str::ucfirst(__('session-replay::viewer.assist.statuses.'.$record->status));
    }

    /** "You", or who else holds it. */
    protected static function holder(AssistRequest $record): ?string
    {
        if ($record->agent_id === null) {
            return null;
        }

        if (PanelAssist::isMine($record)) {
            return __('by you');
        }

        return $record->openedByAgent()
            ? __('session-replay::viewer.assist.offered_by', ['name' => $record->agent_name])
            : __('session-replay::viewer.assist.claimed_by', ['name' => $record->agent_name]);
    }

    /** 45 s, 3 min, 1 h 05 min. */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => __(':n s', ['n' => $seconds]),
            $seconds < 3600 => __(':n min', ['n' => intdiv($seconds, 60)]),
            default => __(':h h :m min', ['h' => intdiv($seconds, 3600), 'm' => sprintf('%02d', intdiv($seconds % 3600, 60))]),
        };
    }
}
