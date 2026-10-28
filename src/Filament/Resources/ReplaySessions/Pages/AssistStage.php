<?php

namespace Packstub\SessionReplay\Filament\Resources\ReplaySessions\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Packstub\SessionReplay\Filament\Assist\PanelAssist;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;

/**
 * The live stage inside the panel: the core's stage script (stage.js and
 * stage.css from its script route, rrweb's Replayer in live mode and its own
 * Calls client, kind=assist) in the panel's layout, with the tools rendered as
 * the panel's own buttons. Beside it, Filament Calls' widget keeps polling
 * kind=call and never sees the assist call.
 *
 * A page of its own, not kept across wire:navigate: links to it are full page
 * loads, and leaving it leaves the call (the core's script does so on
 * livewire:navigating) while the request stays the agent's; Open live view on
 * the Requests page brings them back.
 *
 * Only the agent who holds the request (claimed, offered or live) opens it; the
 * gate, visibleUsing() and canAssist are asked again, as on the core's routes.
 */
class AssistStage extends Page
{
    protected static string $resource = ReplaySessionResource::class;

    protected string $view = 'filament-session-replay::assist.stage';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Locked]
    public string $requestId;

    public static function canAccess(array $parameters = []): bool
    {
        return PanelAssist::mayAssist();
    }

    public function mount(string $assistRequest): void
    {
        $model = AssistRequest::query()->with(['session', 'user'])->find(strtolower($assistRequest));

        // An id that is no request answers like one the agent may not see.
        abort_unless($model !== null && PanelAssist::allows($model), 403);
        abort_unless(PanelAssist::isMine($model), 403);

        if (! $model->isPending()) {
            Notification::make()->title(__('session-replay::viewer.assist.not_open'))->warning()->send();

            $this->redirect(PanelAssist::requestsUrl());

            return;
        }

        $this->requestId = $model->getKey();
    }

    public function getAssistRequest(): AssistRequest
    {
        return AssistRequest::query()->with(['session', 'user'])->findOrFail($this->requestId);
    }

    public function getTitle(): string|Htmlable
    {
        return __('session-replay::viewer.stage.title', ['person' => PanelAssist::personLabel($this->getAssistRequest())]);
    }

    public function getBreadcrumbs(): array
    {
        return [
            ReplaySessionResource::getUrl() => ReplaySessionResource::getBreadcrumb(),
            PanelAssist::requestsUrl() => __('session-replay::viewer.assist.title'),
            __('Live view'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('watchRecording')
                ->label(__('Watch recording'))
                ->icon(Heroicon::OutlinedPlay)
                ->color('gray')
                ->url(fn (): ?string => ($session = $this->getAssistRequest()->session) instanceof ReplaySession ? ReplaySessionResource::getUrl('view', ['record' => $session]) : null)
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($session = $this->getAssistRequest()->session) instanceof ReplaySession && ReplaySessionResource::canView($session)),
            Action::make('requests')
                ->label(__('session-replay::viewer.assist.title'))
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(PanelAssist::requestsUrl()),
        ];
    }

    /** @return array<string, mixed> */
    public function getStageConfig(): array
    {
        return PanelAssist::stageConfig($this->getAssistRequest(), PanelAssist::agent());
    }
}
