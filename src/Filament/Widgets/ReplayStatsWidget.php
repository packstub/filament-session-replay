<?php

namespace Packstub\SessionReplay\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;

/** The last seven days at a glance; each number opens the list with the matching filter. */
class ReplayStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return ReplaySessionResource::canViewAny();
    }

    protected function getStats(): array
    {
        $week = fn (): Builder => $this->query()->where('started_at', '>=', now()->subDays(7));

        return [
            Stat::make(__('Recordings, last 7 days'), number_format($week()->count()))
                ->url(ReplaySessionResource::getUrl()),
            Stat::make(__('With errors'), number_format($week()->withErrors()->count()))
                ->color('danger')
                ->url(ReplaySessionResource::getUrl(parameters: ['filters' => ['errors' => ['value' => 1]]])),
            Stat::make(__('Slow pages (poor vitals)'), number_format($week()->poorVitals()->count()))
                ->color('warning')
                ->url(ReplaySessionResource::getUrl(parameters: ['filters' => ['poor_vitals' => ['isActive' => true]]])),
        ];
    }

    protected function query(): Builder
    {
        $query = ReplaySession::query();
        $tenant = Filament::getTenant();

        if ($tenant instanceof Model && SessionReplayPlugin::get()->isScopedToTenant()) {
            $query->forTenant($tenant);
        }

        return $query;
    }
}
