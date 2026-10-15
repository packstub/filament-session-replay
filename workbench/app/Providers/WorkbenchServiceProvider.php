<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/** `composer serve`: a recorded panel on /admin (signed in as ada@example.com / secret) where recordings are watched too. */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config()->set('auth.providers.users.model', User::class);

        Gate::define('viewSessionReplay', fn ($user) => true);

        // Lab knobs: workbench/lab/navigate.mjs starts its own server with them.
        if ($except = env('LAB_EXCEPT_ROUTES')) {
            config()->set('session-replay.except_routes', [...config('session-replay.except_routes', []), $except]);
        }
    }
}
