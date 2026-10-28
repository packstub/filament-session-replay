<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/**
 * `composer serve`: a recorded panel on /admin (signed in as ada@example.com / secret) where recordings are watched too.
 *
 * Assist: `SESSION_REPLAY_ASSIST=1 composer serve` (with packstub/calls and packstub/filament-calls linked, see
 * AGENTS.md), ask for help from Ada's page (`SessionReplay.assist.request()` in the console), then open /lab/agent
 * in another browser: Ana Support's Help requests, where Claim leads to the live stage. workbench/lab/assist.mjs
 * does it all in two Chromium browsers.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The visitor's page and the agent's stage poll at once (PHP_CLI_SERVER_WORKERS): wait for a lock, never fail.
        config()->set('database.connections.sqlite.busy_timeout', 10000);
        config()->set('database.connections.sqlite.journal_mode', 'wal');
    }

    public function boot(): void
    {
        config()->set('auth.providers.users.model', User::class);

        Gate::define('viewSessionReplay', fn ($user) => true);

        // Assist's agent, in another browser than Ada's: the Help requests page as Ana Support. The cookie tells the
        // panel's bootUsing() not to sign Ada in over her (it runs before the session is read).
        Route::middleware('web')->get('lab/agent', function () {
            Auth::login(User::query()->firstOrCreate(['email' => 'ana@example.com'], ['name' => 'Ana Support', 'password' => bcrypt('secret')]));

            return redirect('/admin/session-replays/requests')->withCookie(cookie('lab_role', 'agent'));
        });

        // Lab knobs: workbench/lab/navigate.mjs starts its own server with them.
        if ($except = env('LAB_EXCEPT_ROUTES')) {
            config()->set('session-replay.except_routes', [...config('session-replay.except_routes', []), $except]);
        }
    }
}
