<?php

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\Livewire\AssistNotifier;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\AssistRequests;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\AssistStage;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ListReplaySessions;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\ViewReplaySession;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Support\Assist;

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

it('claims a request and goes on to the live stage with Holler, with a full page load', function () {
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

it('claims into the queue alone without Holler, and says the app answers the request its own way', function () {
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

    $this->get(ReplaySessionResource::getUrl('requests'))->assertSee('Without packstub/holler there is no live view');
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

it('says why Offer help is refused a few minutes after the visitor said not now, in the core\'s words', function () {
    $this->needsCalls();

    $live = $this->browser();
    $this->signIn($this->staff());

    Livewire::test(ViewReplaySession::class, ['record' => $live['session']->id])->callAction('offerAssist');

    // The visitor's "Not now".
    AssistRequest::query()->where('replay_session_id', $live['session']->id)->firstOrFail()
        ->forceFill(['status' => AssistRequest::DECLINED, 'end_reason' => 'declined', 'ended_at' => now()])->save();

    Livewire::test(ViewReplaySession::class, ['record' => $live['session']->id])
        ->assertActionVisible('offerAssist')
        ->callAction('offerAssist')
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->title('Not possible')->body(__('session-replay::viewer.assist.declined_recently'))->danger());

    expect(__('session-replay::viewer.assist.declined_recently'))->toBe('The visitor said not now a moment ago. Offer again in a few minutes.')
        ->and(AssistRequest::query()->where('replay_session_id', $live['session']->id)->pending()->exists())->toBeFalse();
});

it('offers no help without Holler, and nothing at all while Assist is off in the panel', function () {
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
    // "40 s ago" is read when the table renders: a second ticking over in between would make it 41.
    $this->freezeTime();

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

it('keeps another workspace\'s request out of reach: no claim from the notifier\'s event, no stage', function () {
    $this->withoutCalls();
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    // A request without a recording: the per-recording gate never sees it, only the workspace rule does.
    $theirs = $this->waiting();
    $theirs->forceFill(['replay_session_id' => null, 'tenant_type' => $globex->getMorphClass(), 'tenant_id' => (string) $globex->getKey()])->save();

    $this->signIn($agent = $this->staff());
    Filament::setTenant($acme, isQuiet: true);

    expect(PanelAssist::allows($theirs->refresh()))->toBeFalse();

    // The browser can dispatch the toast's Claim with any id.
    Livewire::test(AssistNotifier::class)
        ->dispatch(AssistNotifier::CLAIM_EVENT, id: $theirs->id)
        ->assertNotified('Not possible');

    expect($theirs->refresh()->status)->toBe(AssistRequest::OPEN);

    // Held anyway (claimed in Globex's panel, say): the stage in Acme's panel still refuses it.
    app(Assist::class)->claim($agent, $theirs, $globex);
    PanelAssist::flush();

    expect(PanelAssist::allows($theirs->refresh()))->toBeFalse();
    Livewire::test(AssistStage::class, ['assistRequest' => $theirs->id])->assertForbidden();

    // Unscoped, the core's rules decide alone.
    SessionReplayPlugin::get()->scopeToTenant(false);
    PanelAssist::flush();

    expect(PanelAssist::allows($theirs))->toBeTrue();
});

it('lets an agent of the workspace take its request without a recording: listed, claimed from the toast, the stage opens', function () {
    $this->withoutCalls();
    $acme = $this->team('Acme');

    // Without a recording the core shows a workspace's request only to an agent placed in that workspace: the panel's.
    $ours = $this->waiting();
    $ours->forceFill(['replay_session_id' => null, 'tenant_type' => $acme->getMorphClass(), 'tenant_id' => (string) $acme->getKey()])->save();

    $this->signIn($agent = $this->staff());
    Filament::setTenant($acme, isQuiet: true);

    expect(PanelAssist::queue()->pluck('id')->all())->toBe([$ours->id])
        ->and(PanelAssist::allows($ours->refresh()))->toBeTrue();

    Livewire::test(AssistNotifier::class)
        ->dispatch(AssistNotifier::CLAIM_EVENT, id: $ours->id)
        ->assertNotified('Claimed');

    expect($ours->refresh()->status)->toBe(AssistRequest::CLAIMED)
        ->and($ours->agent_id)->toBe((string) $agent->id);
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
