<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ListReplaySessions;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ViewReplaySession;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Tests\Fixtures\ReplaySessionPolicy;

it('follows the viewSessionReplay gate: nobody by default, then whoever it allows', function () {
    $this->actingAs($staff = $this->user(['is_staff' => true]));

    expect(ReplaySessionResource::canViewAny())->toBeFalse();
    $this->get(ReplaySessionResource::getUrl())->assertForbidden();

    Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => $user->is_staff);

    $this->get(ReplaySessionResource::getUrl())->assertOk();
    $this->actingAs($this->user())->get(ReplaySessionResource::getUrl())->assertForbidden();
});

it('passes the recording to the gate for the watch page', function () {
    // Support may watch customers, never other staff.
    Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => $user->is_staff && ! $session?->user?->is_staff);

    $customers = $this->recording($this->user());
    $colleagues = $this->recording($this->user(['is_staff' => true]));

    $this->actingAs($this->user(['is_staff' => true]));

    $this->get(ReplaySessionResource::getUrl('view', ['record' => $customers]))
        ->assertOk()
        ->assertSee('data-session-replay-player', false)
        ->assertSee(route('session-replay.manifest', $customers), false);

    $this->get(ReplaySessionResource::getUrl('view', ['record' => $colleagues]))->assertForbidden();
});

it('lets a registered policy decide instead, which is how Shield plugs in', function () {
    Gate::define('viewSessionReplay', fn ($user) => false);
    Gate::policy(ReplaySession::class, ReplaySessionPolicy::class);

    $session = $this->recording();

    $this->actingAs($this->user(['is_staff' => true]));

    expect(ReplaySessionResource::canViewAny())->toBeTrue()
        ->and(ReplaySessionResource::canView($session))->toBeTrue()
        ->and(ReplaySessionResource::canDelete($session))->toBeFalse();
});

it('lists recordings and filters them by what was marked', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $ada = $this->user(['email' => 'ada@example.com']);
    $now = now()->getTimestampMs();

    $broken = $this->recording($ada, markers: [['type' => 'error', 'label' => 'Boom', 'at' => $now]]);
    $slow = $this->recording(markers: [['type' => 'vital', 'label' => 'INP', 'payload' => ['name' => 'INP', 'value' => 900], 'at' => $now]]);
    $angry = $this->recording(markers: [['type' => 'rage-click', 'label' => 'button', 'at' => $now]]);
    $watched = $this->recording(impersonator: '5');
    $kept = $this->recording(attributes: ['pinned' => true, 'device' => 'mobile', 'entry_url' => 'https://app.test/admin/invoices/7']);

    $this->actingAs($ada);

    Livewire::test(ListReplaySessions::class)
        ->assertCanSeeTableRecords([$broken, $slow, $angry, $watched, $kept])
        ->assertSee('ada@example.com')
        ->assertSee('Impersonated by #5')
        ->filterTable('errors')->assertCanSeeTableRecords([$broken])->assertCanNotSeeTableRecords([$slow, $angry])->resetTableFilters()
        ->filterTable('poor_vitals')->assertCanSeeTableRecords([$slow])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('rage_clicks')->assertCanSeeTableRecords([$angry])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('impersonated')->assertCanSeeTableRecords([$watched])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('pinned')->assertCanSeeTableRecords([$kept])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('device', 'mobile')->assertCanSeeTableRecords([$kept])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('url', ['contains' => 'invoices'])->assertCanSeeTableRecords([$kept])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->searchTable((string) $ada->id)->assertCanSeeTableRecords([$broken]);
});

it('lists only the current workspace\'s recordings in a panel with tenancy, unless told otherwise', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    $mine = $this->recording(team: $acme);
    $theirs = $this->recording(team: $globex);

    expect(ReplaySessionResource::getEloquentQuery()->pluck('id')->all())->toEqualCanonicalizing([$mine->id, $theirs->id]);

    Filament::setTenant($acme, isQuiet: true);

    expect(ReplaySessionResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine->id]);

    SessionReplayPlugin::get()->scopeToTenant(false);

    expect(ReplaySessionResource::getEloquentQuery()->count())->toBe(2);
});

it('pins, unpins and deletes a recording together with its files', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $session = $this->recording($user = $this->user());
    $path = $session->chunks()->sole()->path;

    $this->actingAs($user);

    Livewire::test(ListReplaySessions::class)->callTableAction('pin', $session);
    expect($session->refresh()->pinned)->toBeTrue();

    Livewire::test(ListReplaySessions::class)->callTableAction('pin', $session);
    expect($session->refresh()->pinned)->toBeFalse();

    Livewire::test(ListReplaySessions::class)->callTableAction('delete', $session);

    expect(ReplaySession::query()->count())->toBe(0);
    Storage::disk('replays')->assertMissing($path);
});

it('keeps deleting for whoever the deleteSessionReplay gate allows, once the app defines it', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);
    Gate::define('deleteSessionReplay', fn ($user, ?ReplaySession $session = null) => $user->is_staff);

    $session = $this->recording();

    $this->actingAs($this->user());
    expect(ReplaySessionResource::canDelete($session))->toBeFalse()->and(ReplaySessionResource::canDeleteAny())->toBeFalse();

    $this->actingAs($this->user(['is_staff' => true]));
    expect(ReplaySessionResource::canDelete($session))->toBeTrue();
});

it('exports a recording as one JSON file that carries its stylesheets', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $session = $this->recording($user = $this->user(), markers: [['type' => 'custom', 'label' => 'Checkout started', 'at' => now()->getTimestampMs()]]);

    $this->actingAs($user);

    $response = Livewire::test(ViewReplaySession::class, ['record' => $session->id])->callAction('export');
    $response->assertFileDownloaded('session-replay-'.$session->id.'.json');
});

it('never offers to create or edit a recording', function () {
    expect(ReplaySessionResource::canCreate())->toBeFalse()
        ->and(ReplaySessionResource::canEdit(new ReplaySession))->toBeFalse()
        ->and(ReplaySessionResource::getNavigationGroup())->toBe('Support')
        ->and(ReplaySessionResource::getSlug())->toBe('session-replays');
});
