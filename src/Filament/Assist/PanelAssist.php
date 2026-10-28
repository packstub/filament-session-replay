<?php

namespace Packstub\SessionReplay\Filament\Assist;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Packstub\Calls\Calls;
use Packstub\SessionReplay\Exceptions\AssistRefused;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\AssistRequests;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages\AssistStage;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Support\Assist;

/**
 * Assist inside a panel: who the agent is (the panel's user), what they may see
 * (the core's queue, narrowed to the panel's workspace like the recordings list),
 * where the stage is, and what its script needs. The core's Assist service does
 * the work and asks the gate, visibleUsing() and canAssist on every call; nothing
 * here decides who may help on its own.
 *
 * @internal Used by the Sessions resource, its pages, the notifier and the actions.
 */
class PanelAssist
{
    /** Assist is on in the core and in this panel's plugin, and the panel lists recordings. */
    public static function enabled(): bool
    {
        $panel = Filament::getCurrentPanel();
        $plugin = SessionReplayPlugin::get();

        return app(Assist::class)->enabled()
            && $panel !== null
            && $panel->hasPlugin($plugin->getId())
            && $panel->getPlugin($plugin->getId()) === $plugin
            && $plugin->hasAssist()
            && in_array(ReplaySessionResource::class, $panel->getResources(), true);
    }

    public static function agent(): ?Authenticatable
    {
        $user = Filament::auth()->user();

        return $user instanceof Authenticatable ? $user : null;
    }

    /** The panel's user may help at all: the gate (or a ReplaySession policy) lets them watch recordings. */
    public static function mayAssist(): bool
    {
        return static::enabled() && static::agent() !== null && ReplaySessionResource::canViewAny();
    }

    /** packstub/calls is installed and on: a live stage and offers of help exist. */
    public static function hasLiveView(): bool
    {
        return app(Assist::class)->callsAvailable();
    }

    /**
     * The requests this agent may see that wait or are theirs (the core's queue: the gate, visibleUsing(),
     * canAssist and tenantUsing()), inside the panel's current workspace when the resource is scoped to it.
     * Read once per HTTP request: the navigation badge, the table and the notifier share it.
     *
     * @return Collection<int, AssistRequest>
     */
    public static function queue(): Collection
    {
        $agent = static::agent();

        if ($agent === null || ! static::enabled()) {
            return new Collection;
        }

        $key = 'filament-session-replay.assist.queue.'.Filament::getCurrentPanel()?->getId().'|'.$agent->getAuthIdentifier();
        $queue = request()->attributes->get($key);

        if (! $queue instanceof Collection) {
            $tenant = Filament::getTenant();
            $queue = app(Assist::class)->queue($agent);

            if ($tenant instanceof Model && SessionReplayPlugin::get()->isScopedToTenant()) {
                $queue = $queue->filter(fn (AssistRequest $request): bool => $request->tenant_type === $tenant->getMorphClass() && $request->tenant_id === (string) $tenant->getKey())->values();
            }

            request()->attributes->set($key, $queue);
        }

        return $queue;
    }

    /** Forget the queue and the held requests read during this request (after a claim, or in tests). */
    public static function flush(): void
    {
        foreach (array_keys(request()->attributes->all()) as $key) {
            if (str_starts_with((string) $key, 'filament-session-replay.assist.')) {
                request()->attributes->remove($key);
            }
        }
    }

    /** Visitors waiting for someone to take their request: the navigation badge. */
    public static function waitingCount(): int
    {
        return static::queue()->filter(fn (AssistRequest $request): bool => static::isWaiting($request))->count();
    }

    /** A visitor's request nobody has taken yet. */
    public static function isWaiting(AssistRequest $request): bool
    {
        return $request->status === AssistRequest::OPEN && ! $request->openedByAgent();
    }

    public static function isMine(AssistRequest $request): bool
    {
        // The core's check: the same key, model type and guard as the claim stored.
        return app(Assist::class)->holds(static::agent(), $request);
    }

    /** Whether this agent may see and act on the request, as the core's agent routes ask it. */
    public static function allows(AssistRequest $request): bool
    {
        $agent = static::agent();

        return $agent !== null && static::enabled() && app(Assist::class)->visibleTo($request, $agent);
    }

    /** The request going on this recording, whoever holds it. */
    public static function pendingFor(ReplaySession $session): ?AssistRequest
    {
        return AssistRequest::query()->pending()->where('replay_session_id', $session->getKey())->latest()->first();
    }

    /** The recording is someone else's, live, and nobody is helping with it yet: Offer help. */
    public static function canOffer(ReplaySession $session): bool
    {
        $agent = static::agent();

        if ($agent === null || ! static::mayAssist() || ! static::hasLiveView() || ! $session->isLive()) {
            return false;
        }

        // Nobody is offered their own visit.
        if ($agent instanceof Model && $session->user_type === $agent->getMorphClass() && $session->user_id === (string) $agent->getKey()) {
            return false;
        }

        return static::pendingFor($session) === null && ReplaySessionResource::canView($session);
    }

    /** The request on this recording that this agent holds: Open live view. */
    public static function heldFor(ReplaySession $session): ?AssistRequest
    {
        $agent = static::agent();

        if ($agent === null) {
            return null;
        }

        // One query per HTTP request for the agent's requests going, not one per row of the recordings table.
        $key = 'filament-session-replay.assist.held.'.$agent->getAuthIdentifier();
        $held = request()->attributes->get($key);

        if (! $held instanceof Collection) {
            $held = app(Assist::class)->whereHolder(AssistRequest::query()->pending(), $agent)->whereNotNull('replay_session_id')->get()->keyBy('replay_session_id');
            request()->attributes->set($key, $held);
        }

        return $held->get($session->getKey());
    }

    public static function stageUrl(AssistRequest $request): string
    {
        return AssistStage::getUrl(['assistRequest' => $request->getKey()]);
    }

    public static function requestsUrl(): string
    {
        return AssistRequests::getUrl();
    }

    /** The person who asked (or was offered help), the way the recordings list names people. */
    public static function personLabel(AssistRequest $request): string
    {
        if ($request->session instanceof ReplaySession) {
            return SessionReplayPlugin::get()->userLabel($request->session);
        }

        if ($request->user_id === null) {
            return __('Guest');
        }

        return SessionReplayPlugin::get()->personLabel($request->user_type === null ? null : $request->user, $request->user_id);
    }

    /** A refusal from the core (taken, not allowed, guard mismatch…) as a panel notification. */
    public static function refused(AssistRefused $refused): void
    {
        Notification::make()
            ->title(__('Not possible'))
            ->body($refused->getMessage())
            ->danger()
            ->send();
    }

    /**
     * What the core's stage script reads from data-config: the request as the agent sees it, the core's agent
     * routes, Calls' routes (the panel's own when Filament Calls is on it, else the app's `Calls::routes()` under
     * `assist.calls_routes`) and the core's strings, so the stage says the same in a panel as in the built-in viewer.
     *
     * @return array<string, mixed>
     */
    public static function stageConfig(AssistRequest $request, Authenticatable $agent): array
    {
        $assist = app(Assist::class);
        $calls = $assist->callsAvailable();
        $routes = $calls ? static::callsRoutes() : null;

        return [
            'request' => $assist->presentForAgent($request, $agent),
            'routes' => [
                'status' => route('session-replay.assist.status', $request->id),
                'asks' => route('session-replay.assist.asks', $request->id),
                'end' => route('session-replay.assist.end', $request->id),
                'withdraw' => route('session-replay.assist.withdraw', $request->id),
                'list' => static::requestsUrl(),
            ],
            'calls' => [
                'available' => $calls,
                'routes' => $routes,
                'iceTransportPolicy' => $routes === null ? 'all' : app(Calls::class)->rtcConfiguration()['iceTransportPolicy'],
                'reconnectTimeout' => (int) config('packstub-calls.reconnect_timeout', 15) * 1000,
                'pollMs' => $assist->callsPollMs(),
            ],
            'video' => $assist->videoAllowed(),
            'csrf' => csrf_token(),
            'labels' => __('session-replay::viewer.stage'),
        ];
    }

    /** Filament Calls is on this panel: its widget polls kind=call beside the stage. */
    public static function panelHasCallsWidget(?Panel $panel = null): bool
    {
        $panel ??= Filament::getCurrentPanel();

        return $panel !== null && $panel->hasPlugin('filament-calls');
    }

    /** @return array<string, string>|null */
    protected static function callsRoutes(): ?array
    {
        $panel = Filament::getCurrentPanel();

        if ($panel !== null && static::panelHasCallsWidget($panel) && Route::has($panel->generateRouteName('filament-calls.poll'))) {
            $route = fn (string $name, array $parameters = []): string => $panel->route('filament-calls.'.$name, $parameters);
        } else {
            $prefix = (string) config('session-replay.assist.calls_routes', 'calls.');

            if (! Route::has($prefix.'poll') || ! Route::has($prefix.'signal')) {
                return null;
            }

            $route = fn (string $name, array $parameters = []): string => route($prefix.$name, $parameters);
        }

        return [
            'poll' => $route('poll'),
            'store' => $route('store'),
            'answer' => $route('answer', ['call' => '__ID__']),
            'decline' => $route('decline', ['call' => '__ID__']),
            'hangup' => $route('hangup', ['call' => '__ID__']),
            'signal' => $route('signal', ['call' => '__ID__']),
            'leave' => $route('leave', ['call' => '__ID__']),
            'ice' => $route('ice'),
        ];
    }
}
