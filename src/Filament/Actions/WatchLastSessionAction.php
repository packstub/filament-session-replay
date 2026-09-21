<?php

namespace Packstub\SessionReplay\Filament\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\ReplaySession;

/**
 * "What did they just do?" — on a person's row or page, opens their most
 * recent recording. Hidden when there is none or the viewer may not watch it.
 */
class WatchLastSessionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'watchLastSession';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Watch last session'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('gray')
            ->visible(fn (Model $record): bool => ($session = static::lastSession($record)) !== null && ReplaySessionResource::canView($session))
            ->url(fn (Model $record): ?string => ($session = static::lastSession($record)) ? ReplaySessionResource::getUrl('view', ['record' => $session]) : null);
    }

    public static function lastSession(Model $user): ?ReplaySession
    {
        return once(fn (): ?ReplaySession => SessionReplay::visibleTo(ReplaySession::query(), Filament::auth()->user())->forUser($user)->latest('started_at')->first());
    }
}
