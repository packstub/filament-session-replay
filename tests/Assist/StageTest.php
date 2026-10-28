<?php

use Illuminate\Testing\TestResponse;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
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

it('hosts the core\'s stage in the panel, with what its script needs and the panel\'s own Calls routes', function () {
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
        // Filament Calls' widget beside it, which polls kind=call only.
        ->assertSee('Starting a call from the Calls widget ends this assist.');

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
        ->and($config['calls']['routes']['poll'])->toBe(route('filament.admin.filament-calls.poll'))
        ->and($config['calls']['routes']['signal'])->toBe(route('filament.admin.filament-calls.signal', ['call' => '__ID__']))
        ->and($config['labels']['buttons']['control'])->toBe('Request control')
        ->and($config['csrf'])->toBeString();
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

it('says there is no live view when Calls is off, instead of a stage that never connects', function () {
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
