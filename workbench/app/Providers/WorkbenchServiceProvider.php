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
    }
}
