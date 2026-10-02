<?php

namespace Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Number;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Support\ReplayStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The facts of a recording and the player. The player loads its data from
 * the core's routes, which ask the viewSessionReplay gate for this recording
 * again on every request.
 */
class ViewReplaySession extends ViewRecord
{
    protected static string $resource = ReplaySessionResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var ReplaySession $record */
        $record = $this->getRecord();

        return SessionReplayPlugin::get()->userLabel($record);
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var ReplaySession $record */
        $record = $this->getRecord();

        return $record->started_at?->toDayDateTimeString();
    }

    public function getBreadcrumb(): string
    {
        return __('Watch');
    }

    protected function getHeaderActions(): array
    {
        return [
            ReplaySessionResource::pinAction(),
            Action::make('export')
                ->label(__('Export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                // The export carries the recording's content, so it asks what the core's data routes ask: the gate,
                // on top of whatever lets this page open (a policy, when the app registered one).
                ->authorize(fn (ReplaySession $record): bool => ReplaySessionResource::canView($record) && SessionReplay::check($record, Filament::auth()->user()))
                ->action(fn (ReplaySession $record): StreamedResponse => $this->export($record)),
            DeleteAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['default' => 2, 'md' => 4, 'xl' => 7])
                ->schema([
                    TextEntry::make('duration')->label(__('Length'))->state(fn (ReplaySession $record): string => $record->durationForHumans())
                        ->badge(fn (ReplaySession $record): bool => $record->isLive())->color('success'),
                    TextEntry::make('page_count')->label(__('Pages')),
                    TextEntry::make('error_count')->label(__('Errors'))->badge()->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                    TextEntry::make('vitals')->label(__('Vitals'))
                        ->state(fn (ReplaySession $record): string => collect([
                            'LCP' => $record->lcp_ms === null ? null : $record->lcp_ms.' ms',
                            'INP' => $record->inp_ms === null ? null : $record->inp_ms.' ms',
                            'CLS' => $record->cls === null ? null : number_format($record->cls, 3),
                        ])->filter()->map(fn (string $value, string $name): string => "{$name} {$value}")->implode(' · ') ?: '·'),
                    TextEntry::make('device')->label(__('Device'))
                        ->state(fn (ReplaySession $record): string => trim(__(ucfirst((string) $record->device)).' '.($record->viewport_width ? $record->viewport_width.'×'.$record->viewport_height : '')) ?: '·'),
                    TextEntry::make('bytes')->label(__('Size'))->formatStateUsing(fn (int $state): string => Number::fileSize($state)),
                    TextEntry::make('impersonator_id')->label(__('Impersonated by'))->prefix('#')->visible(fn (ReplaySession $record): bool => $record->impersonator_id !== null),
                    TextEntry::make('entry_url')->label(__('First page'))->columnSpanFull()->copyable(),
                    TextEntry::make('truncated')->hiddenLabel()->columnSpanFull()->color('warning')
                        ->state(__('This recording reached its size limit and stops before the visit ended.'))
                        ->visible(fn (ReplaySession $record): bool => $record->truncated),
                ]),
            View::make('filament-session-replay::player')->columnSpanFull(),
        ]);
    }

    /** Everything the recording holds as one JSON file: the rrweb events as stored, plus the index. */
    protected function export(ReplaySession $record): StreamedResponse
    {
        $storage = app(ReplayStorage::class);

        return response()->streamDownload(function () use ($record, $storage): void {
            echo '{"session":'.json_encode($record->only(['id', 'user_type', 'user_id', 'tenant_type', 'tenant_id', 'entry_url', 'started_at', 'last_activity_at', 'properties']));
            echo ',"markers":'.json_encode($record->markers->map->only(['type', 'label', 'payload', 'at_ms'])->all());
            echo ',"chunks":[';

            $hashes = [];

            foreach ($record->chunks as $index => $chunk) {
                $gzip = $storage->get($chunk->path);
                $json = $gzip === null ? false : @gzdecode($gzip);

                // A chunk is what a browser uploaded; only a JSON array goes into the file as is.
                if ($json !== false && (! str_starts_with(ltrim($json), '[') || ! json_validate($json))) {
                    $json = false;
                }

                if ($json !== false && preg_match_all('/sr-asset:([a-f0-9]{64})/', $json, $matches)) {
                    $hashes += array_flip($matches[1]);
                }

                echo ($index ? ',' : '').($json === false ? '[]' : $json);
            }

            // The stylesheets the events point at (/*sr-asset:<sha256>*/), so the file replays on its own.
            echo '],"assets":{';

            foreach (ReplayAsset::query()->whereIn('hash', array_keys($hashes))->get() as $index => $asset) {
                $css = @gzdecode((string) $storage->get($asset->path));

                echo ($index ? ',' : '').json_encode($asset->hash).':'.json_encode($css === false ? '' : $css);
            }

            echo '}}';
        }, 'session-replay-'.$record->id.'.json', ['Content-Type' => 'application/json']);
    }
}
