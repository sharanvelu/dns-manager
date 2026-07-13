<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTriggerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('dns.trigger_token');

        if (! $token) {
            abort(404); // Endpoint is disabled until a token is configured.
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            abort(401, 'Invalid trigger token.');
        }

        return $next($request);
    }
}
