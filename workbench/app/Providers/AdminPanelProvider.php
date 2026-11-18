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
use Packstub\FilamentHoller\FilamentHollerPlugin;
use Packstub\SessionReplay\SessionReplayPlugin;
use Packstub\SessionReplay\Tests\Fixtures\Filament\Resources\Users\UserResource;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;
use Workbench\App\Filament\Resources\Orders\OrderResource;
use Workbench\App\Models\Order;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->resources([UserResource::class, OrderResource::class])
            // LAB_SPA=1 composer serve: the panel navigates with wire:navigate, for the lab's measurements.
            ->spa((bool) env('LAB_SPA', false))
            ->pages([Dashboard::class])
            // LAB_IMPERSONATE_ON=admin/orders: pages on that path are signed with an impersonator, for the lab's identity check.
            ->plugin(SessionReplayPlugin::make()->widget()->navigationGroup('Support')->impersonatorUsing(fn () => env('LAB_IMPERSONATE_ON') && request()->is(env('LAB_IMPERSONATE_ON')) ? '7' : null))
            // Filament Holler beside the Assist stage (a dev dependency): its widget polls kind=call.
            ->plugins(class_exists(FilamentHollerPlugin::class) && env('SESSION_REPLAY_ASSIST') ? [FilamentHollerPlugin::make()] : [])
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
                if (! auth()->check() && ! app()->runningInConsole() && ! request()->cookies->has('lab_role')) {
                    Order::seedLab();
                    auth()->login(User::query()->firstOrCreate(['email' => 'ada@example.com'], ['name' => 'Ada Lovelace', 'password' => bcrypt('secret')]));
                }
            });
    }
}
