<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Support\ContextToken;

function panelRecorderConfig(string $html): array
{
    preg_match('/window\.__sessionReplay=(\{.*?\});<\/script>/s', $html, $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

it('records the panel\'s pages with the panel\'s user, id and properties', function () {
    $user = $this->user();

    $html = $this->actingAs($user)->get('/admin')->assertOk()->getContent();
    $token = ContextToken::decode(panelRecorderConfig($html)['token'] ?? null);

    expect($html)->toContain('scripts/recorder.js')
        ->and($token?->userId)->toBe((string) $user->id)
        ->and($token->properties)->toBe(['panel' => 'admin', 'release' => '1.2.3'])
        ->and($token->tenantId)->toBeNull();
});

it('leaves the login page alone while guests are not recorded', function () {
    $this->get('/admin/login')->assertOk()->assertDontSee('__sessionReplay', false);
});

it('can be told not to record, for everyone or per person', function () {
    $this->actingAs($staff = $this->user(['is_staff' => true]));

    SessionReplayPlugin::get()->record(false);
    expect((string) SessionReplayPlugin::get()->recorder())->toBe('');

    SessionReplayPlugin::get()->record(fn ($user) => ! $user->is_staff);
    expect((string) SessionReplayPlugin::get()->recorder())->toBe('');

    $this->actingAs($this->user());
    expect((string) SessionReplayPlugin::get()->recorder())->toContain('__sessionReplay');
});

it('signs the current workspace and the impersonator into the token', function () {
    $this->actingAs($this->user());

    Filament\Facades\Filament::setTenant($team = $this->team(), isQuiet: true);
    SessionReplayPlugin::get()->impersonatorUsing(fn () => 7);

    $token = ContextToken::decode(panelRecorderConfig((string) SessionReplayPlugin::get()->recorder())['token']);

    expect($token->tenantId)->toBe((string) $team->id)->and($token->impersonatorId)->toBe('7');
});

it('records the workspace but not the person or the impersonator with privacy.anonymous', function () {
    config()->set('session-replay.privacy.anonymous', true);

    $this->actingAs($this->user());

    Filament\Facades\Filament::setTenant($team = $this->team(), isQuiet: true);
    SessionReplayPlugin::get()->impersonatorUsing(fn () => 7);

    $token = ContextToken::decode(panelRecorderConfig((string) SessionReplayPlugin::get()->recorder())['token']);

    expect($token->userId)->toBeNull()
        ->and($token->userType)->toBeNull()
        ->and($token->impersonatorId)->toBeNull()
        ->and($token->tenantId)->toBe((string) $team->id)
        ->and($token->pseudonym)->toHaveLength(32)
        ->and($token->properties)->toBe(['panel' => 'admin', 'release' => '1.2.3']);
});

it('leaves the panel\'s password-reset and verification pages out by route name, whatever their URL', function () {
    config()->set('session-replay.guests', true);

    $this->get('/admin/account/forgot')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('/admin/login')->assertOk()->assertSee('__sessionReplay', false);

    // The names Filament gives these pages: if it renames one, the default patterns need to follow.
    foreach ([
        'filament.admin.auth.password-reset.request',
        'filament.admin.auth.password-reset.reset',
        'filament.admin.auth.email-verification.prompt',
        'filament.admin.auth.email-verification.verify',
        'filament.admin.auth.email-change-verification.verify',
        'filament.admin.auth.email-change-verification.block-verification',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("Filament registers no {$name} route")
            ->and(Str::is(config('session-replay.except_routes'), $name))->toBeTrue("{$name} is not in except_routes");
    }

    // Without the route names, the page's URL alone would not keep the recorder out.
    config()->set('session-replay.except_routes', []);

    $this->get('/admin/account/forgot')->assertOk()->assertSee('__sessionReplay', false);
});

it('does not record the pages recordings are watched on', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $session = $this->recording($user = $this->user());

    $this->actingAs($user);

    $this->get(ReplaySessionResource::getUrl())->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get(ReplaySessionResource::getUrl('view', ['record' => $session]))->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('/admin')->assertOk()->assertSee('__sessionReplay', false);
});
