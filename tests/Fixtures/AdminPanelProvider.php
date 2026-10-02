<?php

namespace Packstub\SessionReplay\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\UserResource;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // URLs none of the default `except` paths match, so only the route names leave these pages out.
            ->passwordReset()
            ->passwordResetRoutePrefix('account')
            ->passwordResetRequestRouteSlug('forgot')
            ->emailVerification(isRequired: false)
            ->emailVerificationRoutePrefix('confirm')
            ->emailChangeVerification()
            ->emailChangeVerificationRoutePrefix('change')
            ->resources([UserResource::class])
            ->pages([Dashboard::class])
            ->plugin(
                SessionReplayPlugin::make()
                    ->widget()
                    ->navigationGroup('Support')
                    ->properties(fn () => ['release' => '1.2.3']),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
