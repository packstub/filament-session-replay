<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\AssistRequests;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ListReplaySessions;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ViewReplaySession;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;

beforeEach(function () {
    $this->staffGate();
});

it('lists the visitors who wait for help to whoever the gate lets watch, nobody else', function () {
    $request = $this->waiting(message: '<b>Checkout</b> does nothing');

    $this->signIn($this->user());
    $this->get(ReplaySessionResource::getUrl('requests'))->assertForbidden();

    $this->signIn($this->staff());

    $this->get(ReplaySessionResource::getUrl('requests'))
        ->assertOk()
        ->assertSee('Help requests')
        // The visitor's words as text, never markup.
        ->assertSee('&lt;b&gt;Checkout&lt;/b&gt; does nothing', false)
        ->assertDontSee('<b>Checkout</b>', false)
        ->assertSee('/admin/orders?page=2')
        ->assertSee('wire:poll.5s', false);

    Livewire::test(AssistRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertTableColumnFormattedStateSet('status', 'Waiting', $request)
        ->assertTableActionVisible('claimAssist', $request)
        ->assertTableActionHidden('withdrawAssist', $request)
        ->assertTableActionHidden('endAssist', $request);
});

it('puts Help requests in the navigation with the number of visitors waiting as the badge', function () {
    $this->waiting();
    $this->waiting();

    $this->signIn($this->staff());

    $items = collect(ReplaySessionResource::getNavigationItems());

    expect($items)->toHaveCount(2)
        ->and($items->last()->getLabel())->toBe('Help requests')
        ->and($items->last()->getBadge())->toBe('2')
        ->and($items->last()->getUrl())->toBe(ReplaySessionResource::getUrl('requests'));

    $this->get(ReplaySessionResource::getUrl('requests'))->assertSee('Visitors waiting for help');
});

it('follows canAssistUsing(): a request the rule refuses is neither listed, counted nor claimable', function () {
    $request = $this->waiting();
    SessionReplay::canAssistUsing(fn ($agent, ?ReplaySession $session, AssistRequest $request): bool => false);

    $this->signIn($this->staff());

    expect(PanelAssist::waitingCount())->toBe(0);

    Livewire::test(AssistRequests::class)->assertCanNotSeeTableRecords([$request]);

    expect(PanelAssist::allows($request))->toBeFalse();
});

it('claims a request and goes on to the live stage with Calls, with a full page load', function () {
    $this->needsCalls();

    $request = $this->waiting();
    $this->signIn($agent = $this->staff());

    Livewire::test(AssistRequests::class)
        ->callTableAction('claimAssist', $request)
        ->assertRedirect(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]));

    expect($request->refresh())
        ->status->toBe(AssistRequest::CLAIMED)
        ->agent_id->toBe((string) $agent->id)
        ->agent_name->toBe('Ana Support');
});

it('claims into the queue alone without Calls, and says the app answers the request its own way', function () {
    $this->withoutCalls();

    $request = $this->waiting();
    $this->signIn($this->staff());

    Livewire::test(AssistRequests::class)
        ->callTableAction('claimAssist', $request)
        ->assertNoRedirect()
        ->assertNotified('Claimed')
        ->assertTableActionHidden('claimAssist', $request)
        ->assertTableActionHidden('openAssistStage', $request)
        ->assertTableActionVisible('endAssist', $request);

    expect($request->refresh()->status)->toBe(AssistRequest::CLAIMED);

    $this->get(ReplaySessionResource::getUrl('requests'))->assertSee('Without packstub/calls there is no live view');
});

it('withdraws a claim back into the list and ends a request', function () {
    $this->withoutCalls();

    $request = $this->waiting();
    $this->signIn($this->staff());

    Livewire::test(AssistRequests::class)
        ->callTableAction('claimAssist', $request)
        ->callTableAction('withdrawAssist', $request)
        ->assertNotified('Back in the list for someone else');

    expect($request->refresh())->status->toBe(AssistRequest::OPEN)->agent_id->toBeNull();

    Livewire::test(AssistRequests::class)
        ->callTableAction('claimAssist', $request)
        ->callTableAction('endAssist', $request)
        ->assertNotified('Ended.');

    expect($request->refresh())->status->toBe(AssistRequest::ENDED)->end_reason->toBe('ended_by_agent');

    Livewire::test(AssistRequests::class)->assertCanNotSeeTableRecords([$request]);
});

it('offers help on a live recording from the list and the watch page, then the stage', function () {
    $this->needsCalls();

    $live = $this->browser();
    $quiet = $this->browser(null, ['last_activity_at' => now()->subMinutes(5)]);
    $agent = $this->staff();
    $own = $this->browser($agent);

    $this->signIn($agent);

    Livewire::test(ListReplaySessions::class)
        ->assertTableActionVisible('offerAssist', $live['session'])
        ->assertTableActionHidden('offerAssist', $quiet['session'])
        // Nobody is offered their own visit.
        ->assertTableActionHidden('offerAssist', $own['session']);

    Livewire::test(ViewReplaySession::class, ['record' => $live['session']->id])
        ->assertActionVisible('offerAssist')
        ->callAction('offerAssist');

    $request = AssistRequest::query()->where('replay_session_id', $live['session']->id)->firstOrFail();

    expect($request)->opened_by->toBe(AssistRequest::BY_AGENT)->status->toBe(AssistRequest::OPEN)->agent_id->toBe((string) $agent->id);

    // The recording has a request going: Open live view instead of a second offer.
    Livewire::test(ViewReplaySession::class, ['record' => $live['session']->id])
        ->assertActionHidden('offerAssist')
        ->assertActionVisible('openAssistStageForSession')
        ->callAction('openAssistStageForSession')
        ->assertRedirect(ReplaySessionResource::getUrl('stage', ['assistRequest' => $request->id]));

    Livewire::test(ListReplaySessions::class)->assertTableActionVisible('openAssistStageForSession', $live['session']);

    // Another agent sees neither.
    $this->signIn($this->staff());

    Livewire::test(ViewReplaySession::class, ['record' => $live['session']->id])
        ->assertActionHidden('offerAssist')
        ->assertActionHidden('openAssistStageForSession');
});

it('offers no help without Calls, and nothing at all while Assist is off in the panel', function () {
    $live = $this->browser();
    $this->signIn($this->staff());

    $this->withoutCalls();

    Livewire::test(ListReplaySessions::class)->assertTableActionHidden('offerAssist', $live['session']);

    config()->set('session-replay.assist.calls', true);
    SessionReplayPlugin::get()->assist(false);

    Livewire::test(ListReplaySessions::class)->assertTableActionHidden('offerAssist', $live['session']);
    $this->get(ReplaySessionResource::getUrl('requests'))->assertForbidden();

    expect(ReplaySessionResource::getNavigationItems())->toHaveCount(1);
});

it('shows a Live now tab with the recordings heard from in the last two minutes, and when', function () {
    $live = $this->browser($this->user(['email' => 'live@example.com']));
    $quiet = $this->browser($this->user(['email' => 'quiet@example.com']), ['last_activity_at' => now()->subMinutes(5)]);
    $live['session']->forceFill(['last_activity_at' => now()->subSeconds(40)])->save();

    $this->signIn($this->staff());

    Livewire::test(ListReplaySessions::class)
        ->assertCanSeeTableRecords([$live['session'], $quiet['session']])
        ->set('activeTab', 'live')
        ->assertCanSeeTableRecords([$live['session']])
        ->assertCanNotSeeTableRecords([$quiet['session']])
        ->assertSee('last seen 40 s ago');
});

it('narrows the queue to the panel\'s current workspace in the query, unless told otherwise', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    $ours = $this->waiting();
    $theirs = $this->waiting();
    $ours->forceFill(['tenant_type' => $acme->getMorphClass(), 'tenant_id' => (string) $acme->getKey()])->save();
    $theirs->forceFill(['tenant_type' => $globex->getMorphClass(), 'tenant_id' => (string) $globex->getKey()])->save();

    $this->signIn($this->staff());

    expect(PanelAssist::queue()->pluck('id')->all())->toEqualCanonicalizing([$ours->id, $theirs->id]);

    PanelAssist::flush();
    Filament::setTenant($acme, isQuiet: true);

    expect(PanelAssist::queue()->pluck('id')->all())->toBe([$ours->id]);

    PanelAssist::flush();
    SessionReplayPlugin::get()->scopeToTenant(false);

    expect(PanelAssist::queue())->toHaveCount(2);
});
