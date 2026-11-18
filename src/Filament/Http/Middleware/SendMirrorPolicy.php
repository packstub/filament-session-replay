<?php

namespace Packstub\SessionReplay\Filament\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packstub\SessionReplay\Support\Assist;
use Symfony\Component\HttpFoundation\Response;

/**
 * The core's Content-Security-Policy for a page that hosts the live mirror (Assist::mirrorPolicy()): rrweb's iframe
 * inherits it, so what the visitor's page points at loads only from the app and never tells another host the agent's
 * address. Added beside any policy the app sends (the browser enforces both), never in place of it.
 *
 * @internal On the live view's route.
 */
class SendMirrorPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $policy = app(Assist::class)->mirrorPolicy();

        if ($policy !== null) {
            $response->headers->set('Content-Security-Policy', $policy, false);
        }

        return $response;
    }
}
