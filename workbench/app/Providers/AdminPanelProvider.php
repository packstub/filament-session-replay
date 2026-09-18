<?php

namespace Workbench\App\Providers;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\UserResource;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->resources([UserResource::class])
            ->pages([Dashboard::class])
            ->plugin(SessionReplayPlugin::make()->widget()->navigationGroup('Support'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class])
            ->bootUsing(function (): void {
                // The workbench signs its one person in; a real panel has a login page for that.
                if (! auth()->check() && ! app()->runningInConsole()) {
                    auth()->login(User::query()->firstOrCreate(['email' => 'ada@example.com'], ['name' => 'Ada Lovelace', 'password' => bcrypt('secret')]));
                }
            });
    }
}
