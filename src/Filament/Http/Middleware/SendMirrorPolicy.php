<?php

namespace Packstub\SessionReplay\Filament\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Packstub\SessionReplay\Support\Assist;
use Symfony\Component\HttpFoundation\Response;

/**
 * The core's Content-Security-Policy for a page that hosts the live mirror (Assist::mirrorPolicy()): rrweb's iframe
 * inherits it, so what the visitor's page points at loads only from the app and never tells another host the agent's
 * address. Added beside any policy the app sends (the browser enforces both), never in place of it.
 *
 * The route middleware only marks the request; the header goes on once every middleware is done (RequestHandled).
 * An app's own CSP middleware wraps this route and often skips its policy when the response already carries one
 * (spatie/laravel-csp does), so a header set here would have taken the app's policy off the live view.
 *
 * @internal On the live view's route.
 */
class SendMirrorPolicy
{
    public const ATTRIBUTE = 'filament-session-replay.mirror-policy';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);

        return $next($request);
    }

    /** After the whole middleware stack: the mirror's policy beside whatever the app sent. */
    public static function apply(RequestHandled $event): void
    {
        if ($event->request->attributes->get(self::ATTRIBUTE) !== true) {
            return;
        }

        $policy = app(Assist::class)->mirrorPolicy();

        if ($policy !== null) {
            $event->response->headers->set('Content-Security-Policy', $policy, false);
        }
    }
}
