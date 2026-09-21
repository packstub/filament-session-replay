<?php

namespace Workbench\App\Filament\Resources\Orders;

use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\Orders\Pages\CreateOrder;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\Order;

/**
 * The measuring lab (workbench/lab/measure.mjs): a table of 50 rows that can
 * poll every two seconds with a cell that changes each time (?poll=1), an
 * edit modal, and a create page with a rich editor and a file upload.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('reference')->required(),
            TextInput::make('customer')->required(),
            Select::make('status')->options(['new' => 'New', 'paid' => 'Paid', 'packed' => 'Packed', 'shipped' => 'Shipped', 'refunded' => 'Refunded'])->required(),
            TextInput::make('total')->numeric()->required(),
            RichEditor::make('notes')->columnSpanFull(),
            FileUpload::make('attachment')->disk('local')->directory('lab')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll(request()->boolean('poll') || str_contains((string) request()->header('Referer'), 'poll=1') ? '2s' : null)
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('customer')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total')->money('usd', divideBy: 100)->sortable(),
                // Changes on every poll, in every row: the most a polled table can ask of the recorder.
                TextColumn::make('checked')->state(fn (): string => now()->format('H:i:s')),
            ])
            ->filters([SelectFilter::make('status')->options(['new' => 'New', 'paid' => 'Paid', 'shipped' => 'Shipped'])])
            // A plain four-field modal; the rich editor and the upload are measured on the create page.
            ->recordActions([EditAction::make()->schema(fn (Schema $schema): Schema => $schema->components(array_slice(static::form($schema)->getComponents(), 0, 4)))]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
        ];
    }
}
