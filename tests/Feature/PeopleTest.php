<?php

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Packstub\SessionReplay\Filament\Actions\WatchLastSessionAction;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\RelationManagers\ReplaysRelationManager;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Filament\Widgets\ReplayStatsWidget;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\Pages\EditUser;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\Pages\ListUsers;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\UserResource;

it('shows a person\'s recordings on their own page', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $customer = $this->user();
    $theirs = $this->recording($customer);
    $someoneElses = $this->recording();

    $this->actingAs($this->user());

    Livewire::test(ReplaysRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditUser::class])
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$someoneElses])
        ->assertTableColumnDoesNotExist('user_id')
        ->assertTableColumnExists('started_at')
        ->assertTableActionHasUrl('watch', ReplaySessionResource::getUrl('view', ['record' => $theirs]), $theirs);
});

it('hides the relation manager from people who may not watch', function () {
    Gate::define('viewSessionReplay', fn ($user) => false);

    $this->actingAs($this->user());

    expect(ReplaysRelationManager::canViewForRecord($this->user(), EditUser::class))->toBeFalse();
});

it('opens the most recent recording from a person\'s row', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $customer = $this->user();
    $quiet = $this->user();

    $this->recording($customer, ['started_at' => now()->subDay()]);
    $latest = $this->recording($customer);

    $this->actingAs($this->user());

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('watchLastSession', $customer)
        ->assertTableActionHasUrl('watchLastSession', ReplaySessionResource::getUrl('view', ['record' => $latest]), $customer)
        ->assertTableActionHidden('watchLastSession', $quiet);

    expect(WatchLastSessionAction::lastSession($customer)->is($latest))->toBeTrue();
});

it('declares masking where the field is', function () {
    expect(TextInput::make('iban')->maskInReplay()->getExtraFieldWrapperAttributes())->toHaveKey('data-replay-mask')
        ->and(TextInput::make('iban')->blockInReplay()->getExtraFieldWrapperAttributes())->toHaveKey('data-replay-block')
        ->and(TextInput::make('iban')->maskInReplay(false)->getExtraFieldWrapperAttributes())->not->toHaveKey('data-replay-mask')
        ->and(TextColumn::make('email')->maskInReplay()->getExtraCellAttributes())->toHaveKey('data-replay-mask')
        ->and(TextEntry::make('email')->maskInReplay()->getExtraEntryWrapperAttributes())->toHaveKey('data-replay-mask')
        ->and(Section::make('Billing')->blockInReplay()->getExtraAttributes())->toHaveKey('data-replay-block');
});

it('renders the masking attributes into the page', function () {
    $this->actingAs($user = $this->user());

    $this->get(UserResource::getUrl('edit', ['record' => $user]))
        ->assertOk()
        ->assertSee('data-replay-mask', false)
        ->assertSee('data-replay-block', false);
});

it('sums up the last seven days on the dashboard', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $this->recording(attributes: ['error_count' => 2]);
    $this->recording(attributes: ['lcp_ms' => 6000]);
    $this->recording(attributes: ['started_at' => now()->subDays(20), 'error_count' => 1]);

    $this->actingAs($this->user());

    Livewire::test(ReplayStatsWidget::class)
        ->assertSeeInOrder(['Recordings, last 7 days', '2', 'With errors', '1', 'Slow pages', '1']);

    Gate::define('viewSessionReplay', fn ($user) => false);

    expect(ReplayStatsWidget::canView())->toBeFalse();
});
