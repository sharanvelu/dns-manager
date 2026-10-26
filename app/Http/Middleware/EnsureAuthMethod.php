<?php

declare(strict_types = 1);

namespace App\Http\Middleware;

use Closure;
use App\Support\AuthMethods;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 404s routes belonging to a sign-in method the deployment has disabled. */
class EnsureAuthMethod
{
    public function handle(Request $request, Closure $next, string $method): Response
    {
        $enabled = $method === 'sso' ? AuthMethods::sso() : AuthMethods::basic();

        abort_unless($enabled, 404);

        return $next($request);
    }
}
