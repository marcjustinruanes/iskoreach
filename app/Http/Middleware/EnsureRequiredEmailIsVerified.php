<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequiredEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->requires_email_verification) {
            return app(EnsureEmailIsVerified::class)->handle($request, $next);
        }

        return $next($request);
    }
}
