<?php

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Actions\WatchLastSessionAction;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ListReplaySessions;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ViewReplaySession;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Models\ReplaySessionAsset;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Support\ReplayStorage;
use Packstub\SessionReplay\Tests\Fixtures\Models\Team;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;
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

it('lets a registered policy decide without a gate, which is how Shield plugs in, and the gate decide over it', function () {
    Gate::policy(ReplaySession::class, ReplaySessionPolicy::class);

    $session = $this->recording();

    $this->actingAs($staff = $this->user(['is_staff' => true]));

    // The policy alone: viewAny and view open the list, the page and (in the core) the player's data; delete says no.
    expect(ReplaySessionResource::canViewAny())->toBeTrue()
        ->and(ReplaySessionResource::canView($session))->toBeTrue()
        ->and(ReplaySessionResource::canDelete($session))->toBeFalse()
        ->and(SessionReplay::check($session, $staff))->toBeTrue();

    $this->get(ReplaySessionResource::getUrl('view', ['record' => $session]))->assertOk();
    $this->get(route('session-replay.manifest', $session))->assertOk();

    // A gate the app defines wins, as it does for the core's data routes: the page and the player cannot disagree.
    Gate::define('viewSessionReplay', fn ($user) => false);

    expect(ReplaySessionResource::canViewAny())->toBeFalse()
        ->and(ReplaySessionResource::canView($session))->toBeFalse()
        ->and(ReplaySessionResource::canDelete($session))->toBeFalse();

    $this->get(ReplaySessionResource::getUrl('view', ['record' => $session]))->assertForbidden();
});

it('lists recordings and filters them by what was marked', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $ada = $this->user(['email' => 'ada@example.com']);
    $now = now()->getTimestampMs();

    $broken = $this->recording($ada, markers: [['type' => 'error', 'label' => 'Boom', 'at' => $now]]);
    $slow = $this->recording(markers: [['type' => 'vital', 'label' => 'INP', 'payload' => ['name' => 'INP', 'value' => 900], 'at' => $now]]);
    $angry = $this->recording(markers: [['type' => 'rage-click', 'label' => 'button', 'at' => $now]]);
    $watched = $this->recording(impersonator: '5');
    $reported = $this->recording(markers: [['type' => 'report', 'label' => 'Wrong total', 'at' => $now]]);
    $kept = $this->recording(attributes: ['pinned' => true, 'device' => 'mobile', 'entry_url' => 'https://app.test/admin/invoices/7']);

    $this->actingAs($ada);

    Livewire::test(ListReplaySessions::class)
        ->assertCanSeeTableRecords([$broken, $slow, $angry, $watched, $kept, $reported])
        ->assertSee('ada@example.com')
        ->assertSee('Impersonated by #5')
        ->assertTableColumnStateSet('reported', 'Reported', $reported)
        ->assertTableColumnStateSet('reported', null, $broken)
        ->filterTable('errors')->assertCanSeeTableRecords([$broken])->assertCanNotSeeTableRecords([$slow, $angry])->resetTableFilters()
        ->filterTable('reported')->assertCanSeeTableRecords([$reported])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('poor_vitals')->assertCanSeeTableRecords([$slow])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('rage_clicks')->assertCanSeeTableRecords([$angry])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('impersonated')->assertCanSeeTableRecords([$watched])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('pinned')->assertCanSeeTableRecords([$kept])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('device', 'mobile')->assertCanSeeTableRecords([$kept])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->filterTable('url', ['contains' => 'invoices'])->assertCanSeeTableRecords([$kept])->assertCanNotSeeTableRecords([$broken])->resetTableFilters()
        ->searchTable((string) $ada->id)->assertCanSeeTableRecords([$broken]);
});

it('names the impersonator like a person when the hook handed over a model, by key otherwise', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $support = $this->user(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    $byModel = $this->recording(impersonator: $support);
    $byKey = $this->recording(impersonator: (string) $support->id);

    expect($byModel->impersonator_type)->toBe($support->getMorphClass())
        ->and($byModel->impersonator->is($support))->toBeTrue()
        ->and($byKey->impersonator_type)->toBeNull()
        ->and(SessionReplayPlugin::get()->impersonatorLabel($byModel))->toBe('grace@example.com')
        ->and(SessionReplayPlugin::get()->impersonatorLabel($byKey))->toBe('#'.$support->id)
        ->and(SessionReplayPlugin::get()->impersonatorLabel($this->recording()))->toBeNull();

    $this->actingAs($this->user());

    Livewire::test(ListReplaySessions::class)->assertSee('Impersonated by grace@example.com')->assertSee('Impersonated by #'.$support->id);
    $this->get(ReplaySessionResource::getUrl('view', ['record' => $byModel]))->assertOk()->assertSee('grace@example.com');

    // The panel's own hook passes what impersonatorUsing() returns through, a model included.
    SessionReplayPlugin::get()->impersonatorUsing(fn () => $support);
    $token = (new ReflectionMethod(SessionReplayPlugin::class, 'impersonator'))->invoke(SessionReplayPlugin::get());

    expect($token)->toBe($support);
});

it('starts the watch page\'s player paused unless viewer.autoplay says otherwise', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $session = $this->recording();
    $this->actingAs($this->user());

    $this->get(ReplaySessionResource::getUrl('view', ['record' => $session]))->assertSee('data-autoplay="false"', false);

    config()->set('session-replay.viewer.autoplay', true);

    $this->get(ReplaySessionResource::getUrl('view', ['record' => $session]))->assertSee('data-autoplay="true"', false);
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

it('asks about every selected recording before a bulk delete', function () {
    Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => $session === null || ! $session->user?->is_staff);

    $customers = $this->recording($this->user());
    $colleagues = $this->recording($this->user(['is_staff' => true]));

    $this->actingAs($this->user());

    Livewire::test(ListReplaySessions::class)->callTableBulkAction('delete', [$customers, $colleagues]);

    expect(ReplaySession::query()->pluck('id')->all())->toBe([$colleagues->id]);
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

it('offers the export to whoever may watch, a policy without a gate included', function () {
    Gate::policy(ReplaySession::class, ReplaySessionPolicy::class);

    $session = $this->recording();

    $this->actingAs($this->user(['is_staff' => true]));

    Livewire::test(ViewReplaySession::class, ['record' => $session->id])->assertActionVisible('export');
});

it('exports only the stylesheets the recording points at, as the core serves them, unless it points at none', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $mine = storeReplayAsset(ReplayAsset::STYLESHEET, 'body { color: rebeccapurple }');
    $theirs = storeReplayAsset(ReplayAsset::STYLESHEET, 'body { color: tomato } /* another workspace */');

    $events = fn (string ...$hashes): string => gzencode(json_encode(array_map(fn (string $hash, int $i): array => ['type' => 2, 'timestamp' => $i, 'data' => ['node' => ['type' => 2, 'tagName' => 'style', 'attributes' => ['_cssText' => '/*sr-asset:'.$hash.'*/'], 'childNodes' => [], 'id' => 1]]], $hashes, array_keys($hashes))));

    $referenced = $this->recording($user = $this->user());
    ReplaySessionAsset::query()->insert(['replay_session_id' => $referenced->id, 'hash' => $mine, 'created_at' => now()]);
    Storage::disk('replays')->put($referenced->chunks()->sole()->path, $events($mine, $theirs));

    // From before stylesheets were referenced: every stylesheet it names, as before.
    $old = $this->recording($user);
    Storage::disk('replays')->put($old->chunks()->sole()->path, $events($mine, $theirs));

    $this->actingAs($user);

    $export = fn (ReplaySession $session): array => json_decode(base64_decode(Livewire::test(ViewReplaySession::class, ['record' => $session->id])->callAction('export')->effects['download']['content']), true);

    expect(array_keys($export($referenced)['assets']))->toBe([$mine])
        ->and(array_keys($export($old)['assets']))->toEqualCanonicalizing([$mine, $theirs]);
});

it('leaves a chunk that is not a JSON array out of an export', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $session = $this->recording($user = $this->user());
    $chunk = $session->chunks()->sole();

    Storage::disk('replays')->put($chunk->path, gzencode('[]],"session":{"id":"forged"'));

    $this->actingAs($user);

    $response = Livewire::test(ViewReplaySession::class, ['record' => $session->id])->callAction('export');

    $response->assertFileDownloaded('session-replay-'.$session->id.'.json');
    expect(json_decode(base64_decode($response->effects['download']['content']), true)['session']['id'])->toBe($session->id);
});

/** A stylesheet or a shared snapshot on the disk and in the index, as the core's ingest stores them. */
function storeReplayAsset(string $kind, string $content): string
{
    $hash = hash('sha256', $content);
    $storage = app(ReplayStorage::class);
    $path = $kind === ReplayAsset::SNAPSHOT ? $storage->snapshotPath($hash) : $storage->assetPath($hash);

    Storage::disk('replays')->put($path, gzencode($content));
    ReplayAsset::query()->create(['hash' => $hash, 'kind' => $kind, 'path' => $path, 'bytes' => 1, 'raw_bytes' => strlen($content), 'last_seen_at' => now()]);

    return $hash;
}

it('exports the shared snapshots a recording points at, and the stylesheets inside them, so the file replays on its own', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $css = storeReplayAsset(ReplayAsset::STYLESHEET, 'body { color: rebeccapurple }');
    $tree = ['type' => 0, 'id' => 1, 'childNodes' => [['type' => 2, 'tagName' => 'style', 'attributes' => ['_cssText' => '/*sr-asset:'.$css.'*/'], 'childNodes' => [], 'id' => 2]]];
    $page = storeReplayAsset(ReplayAsset::SNAPSHOT, json_encode($tree));
    $elsewhere = storeReplayAsset(ReplayAsset::SNAPSHOT, '{"type":0,"id":1,"childNodes":[],"another":"page"}');

    $session = $this->recording($user = $this->user());
    ReplaySessionAsset::query()->insert(['replay_session_id' => $session->id, 'hash' => $page, 'created_at' => now()]);

    Storage::disk('replays')->put($session->chunks()->sole()->path, gzencode(json_encode([
        ['type' => 4, 'timestamp' => 1, 'data' => ['href' => 'https://app.test/admin']],
        ['type' => 2, 'timestamp' => 2, 'data' => ['srSnapshot' => $page, 'initialOffset' => ['top' => 0, 'left' => 0]]],
        // A hash the recording does not point at opens nothing, as in the core's snapshot route.
        ['type' => 2, 'timestamp' => 3, 'data' => ['srSnapshot' => $elsewhere]],
    ])));

    $this->actingAs($user);

    $export = json_decode(base64_decode(Livewire::test(ViewReplaySession::class, ['record' => $session->id])->callAction('export')->effects['download']['content']), true);

    expect($export['snapshots'])->toBe([$page => $tree])
        ->and($export['assets'])->toBe([$css => 'body { color: rebeccapurple }'])
        ->and($export['chunks'][0][1]['data']['srSnapshot'])->toBe($page);
});

it('puts only stylesheets into an export, whatever an sr-asset placeholder names', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $snapshot = storeReplayAsset(ReplayAsset::SNAPSHOT, '{"type":0,"id":1,"childNodes":[],"another":"workspace"}');

    $session = $this->recording($user = $this->user());

    Storage::disk('replays')->put($session->chunks()->sole()->path, gzencode(json_encode([
        ['type' => 2, 'timestamp' => 2, 'data' => ['node' => ['type' => 2, 'tagName' => 'style', 'attributes' => ['_cssText' => '/*sr-asset:'.$snapshot.'*/'], 'childNodes' => [], 'id' => 1]]],
    ])));

    $this->actingAs($user);

    $export = json_decode(base64_decode(Livewire::test(ViewReplaySession::class, ['record' => $session->id])->callAction('export')->effects['download']['content']), true);

    expect($export['assets'])->toBe([])->and($export['snapshots'])->toBe([]);
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

it('finds this workspace\'s people however many namesakes other workspaces have', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    // More people called Ada than one batch of the search, none with a recording in Acme.
    User::query()->insert(array_map(fn (int $i): array => ['name' => 'Ada '.$i, 'email' => "ada{$i}@globex.example", 'password' => 'secret'], range(1, 600)));
    $theirs = $this->recording($this->user(['name' => 'Ada Globex']), team: $globex);
    $mine = $this->recording($this->user(['name' => 'Ada Acme']), team: $acme);

    Filament::setTenant($acme, isQuiet: true);

    $found = ReplaySessionResource::scope(ReplaySession::query())
        ->where(fn (Builder $query) => ReplaySessionResource::searchPeople($query, 'Ada'))
        ->pluck('id')
        ->all();

    expect($found)->toBe([$mine->id])->and(ReplaySession::query()->whereKey($theirs)->exists())->toBeTrue();
});

it('searches the list\'s own people in one query each, however large the people table is', function () {
    $acme = $this->team('Acme');

    // Far more namesakes than a batch of the people table, none with a recording in Acme.
    User::query()->insert(array_map(fn (int $i): array => ['name' => 'Ada '.$i, 'email' => "ada{$i}@globex.example", 'password' => 'secret'], range(1, 1200)));
    $mine = $this->recording($this->user(['name' => 'Ada Acme']), team: $acme);
    $this->recording($this->user(['name' => 'Bob Acme']), team: $acme);

    Filament::setTenant($acme, isQuiet: true);

    $peopleQueries = 0;
    DB::listen(function ($query) use (&$peopleQueries): void {
        if (str_contains($query->sql, 'like')) {
            $peopleQueries++;
        }
    });

    $found = ReplaySessionResource::scope(ReplaySession::query())
        ->where(fn (Builder $query) => ReplaySessionResource::searchPeople($query, 'Ada'))
        ->pluck('id')
        ->all();

    expect($found)->toBe([$mine->id])->and($peopleQueries)->toBe(1);
});

it('keeps an orWhere in visibleUsing() inside the workspace', function () {
    $acme = $this->team('Acme');
    $viewer = $this->user();

    $mine = $this->recording($viewer, team: $acme);
    $guestElsewhere = $this->recording(team: $this->team('Globex'), attributes: ['user_type' => null, 'user_id' => null]);
    $guestHere = $this->recording(team: $acme, attributes: ['user_type' => null, 'user_id' => null]);

    // Staff see their own recordings and guests'.
    SessionReplay::visibleUsing(fn (Builder $query, $viewer) => $query->where('user_id', (string) $viewer->getKey())->orWhereNull('user_id'));

    $this->actingAs($viewer);
    Filament::setTenant($acme, isQuiet: true);

    expect(ReplaySessionResource::scope(ReplaySession::query())->pluck('id')->all())->toEqualCanonicalizing([$mine->id, $guestHere->id])
        ->and(ReplaySession::query()->whereKey($guestElsewhere)->exists())->toBeTrue();
});

it('shows the Workspace column whenever the list holds every workspace, and loads the workspaces in one query', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $user = $this->user();

    foreach (['Acme', 'Globex', 'Initech', 'Umbrella', 'Hooli'] as $name) {
        $this->recording($user, team: $this->team($name));
    }

    $this->actingAs($user);

    DB::enableQueryLog();

    Livewire::test(ListReplaySessions::class)->assertTableColumnVisible('tenant_id')->assertSee(['Acme', 'Hooli']);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"teams"'))->count())->toBe(1);

    Filament::setTenant($acme = Team::query()->where('name', 'Acme')->sole(), isQuiet: true);

    expect(ReplaySessionResource::listsEveryWorkspace())->toBeFalse();

    SessionReplayPlugin::get()->scopeToTenant(false);

    expect(ReplaySessionResource::listsEveryWorkspace())->toBeTrue();
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

    foreach ([__DIR__.'/../../src', __DIR__.'/../../resources/views'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $source .= file_get_contents($file->getPathname());
        }
    }

    preg_match_all("/__\\('((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $source, $matches);

    // The three vitals ratings and the three devices reach __() through a variable. Keys of the core
    // (session-replay::viewer.*, the Assist and stage strings) come translated with it.
    $strings = array_unique([...array_filter(array_map('stripslashes', $matches[1]), fn (string $string): bool => ! str_contains($string, '::')), 'Good', 'Needs improvement', 'Poor']);

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

it('links a recording to the panel\'s watch page while the core\'s own pages are off', function () {
    $recording = $this->recording();
    $core = route('session-replay.show', ['session' => $recording->id]);

    expect($recording->url())->toBe($core);

    config()->set('session-replay.viewer.enabled', false);

    $panelPage = ReplaySessionResource::getUrl('view', ['record' => $recording]);

    expect($recording->url())->toBe($panelPage)->and($panelPage)->not->toBe($core);

    // The log context of a recorded request gets the same link.
    Filament::setCurrentPanel(null);
    $this->withUnencryptedCookie('session_replay_id', $recording->id)->get('/admin/login')->assertOk();

    expect(Context::get('session_replay_url'))->toBe($panelPage);

    // A slug set on the plugin reaches a route name built for the panel from outside it.
    SessionReplayPlugin::get()->slug('replays');

    expect(ReplaySessionResource::getRouteBaseName(Filament::getPanel('admin')))->toBe('filament.admin.resources.replays');
});

it('filters the list to one person, named like the list names them, and deep-links to it', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $grace = $this->user(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    $hers = $this->recording($grace);
    $other = $this->recording($this->user(['name' => 'Alan Turing']));

    $this->actingAs($grace);

    Livewire::test(ListReplaySessions::class)
        ->filterTable('person', ['type' => $grace->getMorphClass(), 'id' => (string) $grace->getKey()])
        ->assertCanSeeTableRecords([$hers])->assertCanNotSeeTableRecords([$other])
        ->assertSee('Person: grace@example.com')
        ->resetTableFilters()
        ->filterTable('person', ['id' => (string) $grace->getKey()])
        ->assertCanSeeTableRecords([$hers])->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('person', ['type' => 'App\\Models\\Admin', 'id' => (string) $grace->getKey()])
        ->assertCanNotSeeTableRecords([$hers, $other])
        ->assertSee('Person: #'.$grace->getKey())
        ->resetTableFilters()
        // No recording yet: named from their model all the same.
        ->filterTable('person', ['type' => $grace->getMorphClass(), 'id' => (string) $this->user(['email' => 'new@example.com'])->getKey()])
        ->assertCanNotSeeTableRecords([$hers, $other])
        ->assertSee('Person: new@example.com');

    $url = ReplaySessionResource::getUrlForPerson($grace);

    expect($url)->toStartWith(ReplaySessionResource::getUrl())
        ->and(urldecode($url))->toContain('filters[person][type]='.$grace->getMorphClass(), 'filters[person][id]='.$grace->getKey());

    $this->get($url)->assertOk();

    // The table is loaded after the page: the query string reaches the filter through Livewire.
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)->test(ListReplaySessions::class)
        ->assertCanSeeTableRecords([$hers])->assertCanNotSeeTableRecords([$other])
        ->assertSee('Person: grace@example.com');
});

it('names the filtered person through userLabelUsing() whether or not they have a recording yet', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);
    SessionReplayPlugin::get()->userLabelUsing(fn (ReplaySession $session) => $session->user?->name);

    $grace = $this->user(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    $this->recording($grace);
    $ada = $this->user(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $this->actingAs($grace);

    Livewire::test(ListReplaySessions::class)
        ->filterTable('person', ['type' => $grace->getMorphClass(), 'id' => (string) $grace->getKey()])
        ->assertSee('Person: Grace Hopper')
        ->resetTableFilters()
        ->filterTable('person', ['type' => $ada->getMorphClass(), 'id' => (string) $ada->getKey()])
        ->assertSee('Person: Ada Lovelace')
        ->assertDontSee('Person: ada@example.com')
        ->resetTableFilters()
        // No model to name: the closure returns null and the label falls back to the key.
        ->filterTable('person', ['type' => 'App\\Models\\Admin', 'id' => '999'])
        ->assertSee('Person: #999');
});
