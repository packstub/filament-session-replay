<?php

use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Support\Assist;

/** The stage's config, as the view writes it for the core's stage.js. */
function stageConfig(TestResponse $response): array
{
    preg_match('/data-config="([^"]*)"/', $response->getContent(), $match);

    return json_decode(html_entity_decode($match[1] ?? '{}', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->staffGate();
});

it('hosts the core\'s stage in the panel, with what its script needs and the panel\'s own Holler routes', function () {
    $this->needsCalls();

    $request = $this->claimedBy($agent = $this->staff());

    $response = $this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]))
        ->assertOk()
        ->assertSee('Live: ')
        ->assertSee(SessionReplay::scriptUrl('stage.js'), false)
        ->assertSee(SessionReplay::scriptUrl('stage.css'), false)
        ->assertSee('data-session-replay-stage', false)
        ->assertSee('wire:ignore', false)
        // The panel's own buttons, which the script finds by data-action and labels through data-label.
        ->assertSee('data-action="control"', false)
        ->assertSee('data-action="end"', false)
        ->assertSee('fi-btn', false)
        ->assertSee('fi-btn-group', false)
        ->assertSee('data-label', false)
        ->assertSee('The checkout button does nothing')
        // Filament Holler's widget beside it, which polls kind=call only.
        ->assertSee('Starting a call from the Holler widget ends this assist. Put it on hold first to keep it.');

    $config = stageConfig($response);

    expect($config['request'])->toMatchArray(['id' => $request->id, 'status' => 'claimed', 'mine' => true])
        ->and($config['routes'])->toBe([
            'status' => route('session-replay.assist.status', $request->id),
            'asks' => route('session-replay.assist.asks', $request->id),
            'end' => route('session-replay.assist.end', $request->id),
            'withdraw' => route('session-replay.assist.withdraw', $request->id),
            'list' => ReplaySessionResource::getUrl('requests'),
        ])
        ->and($config['calls']['available'])->toBeTrue()
        ->and($config['calls']['routes']['poll'])->toBe(route('filament.admin.filament-holler.poll'))
        ->and($config['calls']['routes']['signal'])->toBe(route('filament.admin.filament-holler.signal', ['call' => '__ID__']))
        ->and($config['labels']['buttons']['control'])->toBe('Request control')
        ->and($config['calls']['pollMs'])->toBe(1000)
        ->and($config['csrf'])->toBeString();

    // As often as Holler says.
    config()->set('holler.poll.active', 2500);

    expect(stageConfig($this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id])))['calls']['pollMs'])->toBe(2500);
});

/** The markup of the stage without its data-config attribute, whose labels JSON repeats every string of the page. */
function stageMarkup(TestResponse $response): string
{
    return preg_replace('/data-config="[^"]*"/', '', $response->getContent());
}

/** The opening tag and content of the panel button with this data-action. */
function stageButton(string $markup, string $action): ?string
{
    return preg_match('/<button\b[^>]*\bdata-action="'.preg_quote($action, '/').'"[^>]*>.*?<\/button>/s', $markup, $match) === 1 ? $match[0] : null;
}

it('has the pointer toggle and Put on hold as panel buttons, over the panel\'s own Holler hold and resume routes', function () {
    $this->needsCalls();

    $request = $this->claimedBy($this->staff());

    $response = $this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]))->assertOk();
    $markup = stageMarkup($response);

    // The strings are looked for in the rendered elements: the config's labels would satisfy a plain assertSee.
    expect($markup)->not->toContain('data-config=');

    $pointer = stageButton($markup, 'pointer');
    $hold = stageButton($markup, 'hold');

    // On by default (the core's script keeps it pressed), a panel button with its label beside the icon.
    expect($pointer)->not->toBeNull()
        ->toContain('fi-btn')
        ->toContain('aria-pressed="true"')
        ->toMatch('/<span data-label>\s*Show my pointer\s*<\/span>/')
        ->toContain('<svg')
        // Hidden until the script knows Holler's hold routes are there.
        ->and($hold)->not->toBeNull()
        ->toContain('fi-btn')
        ->toMatch('/\shidden\b/')
        ->toContain('aria-pressed="false"')
        ->toMatch('/<span data-label>\s*Put on hold\s*<\/span>/')
        // Where the script writes why each is disabled.
        ->and($markup)->toContain('data-why="hold"')
        ->toContain('data-why="pointer"');

    $routes = stageConfig($response)['calls']['routes'];

    expect($routes['hold'])->toBe(route('filament.admin.filament-holler.hold', ['call' => '__ID__']))
        ->and($routes['resume'])->toBe(route('filament.admin.filament-holler.resume', ['call' => '__ID__']));
});

it('has a badge for every phase the core\'s stage reports, On hold among them', function () {
    $this->needsCalls();

    $request = $this->claimedBy($this->staff());
    $markup = stageMarkup($this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]))->assertOk());

    preg_match_all('/<[a-z]+\b[^>]*\bdata-phase-badge="([a-z_]+)"[^>]*>(.*?)<\/[a-z]+>/s', $markup, $badges, PREG_SET_ORDER);
    $labels = collect($badges)->mapWithKeys(fn (array $badge) => [$badge[1] => trim(strip_tags($badge[2]))]);

    // stagePhase() in the core's lib/assist-stage.js.
    expect($labels->keys()->sort()->values()->all())->toBe(['connecting', 'ended', 'hidden', 'live', 'on_hold', 'reconnecting', 'unavailable', 'waiting'])
        ->and($labels['on_hold'])->toBe('On hold')
        ->and($labels['live'])->toBe('Live');

    // Shown only in its phase: the badge carries the Alpine condition the stage's event drives.
    $onHold = collect($badges)->first(fn (array $badge) => $badge[1] === 'on_hold')[0];

    expect($onHold)->toContain('x-show="phase === \'on_hold\'"')
        ->toContain('fi-badge');
});

it('opens the stage to the agent who holds the request only, asking the gate and canAssist again', function () {
    $this->needsCalls();

    $request = $this->claimedBy($agent = $this->staff());
    $url = ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]);

    $this->signIn($this->staff());
    $this->get($url)->assertForbidden();

    $this->signIn($this->user());
    $this->get($url)->assertForbidden();

    $this->signIn($agent);
    $this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => '00000000-0000-4000-8000-000000000000']))->assertForbidden();

    // The same key under another model type is someone else (Admin #12 is not User #12).
    $this->get($url)->assertOk();
    AssistRequest::query()->whereKey($request->id)->update(['agent_type' => 'App\\Models\\Admin']);
    $this->get($url)->assertForbidden();
    AssistRequest::query()->whereKey($request->id)->update(['agent_type' => $agent->getMorphClass()]);

    SessionReplay::canAssistUsing(fn () => false);
    $this->get($url)->assertForbidden();
});

it('sends the agent back to the requests from the stage of a request that is over', function () {
    $this->needsCalls();

    $request = $this->claimedBy($agent = $this->staff());
    app(Assist::class)->end($agent, $request);

    $this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]))
        ->assertRedirect(ReplaySessionResource::getUrl('requests'));
});

it('says there is no live view when Holler is off, instead of a stage that never connects', function () {
    $this->needsCalls();

    $request = $this->claimedBy($this->staff());
    $this->withoutCalls();

    $config = stageConfig($this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]))->assertOk());

    expect($config['calls'])->toMatchArray(['available' => false, 'routes' => null]);
});

it('keeps the stage out of the recording, like every page of the resource', function () {
    $this->needsCalls();

    $request = $this->claimedBy($this->staff());

    $this->get(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]))
        ->assertOk()
        ->assertDontSee(SessionReplay::scriptUrl('recorder.js'), false);
});

it('sends the core\'s mirror policy with the live view, and loads the page in full when wire:navigate brings it', function () {
    $request = $this->claimedBy($this->staff());
    $url = ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]);
    $policy = app(Assist::class)->mirrorPolicy();

    // The mirror inherits the page's policy: images, styles, fonts and media only from the app.
    expect($policy)->toContain('img-src');

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Security-Policy', $policy)
        // A header on a wire:navigate fetch applies to nothing: the page reloads itself in full, and leaves in full.
        ->assertSee('data-fsr-stage-document', false);

    $this->get($url, ['X-Livewire-Navigate' => '1'])->assertHeader('Content-Security-Policy', $policy);

    // "*" in assist.mirror_sources: no policy.
    config()->set('session-replay.assist.mirror_sources', ['*']);

    $this->get($url)->assertOk()->assertHeaderMissing('Content-Security-Policy');
});

it('keeps a workspace\'s request without a recording on the stage of the agent who took it, and takes it off them once they may not help', function () {
    $this->withoutCalls();
    $acme = $this->team('Acme');

    $request = $this->waiting();
    $request->forceFill(['replay_session_id' => null, 'tenant_type' => $acme->getMorphClass(), 'tenant_id' => (string) $acme->getKey()])->save();

    $this->signIn($agent = $this->staff());
    Filament::setTenant($acme, isQuiet: true);
    app(Assist::class)->claim($agent, $request->refresh(), PanelAssist::workspace());

    // The stage's status poll is the core's route, outside the panel and its workspace: holding the request is enough.
    $this->getJson(route('session-replay.assist.status', $request->id))->assertOk()->assertJsonPath('status', 'claimed');

    // The agent loses the right to help: the poll takes the request off them, back to the list, and the stage ends
    // ("you may no longer help", the core's words; the panel's Over badge).
    SessionReplay::canAssistUsing(fn () => false);

    $this->getJson(route('session-replay.assist.status', $request->id))->assertForbidden();

    expect($request->refresh()->status)->toBe(AssistRequest::OPEN)
        ->and(__('session-replay::viewer.stage.end_reasons.not_allowed'))->toBe('you may no longer help');
});
