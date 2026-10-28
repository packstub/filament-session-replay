<?php

namespace Packstub\SessionReplay\Tests;

use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Packstub\Calls\CallsServiceProvider;
use Packstub\FilamentCalls\FilamentCallsServiceProvider;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\Assist;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Tests\Fixtures\AdminPanelProvider;
use Packstub\SessionReplay\Tests\Fixtures\AssistPanelProvider;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/**
 * Assist on in the core (its routes register at boot), with packstub/calls and
 * packstub/filament-calls booted when they are installed (a dev install links
 * them, see AGENTS.md; they are only suggested by composer.json). Tests that
 * need the live view call needsCalls() and are skipped without them.
 */
abstract class AssistTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = array_values(array_filter(parent::getPackageProviders($app), fn (string $provider): bool => $provider !== AdminPanelProvider::class));

        return [
            ...$providers,
            ...(class_exists(CallsServiceProvider::class) ? [CallsServiceProvider::class] : []),
            ...(class_exists(FilamentCallsServiceProvider::class) ? [FilamentCallsServiceProvider::class] : []),
            AssistPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session-replay.assist.enabled', true);
    }

    protected function needsCalls(): void
    {
        if (! class_exists(CallsServiceProvider::class) || ! class_exists(FilamentCallsServiceProvider::class)) {
            $this->markTestSkipped('packstub/calls and packstub/filament-calls are not installed.');
        }
    }

    /** The queue alone, as without packstub/calls. */
    protected function withoutCalls(): void
    {
        config()->set('session-replay.assist.calls', false);
    }

    /** Staff may watch (and so help), customers may not. */
    protected function staffGate(): void
    {
        Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => (bool) $user->is_staff);
    }

    protected function staff(array $attributes = []): User
    {
        return $this->user($attributes + ['name' => 'Ana Support', 'is_staff' => true]);
    }

    /**
     * A recording made by this person's browser, with the write key only that browser holds.
     *
     * @return array{session: ReplaySession, token: string, key: string}
     */
    protected function browser(?User $user = null, array $attributes = []): array
    {
        $token = ContextToken::for($user ?? $this->user(), null, null, ['panel' => 'admin'])->encode();
        $key = bin2hex(random_bytes(16));
        $id = (string) Str::uuid();
        $now = now()->getTimestampMs();

        $this->post(route('session-replay.ingest'), [
            'token' => $token,
            'session' => $id,
            'key' => $key,
            'seq' => 0,
            'meta' => json_encode(['url' => 'https://app.test/admin/orders', 'viewport' => ['width' => 1440, 'height' => 900], 'from' => $now - 1000, 'to' => $now, 'events' => 2, 'markers' => []]),
            'events' => UploadedFile::fake()->createWithContent('events', gzencode(json_encode([
                ['type' => 4, 'timestamp' => $now - 1000, 'data' => ['href' => 'https://app.test/admin/orders']],
                ['type' => 3, 'timestamp' => $now - 900, 'data' => ['source' => 1]],
            ]))),
        ])->assertCreated();

        $session = ReplaySession::query()->findOrFail($id);

        if ($attributes !== []) {
            $session->forceFill($attributes)->save();
        }

        return ['session' => $session, 'token' => $token, 'key' => $key];
    }

    /** The visitor asks for help from the page of this recording. */
    protected function ask(array $browser, string $message = 'The checkout button does nothing'): TestResponse
    {
        return $this->postJson(route('session-replay.assist.store'), [
            'token' => $browser['token'],
            'session' => $browser['session']->id,
            'key' => $browser['key'],
            'message' => $message,
            'url' => 'https://app.test/admin/orders?page=2',
        ]);
    }

    /** A visitor's request, waiting. */
    protected function waiting(?User $visitor = null, string $message = 'The checkout button does nothing'): AssistRequest
    {
        $response = $this->ask($this->browser($visitor), $message)->assertCreated();

        // The visitor's routes are stateless: no session or guard is left behind for the agent's requests.
        return AssistRequest::query()->findOrFail($response->json('id'));
    }

    /** A visitor's request, claimed by this agent, who is signed in to the panel. */
    protected function claimedBy(User $agent): AssistRequest
    {
        $request = $this->waiting();
        $this->signIn($agent);
        app(Assist::class)->claim($agent, $request);

        return $request->refresh();
    }

    protected function signIn(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }
}
