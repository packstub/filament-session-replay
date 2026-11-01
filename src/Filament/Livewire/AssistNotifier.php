<?php

namespace Packstub\SessionReplay\Filament\Livewire;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Packstub\SessionReplay\Exceptions\AssistRefused;
use Packstub\SessionReplay\Filament\Assist\AssistActions;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\View\SessionReplayIconAlias as Icon;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\SessionReplayPlugin;

/**
 * On every page of a panel with Assist, for someone who may help: asks the
 * core's queue every few seconds and tells the agent about each visitor who
 * started waiting, once per browser session, with a toast (and in the bell too
 * when the panel has database notifications on). The notification's Claim
 * comes back here as a browser event and claims through the core.
 *
 * No query of its own: the queue is the core's (the gate, visibleUsing(),
 * canAssist, tenantUsing()), narrowed to the panel's workspace.
 *
 * @internal Rendered by the plugin's BODY_END render hook.
 */
class AssistNotifier extends Component
{
    /** How often the queue is asked; Livewire slows it down in a hidden tab. */
    public const POLL = '10s';

    /** At most this many toasts at once (a burst says "and N more" in the last one). */
    public const BURST = 3;

    public const CLAIM_EVENT = 'filament-session-replay-assist-claim';

    public function check(): void
    {
        if (! PanelAssist::mayAssist()) {
            return;
        }

        $key = $this->sessionKey();
        $told = (array) session()->get($key, []);
        $waiting = PanelAssist::queue()->filter(fn (AssistRequest $request): bool => PanelAssist::isWaiting($request))->values();
        $new = $waiting->reject(fn (AssistRequest $request): bool => in_array($request->getKey(), $told, true))->values();

        // Only the ids still waiting are kept: a request someone let go of (back in the list) is news again.
        session()->put($key, $waiting->modelKeys());

        foreach ($new->take(static::BURST) as $index => $request) {
            $more = $index === static::BURST - 1 ? $new->count() - static::BURST : 0;

            $this->notify($request, $more);
        }
    }

    /** The notification's Claim button. */
    #[On(self::CLAIM_EVENT)]
    public function claim(string $id): void
    {
        $request = AssistRequest::query()->with(['session', 'user'])->find(strtolower($id));

        if ($request === null || ! PanelAssist::allows($request)) {
            PanelAssist::refused(new AssistRefused(403, __('session-replay::viewer.assist.not_allowed'), 'not_allowed'));

            return;
        }

        AssistActions::claimRequest($request, $this);
    }

    protected function notify(AssistRequest $request, int $more = 0): void
    {
        $agent = PanelAssist::agent();
        $person = PanelAssist::personLabel($request);
        // The visitor's own words and the person's label are text, never markup (Filament renders a body as sanitized HTML).
        $body = filled($request->message)
            ? '“'.Str::limit((string) $request->message, 140).'”'
            : ($request->page_url !== null ? __('On :page', ['page' => $request->page_url]) : null);

        if ($more > 0) {
            $body = trim(($body ?? '').' '.__('+:count more waiting', ['count' => $more]));
        }

        $notification = Notification::make('session-replay-assist-'.$request->getKey())
            ->title(e(__(':person asked for help', ['person' => $person])))
            ->body($body === null ? null : e($body))
            ->icon(AssistActions::icon(Icon::ASSIST_REQUESTED, Heroicon::OutlinedLifebuoy))
            ->iconColor('warning')
            ->duration(20000)
            ->actions([
                Action::make('claim')
                    ->label(__('session-replay::viewer.assist.claim'))
                    ->button()
                    ->dispatch(static::CLAIM_EVENT, ['id' => $request->getKey()])
                    ->close(),
                Action::make('requests')
                    ->label(__('View requests'))
                    ->link()
                    ->url(PanelAssist::requestsUrl()),
            ]);

        $notification->send();

        // In the bell as well, where the panel keeps notifications; the toast alone otherwise.
        if ($agent !== null && Filament::getCurrentPanel()?->hasDatabaseNotifications() && method_exists($agent, 'notify')) {
            $notification->sendToDatabase($agent, isEventDispatched: true);
        }
    }

    protected function sessionKey(): string
    {
        return 'filament-session-replay.assist.told.'.Filament::getCurrentPanel()?->getId();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div wire:init="check" wire:poll.10s="check" hidden></div>
        BLADE;
    }

    /** @internal Whether the plugin renders the notifier on this page. */
    public static function shouldRender(SessionReplayPlugin $plugin): bool
    {
        return $plugin->hasAssistNotifications() && PanelAssist::mayAssist();
    }
}
