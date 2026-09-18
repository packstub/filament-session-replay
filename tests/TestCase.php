<?php

namespace Packstub\SessionReplay\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\SessionReplay\Filament\FilamentSessionReplayServiceProvider;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayServiceProvider;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Tests\Fixtures\AdminPanelProvider;
use Packstub\SessionReplay\Tests\Fixtures\Models\Team;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('replays');
        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        // Filament binds its own Livewire DataStore, so Support must register before Livewire (as package discovery orders them).
        return [
            SupportServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            SessionReplayServiceProvider::class,
            FilamentSessionReplayServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('session-replay.storage.disk', 'replays');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function user(array $attributes = []): User
    {
        return User::query()->create($attributes + ['name' => 'Ada Lovelace', 'email' => uniqid().'@example.com', 'password' => 'secret']);
    }

    protected function team(string $name = 'Acme'): Team
    {
        return Team::query()->create(['name' => $name]);
    }

    /**
     * A stored recording, uploaded through the real ingest endpoint.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $markers
     */
    protected function recording(?User $user = null, array $attributes = [], ?Team $team = null, array $markers = [], ?string $impersonator = null): ReplaySession
    {
        $id = (string) Str::uuid();
        $now = now()->getTimestampMs();

        $this->post(route('session-replay.ingest'), [
            'token' => ContextToken::for($user ?? $this->user(), $team, $impersonator, ['panel' => 'admin'])->encode(),
            'session' => $id,
            'seq' => 0,
            'meta' => json_encode([
                'url' => 'https://app.test/admin/orders',
                'viewport' => ['width' => 1440, 'height' => 900],
                'from' => $now - 1000,
                'to' => $now,
                'events' => 2,
                'markers' => $markers,
            ]),
            'events' => UploadedFile::fake()->createWithContent('events', gzencode(json_encode([
                ['type' => 4, 'timestamp' => $now - 1000, 'data' => ['href' => 'https://app.test/admin/orders']],
                ['type' => 2, 'timestamp' => $now - 990, 'data' => ['node' => ['type' => 0, 'childNodes' => [], 'id' => 1]]],
            ]))),
        ])->assertCreated();

        $session = ReplaySession::query()->findOrFail($id);

        if ($attributes !== []) {
            $session->forceFill($attributes)->save();
        }

        return $session;
    }
}
