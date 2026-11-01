<?php

use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Packstub\SessionReplay\Filament\Livewire\AssistNotifier;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Support\Assist;

/** The toasts sent so far, as Filament's own notifications component pulls them (which empties the list). */
function sentToasts(): Collection
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotificationsFromSession();

    return $component->notifications->values()->map(fn (Notification $notification): array => $notification->toArray());
}

beforeEach(function () {
    $this->staffGate();
});

it('tells an agent about each new request once, with a toast that can claim it', function () {
    $this->withoutCalls();
    $this->signIn($this->staff());

    $notifier = Livewire::test(AssistNotifier::class)->call('check');
    Notification::assertNotNotified();

    $request = $this->waiting($this->user(['email' => 'ada@example.com']), 'Where is my <i>invoice</i>?');

    $notifier->call('check');

    $sent = sentToasts()->firstWhere('id', 'session-replay-assist-'.$request->id);

    // The visitor's words as text: Filament renders a body as sanitized HTML, so it is escaped first.
    expect($sent)->toMatchArray(['title' => 'ada@example.com asked for help', 'body' => '“Where is my &lt;i&gt;invoice&lt;/i&gt;?”'])
        ->and(collect($sent['actions'])->pluck('name')->all())->toBe(['claim', 'requests']);

    // Once per browser session.
    $notifier->call('check');
    Notification::assertNotNotified();

    // The toast's Claim comes back as an event.
    $notifier->dispatch(AssistNotifier::CLAIM_EVENT, id: $request->id)->assertNotified('Claimed');

    expect($request->refresh()->status)->toBe(AssistRequest::CLAIMED);
});

it('claims from the toast and goes on to the stage with Calls, or says who was faster', function () {
    $this->needsCalls();

    $first = $this->waiting();
    $this->signIn($agent = $this->staff());

    Livewire::test(AssistNotifier::class)
        ->dispatch(AssistNotifier::CLAIM_EVENT, id: $first->id)
        ->assertRedirect(ReplaySessionResource::getUrl('stage', ['assistRequest' => $first->id]));

    $this->signIn($this->staff(['name' => 'Bo']));

    Livewire::test(AssistNotifier::class)
        ->dispatch(AssistNotifier::CLAIM_EVENT, id: $first->id)
        ->assertNoRedirect()
        ->assertNotified('Not possible');

    expect($first->refresh()->agent_id)->toBe((string) $agent->id);
});

it('tells only agents who may take the request', function () {
    $this->signIn($this->user());

    $this->waiting();

    Livewire::test(AssistNotifier::class)->call('check');
    Notification::assertNotNotified();
});

it('puts the notification in the bell too when the panel has database notifications on', function () {
    $this->withoutCalls();
    $this->signIn($agent = $this->staff());
    Filament::getCurrentPanel()->databaseNotifications();

    $this->waiting($this->user(['email' => 'ada@example.com']));

    Livewire::test(AssistNotifier::class)->call('check');

    expect(DB::table('notifications')->where('notifiable_id', $agent->id)->count())->toBe(1)
        ->and(json_decode(DB::table('notifications')->value('data'), true)['title'])->toBe('ada@example.com asked for help');
});

it('keeps a burst to three toasts, the last one saying how many more wait', function () {
    $this->withoutCalls();
    $this->signIn($this->staff());

    foreach (range(1, 5) as $i) {
        $this->waiting($this->user(['email' => 'visitor'.$i.'@example.com']));
    }

    Livewire::test(AssistNotifier::class)->call('check');

    $sent = sentToasts();

    expect(session()->get('filament-session-replay.assist.told.admin'))->toHaveCount(5)
        ->and($sent)->toHaveCount(3)
        ->and($sent->last())->toMatchArray(['title' => 'visitor3@example.com asked for help', 'body' => '“The checkout button does nothing” +2 more waiting']);
});

it('renders the notifier on the panel\'s pages for agents only, and not with notifications off', function () {
    $this->signIn($this->staff());
    $this->get(ReplaySessionResource::getUrl())->assertSee('wire:poll.10s="check"', false);

    $this->signIn($this->user());
    $this->get('/admin')->assertDontSee('wire:poll.10s="check"', false);

    $this->signIn($this->staff());
    SessionReplayPlugin::get()->assistNotifications(false);
    $this->get(ReplaySessionResource::getUrl())->assertDontSee('wire:poll.10s="check"', false);
});

it('tells again about a request someone let go of', function () {
    $this->withoutCalls();
    $request = $this->waiting();

    $this->signIn($bo = $this->staff(['name' => 'Bo']));
    $notifier = Livewire::test(AssistNotifier::class)->call('check');
    Notification::assertNotified();

    $this->signIn($cy = $this->staff(['name' => 'Cy']));
    app(Assist::class)->claim($cy, $request);

    $this->signIn($bo);
    $notifier->call('check');
    Notification::assertNotNotified();

    app(Assist::class)->withdraw($cy, $request);
    $notifier->call('check');
    Notification::assertNotified();
});
