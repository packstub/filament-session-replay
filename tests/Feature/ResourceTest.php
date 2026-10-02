<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Actions\WatchLastSessionAction;
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
        ->assertSee('--sr-pointer: var(--primary-500)', false)
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

it('finds a person by name or email, in whatever table they live', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $grace = $this->user(['name' => 'Grace Hopper', 'email' => 'grace@navy.example']);
    $hers = $this->recording($grace);
    $other = $this->recording($this->user(['name' => 'Alan Turing']));

    $this->actingAs($grace);

    Livewire::test(ListReplaySessions::class)
        ->searchTable('hopper')->assertCanSeeTableRecords([$hers])->assertCanNotSeeTableRecords([$other])
        ->searchTable('navy.example')->assertCanSeeTableRecords([$hers])->assertCanNotSeeTableRecords([$other])
        ->searchTable('100%')->assertCanNotSeeTableRecords([$hers, $other]);

    SessionReplayPlugin::get()->searchPeopleBy(['email', 'no_such_column']);

    Livewire::test(ListReplaySessions::class)
        ->searchTable('hopper')->assertCanNotSeeTableRecords([$hers])
        ->searchTable('navy')->assertCanSeeTableRecords([$hers]);

    SessionReplayPlugin::get()->searchPeopleBy(['name', 'email']);
});

it('names a workspace the way the app says', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $recording = $this->recording(team: $this->team('Acme'));

    expect(SessionReplayPlugin::get()->tenantLabel($recording))->toBe('Acme')
        ->and(SessionReplayPlugin::get()->tenantLabel($this->recording()))->toBeNull();

    SessionReplayPlugin::get()->tenantLabelUsing(fn (ReplaySession $session): string => 'Workspace '.$session->tenant_id);

    $this->actingAs($this->user());

    Livewire::test(ListReplaySessions::class)->assertSee('Workspace '.$recording->tenant_id);
});

it('keeps what SessionReplay::visibleUsing() hides out of the list, the widget, a person\'s tab and the last-session action', function () {
    Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => $session === null || ! $session->user?->is_staff);

    $viewer = $this->user();
    $staff = $this->user(['is_staff' => true]);
    $open = $this->recording($this->user());
    $hidden = $this->recording($staff);

    $seenBy = null;

    SessionReplay::visibleUsing(function ($query, $user) use (&$seenBy, $staff): void {
        $seenBy = $user;
        $query->where('user_id', '!=', (string) $staff->id);
    });

    $this->actingAs($viewer);

    Livewire::test(ListReplaySessions::class)->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$hidden]);

    expect($seenBy->is($viewer))->toBeTrue()
        ->and(ReplaySessionResource::scope(ReplaySession::query())->count())->toBe(1)
        ->and(WatchLastSessionAction::lastSession($staff))->toBeNull();
});

it('ships every string it shows in German, Spanish, Romanian and Russian', function () {
    $source = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
        $source .= file_get_contents($file->getPathname());
    }

    preg_match_all("/__\\('((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $source, $matches);

    // The three vitals ratings and the three devices reach __() through a variable.
    $strings = array_unique([...array_map('stripslashes', $matches[1]), 'Good', 'Needs improvement', 'Poor']);

    foreach (['de', 'es', 'ro', 'ru'] as $locale) {
        $translated = json_decode(file_get_contents(__DIR__."/../../resources/lang/{$locale}.json"), true);

        expect(array_diff($strings, array_keys($translated)))->toBe([], $locale)
            ->and(array_diff(array_keys($translated), $strings))->toBe([], $locale);
    }

    app()->setLocale('de');

    expect(ReplaySessionResource::getNavigationLabel())->toBe('Session Replays');
});

it('shows the path of the app\'s own pages and the host of any other', function () {
    config()->set('app.url', 'https://app.test');

    expect(ReplaySessionResource::shortUrl('https://app.test/admin/orders?page=2'))->toBe('/admin/orders?page=2')
        ->and(ReplaySessionResource::shortUrl('https://app.test/admin?at=10:30'))->toBe('/admin?at=10:30')
        ->and(ReplaySessionResource::shortUrl('https://app.test'))->toBe('/')
        ->and(ReplaySessionResource::shortUrl('http://app.test:8080/admin'))->toBe('/admin')
        ->and(ReplaySessionResource::shortUrl('https://shop.example.com/cart'))->toBe('shop.example.com/cart');
});
