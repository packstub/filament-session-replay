<?php

namespace Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\SessionReplay\Filament\Actions\WatchLastSessionAction;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\RelationManagers\ReplaysRelationManager;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\Pages\EditUser;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\Pages\ListUsers;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Billing')->blockInReplay()->schema([
                TextInput::make('name'),
                TextInput::make('email')->maskInReplay(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name'), TextColumn::make('email')->maskInReplay()])
            ->recordActions([WatchLastSessionAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ReplaysRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
