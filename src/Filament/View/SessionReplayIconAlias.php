<?php

namespace Packstub\SessionReplay\Filament\View;

/**
 * The Assist icons, each under an alias an app can point elsewhere:
 * `FilamentIcon::register([SessionReplayIconAlias::ASSIST_CLAIM => Heroicon::OutlinedHandThumbUp])`.
 * Without a registration the Heroicon default is used.
 */
class SessionReplayIconAlias
{
    /** The Help requests navigation item and the requests' empty state. */
    const ASSIST_NAVIGATION = 'filament-session-replay::assist.navigation';

    const ASSIST_CLAIM = 'filament-session-replay::assist.claim';

    const ASSIST_OFFER = 'filament-session-replay::assist.offer';

    const ASSIST_OPEN_STAGE = 'filament-session-replay::assist.open-stage';

    const ASSIST_WITHDRAW = 'filament-session-replay::assist.withdraw';

    const ASSIST_END = 'filament-session-replay::assist.end';

    /** The notification about a new request. */
    const ASSIST_REQUESTED = 'filament-session-replay::assist.requested';

    const STAGE_MIRROR = 'filament-session-replay::stage.mirror';

    const STAGE_SCREEN = 'filament-session-replay::stage.screen';

    const STAGE_CONTROL = 'filament-session-replay::stage.control';

    const STAGE_DRAW = 'filament-session-replay::stage.draw';

    const STAGE_AUDIO = 'filament-session-replay::stage.audio';

    const STAGE_VIDEO = 'filament-session-replay::stage.video';

    const STAGE_SHARE = 'filament-session-replay::stage.share';

    const STAGE_MUTE = 'filament-session-replay::stage.mute';

    const STAGE_END_AUDIO = 'filament-session-replay::stage.end-audio';
}
