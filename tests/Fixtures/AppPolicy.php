<?php

namespace Packstub\SessionReplay\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An app's CSP middleware the way spatie/laravel-csp behaves: it leaves a response that already has a policy alone. */
class AppPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "script-src 'self'");
        }

        return $response;
    }
}
