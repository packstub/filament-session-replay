<?php

namespace Packstub\SessionReplay\Filament\Assist;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;
use Packstub\SessionReplay\Exceptions\AssistRefused;
use Packstub\SessionReplay\Filament\View\SessionReplayIconAlias as Icon;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Support\Assist;

/**
 * The Assist actions of the panel: on a request (claim, open the live view,
 * withdraw, end) and on a live recording (offer help, open the live view). Each
 * asks the core again (the gate, visibleUsing(), canAssist) through authorize()
 * and through the service itself, never only by being visible.
 *
 * The live view is a page of its own: Claim and Offer help go on to it with a
 * full page load, so it never arrives through wire:navigate.
 *
 * @internal
 */
class AssistActions
{
    public static function claim(): Action
    {
        return Action::make('claimAssist')
            ->label(__('session-replay::viewer.assist.claim'))
            ->icon(static::icon(Icon::ASSIST_CLAIM, Heroicon::OutlinedHandRaised))
            ->color('primary')
            ->button()
            ->visible(fn (AssistRequest $record): bool => PanelAssist::isWaiting($record))
            ->authorize(fn (AssistRequest $record): bool => PanelAssist::allows($record))
            ->action(fn (AssistRequest $record, Component $livewire) => static::claimRequest($record, $livewire));
    }

    /** Claim, then the stage (with Calls) or a note that the app answers the request its own way (without). */
    public static function claimRequest(AssistRequest $request, Component $livewire): void
    {
        $agent = PanelAssist::agent();

        if ($agent === null || ! PanelAssist::allows($request)) {
            PanelAssist::refused(new AssistRefused(403, __('session-replay::viewer.assist.not_allowed'), 'not_allowed'));

            return;
        }

        try {
            app(Assist::class)->claim($agent, $request);
        } catch (AssistRefused $refused) {
            PanelAssist::refused($refused);

            return;
        }

        PanelAssist::flush();

        if (! PanelAssist::hasLiveView()) {
            Notification::make()
                ->title(__('Claimed'))
                ->body(__('The request is yours. Reach the person through your own channels, then end it here.'))
                ->success()
                ->send();

            return;
        }

        $livewire->redirect(PanelAssist::stageUrl($request));
    }

    public static function openStage(): Action
    {
        return Action::make('openAssistStage')
            ->label(__('session-replay::viewer.assist.open_stage'))
            ->icon(static::icon(Icon::ASSIST_OPEN_STAGE, Heroicon::OutlinedSignal))
            ->color('success')
            ->button()
            ->visible(fn (AssistRequest $record): bool => PanelAssist::hasLiveView() && PanelAssist::isMine($record) && $record->isPending())
            ->authorize(fn (AssistRequest $record): bool => PanelAssist::allows($record))
            ->action(fn (AssistRequest $record, Component $livewire) => $livewire->redirect(PanelAssist::stageUrl($record)));
    }

    public static function withdraw(): Action
    {
        return Action::make('withdrawAssist')
            ->label(__('session-replay::viewer.assist.withdraw'))
            ->icon(static::icon(Icon::ASSIST_WITHDRAW, Heroicon::OutlinedArrowUturnLeft))
            ->color('gray')
            ->visible(fn (AssistRequest $record): bool => PanelAssist::isMine($record) && in_array($record->status, [AssistRequest::OPEN, AssistRequest::CLAIMED], true))
            ->authorize(fn (AssistRequest $record): bool => PanelAssist::allows($record))
            ->action(function (AssistRequest $record): void {
                static::run(fn () => app(Assist::class)->withdraw(PanelAssist::agent(), $record), $record->openedByAgent() ? __('Offer withdrawn') : __('Back in the list for someone else'));
            });
    }

    public static function end(): Action
    {
        return Action::make('endAssist')
            ->label(__('session-replay::viewer.assist.end'))
            ->icon(static::icon(Icon::ASSIST_END, Heroicon::OutlinedXCircle))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('End this assist?'))
            ->modalDescription(__('The live view and the call end for both of you.'))
            ->visible(fn (AssistRequest $record): bool => PanelAssist::isMine($record) && in_array($record->status, [AssistRequest::CLAIMED, AssistRequest::ACTIVE], true))
            ->authorize(fn (AssistRequest $record): bool => PanelAssist::allows($record))
            ->action(function (AssistRequest $record): void {
                static::run(fn () => app(Assist::class)->end(PanelAssist::agent(), $record), __('session-replay::viewer.assist.flash.ended'));
            });
    }

    /** On a live recording: offer help to its person, then the stage where the agent waits for their Accept. */
    public static function offer(): Action
    {
        return Action::make('offerAssist')
            ->label(__('session-replay::viewer.assist.offer'))
            ->icon(static::icon(Icon::ASSIST_OFFER, Heroicon::OutlinedLifebuoy))
            ->color('primary')
            ->visible(fn (ReplaySession $record): bool => PanelAssist::canOffer($record))
            ->authorize(fn (ReplaySession $record): bool => PanelAssist::mayAssist())
            ->requiresConfirmation()
            ->modalIcon(static::icon(Icon::ASSIST_OFFER, Heroicon::OutlinedLifebuoy))
            ->modalHeading(__('Offer help?'))
            ->modalDescription(fn (ReplaySession $record): string => __(':person sees your offer on their next upload and may accept it or say not now.', ['person' => SessionReplayPlugin::get()->userLabel($record)]))
            ->modalSubmitActionLabel(__('session-replay::viewer.assist.offer'))
            ->action(function (ReplaySession $record, Component $livewire): void {
                $agent = PanelAssist::agent();

                try {
                    $request = app(Assist::class)->propose($agent, $record);
                } catch (AssistRefused $refused) {
                    PanelAssist::refused($refused);

                    return;
                }

                PanelAssist::flush();
                $livewire->redirect(PanelAssist::stageUrl($request));
            });
    }

    /** On a recording with a request this agent holds. */
    public static function openStageForSession(): Action
    {
        return Action::make('openAssistStageForSession')
            ->label(__('session-replay::viewer.assist.open_stage'))
            ->icon(static::icon(Icon::ASSIST_OPEN_STAGE, Heroicon::OutlinedSignal))
            ->color('success')
            ->visible(fn (ReplaySession $record): bool => PanelAssist::mayAssist() && PanelAssist::hasLiveView() && PanelAssist::heldFor($record) !== null)
            ->action(function (ReplaySession $record, Component $livewire): void {
                $request = PanelAssist::heldFor($record);

                if ($request !== null && PanelAssist::allows($request)) {
                    $livewire->redirect(PanelAssist::stageUrl($request));
                }
            });
    }

    /** @param  Closure(): void  $callback */
    protected static function run(Closure $callback, string $done): void
    {
        try {
            $callback();
        } catch (AssistRefused $refused) {
            PanelAssist::refused($refused);

            return;
        }

        PanelAssist::flush();

        Notification::make()->title($done)->success()->send();
    }

    /** The icon registered for the alias, else the default. */
    public static function icon(string $alias, Heroicon $default): mixed
    {
        return FilamentIcon::resolve($alias) ?? $default;
    }
}
