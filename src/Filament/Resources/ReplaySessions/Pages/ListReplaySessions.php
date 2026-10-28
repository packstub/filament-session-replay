<?php

namespace Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;

class ListReplaySessions extends ListRecords
{
    protected static string $resource = ReplaySessionResource::class;

    /**
     * While Assist is on: every recording, and the ones live now (an upload or a heartbeat in the last two
     * minutes, the window the core's isLive() uses), where Offer help is.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        if (! PanelAssist::enabled()) {
            return [];
        }

        return [
            'all' => Tab::make(__('All')),
            'live' => Tab::make(__('Live now'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->live())
                ->badge(fn (): ?int => ($count = ReplaySessionResource::scope(ReplaySessionResource::getModel()::query())->live()->count()) > 0 ? $count : null)
                ->badgeColor('success'),
        ];
    }
}
