<?php

namespace Packstub\SessionReplay\Filament\Resources\ReplaySessions\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\ReplaySession;

/**
 * A person's recordings on their own resource page. The model uses
 * HasSessionReplays; add this class to the resource's getRelations().
 */
class ReplaysRelationManager extends RelationManager
{
    protected static string $relationship = 'sessionReplays';

    protected static ?string $relatedResource = ReplaySessionResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Session replays');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ReplaySessionResource::canViewAny();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $table = ReplaySessionResource::table($table);

        // Every row is the same person here.
        return $table
            ->columns(array_filter($table->getColumns(), fn (Column $column): bool => $column->getName() !== 'user_id'))
            ->recordActions([
                Action::make('watch')
                    ->label(__('Watch'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->authorize(fn (ReplaySession $record): bool => ReplaySessionResource::canView($record))
                    ->url(fn (ReplaySession $record): string => ReplaySessionResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
