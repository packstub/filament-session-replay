@php
    use Filament\Support\Icons\Heroicon;
    use Packstub\SessionReplay\Facades\SessionReplay;
    use Packstub\SessionReplay\Filament\Assist\PanelAssist;
    use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
    use Packstub\SessionReplay\Filament\View\SessionReplayIconAlias as Icon;
    use Packstub\SessionReplay\Models\ReplaySession;

    $request = $this->getAssistRequest();
    $config = $this->getStageConfig();
    $s = 'session-replay::viewer.stage.';
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    // The stage's status in one word (the core's `session-replay:stage` event), as a badge in the panel's colours.
    $phases = [
        'waiting' => ['warning', __('Waiting')],
        'connecting' => ['info', __('Connecting')],
        'reconnecting' => ['warning', __('Reconnecting')],
        'live' => ['success', __('Live')],
        'hidden' => ['warning', __('Tab hidden')],
        'ended' => ['gray', __('Over')],
        'unavailable' => ['danger', __('No live view')],
    ];
@endphp

<x-filament-panels::page>
    {{-- The core's stage: rrweb's Replayer in live mode and the calls engine, from its script route. A full page load always brings it (the page is never reached through wire:navigate). --}}
    <link rel="stylesheet" href="{{ SessionReplay::scriptUrl('stage.css') }}" @if ($nonce) nonce="{{ $nonce }}" @endif>
    <script src="{{ SessionReplay::scriptUrl('stage.js') }}" defer @if ($nonce) nonce="{{ $nonce }}" @endif></script>

    <style @if ($nonce) nonce="{{ $nonce }}" @endif>
        /* The core's stage.css lays out the mirror; around it, the panel's own components and colours. */
        .fsr-stage {
            --sr-ink: var(--danger-500);
            --sr-control: var(--primary-600);
            --card: var(--color-white, #fff);
            --text: var(--gray-950);
            --muted: var(--gray-500);
            --border: var(--gray-200);
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        @media (min-width: 64rem) {
            .fsr-stage {
                grid-template-columns: minmax(0, 1fr) 22rem;
            }
        }

        :is(.dark) .fsr-stage {
            --sr-control: var(--primary-400);
            --card: var(--gray-900);
            --text: var(--color-white, #fff);
            --muted: var(--gray-400);
            --border: rgb(255 255 255 / 0.1);
        }

        .fsr-stage__main,
        .fsr-stage__side {
            display: grid;
            gap: 1.5rem;
            min-width: 0;
        }

        .fsr-stage__status {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem 0.75rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
            color: var(--gray-950);
        }

        :is(.dark) .fsr-stage__status {
            color: var(--color-white, #fff);
        }

        .fsr-stage__seen,
        .fsr-stage__why,
        .fsr-stage__muted {
            font-size: 0.75rem;
            line-height: 1rem;
            color: var(--gray-500);
        }

        :is(.dark) .fsr-stage__seen,
        :is(.dark) .fsr-stage__why,
        :is(.dark) .fsr-stage__muted {
            color: var(--gray-400);
        }

        .fsr-stage .sr-stage__screen {
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            outline: 1px solid rgb(3 7 18 / 0.05);
            background: var(--gray-100);
        }

        :is(.dark) .fsr-stage .sr-stage__screen {
            outline-color: rgb(255 255 255 / 0.1);
            background: var(--gray-950);
        }

        .fsr-stage .sr-stage__screen.is-controlling {
            outline: 2px dashed var(--sr-control);
            outline-offset: 2px;
        }

        .fsr-stage__tools {
            display: grid;
            gap: 1rem;
        }

        .fsr-stage__group {
            display: grid;
            gap: 0.375rem;
        }

        .fsr-stage__row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .fsr-stage__row > .fi-btn-group {
            flex: 1 1 auto;
        }

        .fsr-stage__about {
            display: grid;
            gap: 1rem;
            margin: 0;
        }

        .fsr-stage__about dt {
            font-size: 0.875rem;
            line-height: 1.5rem;
            font-weight: 500;
            color: var(--gray-950);
        }

        .fsr-stage__about dd {
            margin: 0;
            font-size: 0.875rem;
            line-height: 1.25rem;
            color: var(--gray-500);
            overflow-wrap: anywhere;
        }

        .fsr-stage__about .fsr-stage__message {
            white-space: pre-wrap;
        }

        :is(.dark) .fsr-stage__about dt {
            color: var(--color-white, #fff);
        }

        :is(.dark) .fsr-stage__about dd {
            color: var(--gray-400);
        }

        .fsr-stage__log {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 0.5rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
            color: var(--gray-700);
        }

        :is(.dark) .fsr-stage__log {
            color: var(--gray-300);
        }

        .fsr-stage__log time {
            color: var(--gray-500);
            font-variant-numeric: tabular-nums;
        }

        .fsr-stage__log:empty::before {
            content: attr(data-empty);
            color: var(--gray-500);
        }

        .fsr-stage__media {
            width: 100%;
            border-radius: 0.5rem;
            background: #000;
        }

        /* Once it is over, the status line says so: no reason under every disabled tool. */
        .fsr-stage[data-phase='ended'] .fsr-stage__why {
            display: none;
        }

        .fsr-stage [hidden] {
            display: none !important;
        }
    </style>

    {{-- wire:ignore: the stage script owns this DOM; a Livewire update of the page (a header action) must not morph it. --}}
    <div
        wire:ignore
        class="fsr-stage"
        data-session-replay-stage
        data-config="{{ json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
        x-data="{ phase: 'connecting' }"
        x-on:session-replay:stage="phase = $event.detail.phase"
        x-bind:data-phase="phase"
    >
        <div class="fsr-stage__main">
            <x-filament::section compact>
                <div class="fsr-stage__status" role="status" aria-live="polite">
                    @foreach ($phases as $phase => [$color, $label])
                        <x-filament::badge :color="$color" x-show="phase === '{{ $phase }}'" x-cloak>{{ $label }}</x-filament::badge>
                    @endforeach
                    <span data-ref="status">{{ __($s.'status.connecting') }}</span>
                    <span class="fsr-stage__seen" data-ref="seen"></span>
                </div>
            </x-filament::section>

            {{-- The visitor's page, replayed live in rrweb's sandboxed iframe (no scripts), or their screen as video. --}}
            <div class="sr-stage__screen" data-ref="screen" data-replay-block>
                <p class="sr-stage__placeholder" data-ref="placeholder">{{ __($s.'placeholder.waiting') }}</p>
                <div class="sr-stage__mirror" data-ref="mirror"></div>
                <video class="sr-stage__video" data-ref="video" playsinline autoplay muted hidden aria-label="{{ __($s.'screen_video') }}"></video>
                <canvas class="sr-stage__ink" data-ref="ink" aria-hidden="true"></canvas>
                <div class="sr-stage__overlay" data-ref="overlay" tabindex="-1" role="application" aria-label="{{ __($s.'overlay') }}"></div>
                <div class="sr-stage__editor" data-ref="editor" hidden>
                    <input type="text" data-ref="editor-text" autocomplete="off" aria-label="{{ __($s.'editor') }}">
                    <select data-ref="editor-select" aria-label="{{ __($s.'editor') }}" hidden></select>
                    <small>{{ __($s.'editor_hint') }}</small>
                </div>
            </div>
        </div>

        <aside class="fsr-stage__side">
            <x-filament::section :heading="__($s.'tools')" compact>
                <div class="fsr-stage__tools">
                    <div class="fsr-stage__group">
                        <div class="fsr-stage__row" role="group" aria-label="{{ __($s.'view') }}">
                            <x-filament::button.group>
                                <x-filament::button size="sm" :icon="Heroicon::OutlinedWindow" :icon-alias="Icon::STAGE_MIRROR" data-action="mirror" aria-pressed="true">
                                    <span data-label>{{ __($s.'buttons.mirror') }}</span>
                                </x-filament::button>
                                <x-filament::button size="sm" :icon="Heroicon::OutlinedComputerDesktop" :icon-alias="Icon::STAGE_SCREEN" data-action="screen" aria-pressed="false" aria-describedby="fsr-why-screen">
                                    <span data-label>{{ __($s.'buttons.ask_screen') }}</span>
                                </x-filament::button>
                            </x-filament::button.group>
                        </div>
                        <p class="fsr-stage__why" id="fsr-why-screen" data-why="screen" hidden></p>
                    </div>

                    <div class="fsr-stage__group">
                        <div class="fsr-stage__row">
                            <x-filament::button.group>
                                <x-filament::button size="sm" :icon="Heroicon::OutlinedCursorArrowRays" :icon-alias="Icon::STAGE_CONTROL" data-action="control" aria-pressed="false" aria-describedby="fsr-why-control">
                                    <span data-label>{{ __($s.'buttons.control') }}</span>
                                </x-filament::button>
                                <x-filament::button size="sm" :icon="Heroicon::OutlinedPencil" :icon-alias="Icon::STAGE_DRAW" data-action="draw" aria-pressed="false" aria-describedby="fsr-why-draw">
                                    <span data-label>{{ __($s.'buttons.draw') }}</span>
                                </x-filament::button>
                            </x-filament::button.group>
                        </div>
                        <p class="fsr-stage__why" id="fsr-why-control" data-why="control" hidden></p>
                        <p class="fsr-stage__why" id="fsr-why-draw" data-why="draw" hidden></p>
                    </div>

                    <div class="fsr-stage__group">
                        <div class="fsr-stage__row">
                            <x-filament::button size="sm" color="gray" :icon="Heroicon::OutlinedMicrophone" :icon-alias="Icon::STAGE_AUDIO" data-action="audio" aria-describedby="fsr-why-audio">
                                <span data-label>{{ __($s.'buttons.audio') }}</span>
                            </x-filament::button>
                            <x-filament::button size="sm" color="gray" :icon="Heroicon::OutlinedVideoCamera" :icon-alias="Icon::STAGE_VIDEO" data-action="video" aria-describedby="fsr-why-video" :hidden="! $config['video']">
                                <span data-label>{{ __($s.'buttons.video') }}</span>
                            </x-filament::button>
                            <x-filament::button size="sm" color="gray" :icon="Heroicon::OutlinedArrowUpOnSquare" :icon-alias="Icon::STAGE_SHARE" data-action="share" aria-describedby="fsr-why-share">
                                <span data-label>{{ __($s.'buttons.share') }}</span>
                            </x-filament::button>
                        </div>
                        <p class="fsr-stage__why" id="fsr-why-audio" data-why="audio" hidden></p>
                        <p class="fsr-stage__why" id="fsr-why-video" data-why="video" hidden></p>
                        <p class="fsr-stage__why" id="fsr-why-share" data-why="share" hidden></p>
                    </div>

                    <div class="fsr-stage__row">
                        <x-filament::button size="sm" color="gray" :icon="Heroicon::OutlinedArrowUturnLeft" :icon-alias="Icon::ASSIST_WITHDRAW" data-action="withdraw" hidden>
                            <span data-label>{{ __($s.'buttons.withdraw') }}</span>
                        </x-filament::button>
                        <x-filament::button size="sm" color="danger" :icon="Heroicon::OutlinedXCircle" :icon-alias="Icon::ASSIST_END" data-action="end" hidden>
                            <span data-label>{{ __($s.'buttons.end') }}</span>
                        </x-filament::button>
                    </div>

                    @if (PanelAssist::panelHasCallsWidget())
                        <p class="fsr-stage__muted">{{ __('Starting a call from the Calls widget ends this assist.') }}</p>
                    @endif
                </div>
            </x-filament::section>

            <div data-ref="audiobar" hidden>
                <x-filament::section :heading="__($s.'call')" compact>
                    <div class="fsr-stage__tools">
                        <video class="fsr-stage__media" data-ref="face" playsinline autoplay muted hidden aria-label="{{ __($s.'camera_video') }}"></video>
                        <audio data-ref="audio" autoplay></audio>
                        <div class="fsr-stage__row">
                            <x-filament::button size="sm" color="gray" :icon="Heroicon::OutlinedSpeakerXMark" :icon-alias="Icon::STAGE_MUTE" data-ref="mute" aria-pressed="false">
                                <span data-label>{{ __($s.'buttons.mute') }}</span>
                            </x-filament::button>
                            <x-filament::button size="sm" color="danger" outlined :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::STAGE_END_AUDIO" data-ref="end-audio">
                                <span data-label>{{ __($s.'buttons.end_audio') }}</span>
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            <x-filament::section :heading="__($s.'about')" compact>
                <dl class="fsr-stage__about">
                    <div>
                        <dt>{{ __('Person') }}</dt>
                        <dd>{{ PanelAssist::personLabel($request) }}</dd>
                    </div>
                    @if (filled($request->message))
                        {{-- The visitor's own words: escaped text, never markup. --}}
                        <div>
                            <dt>{{ __('session-replay::viewer.assist.message') }}</dt>
                            <dd class="fsr-stage__message">{{ $request->message }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt>{{ __($s.'now_on') }}</dt>
                        <dd data-ref="url">{{ $request->page_url ?? '·' }}</dd>
                    </div>
                    @if ($request->session instanceof ReplaySession && ReplaySessionResource::canView($request->session))
                        <div>
                            <dt>{{ __('session-replay::viewer.assist.recording') }}</dt>
                            <dd>
                                <x-filament::link :href="ReplaySessionResource::getUrl('view', ['record' => $request->session])" target="_blank" :icon="Heroicon::OutlinedPlay" size="sm">
                                    {{ __('Watch recording') }}
                                </x-filament::link>
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-filament::section>

            <x-filament::section :heading="__($s.'log')" compact>
                <ol class="fsr-stage__log" data-ref="notes" aria-live="polite" data-empty="{{ __('Nothing yet.') }}"></ol>
            </x-filament::section>
        </aside>
    </div>
</x-filament-panels::page>
