<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->role === UserRole::Provider) {
            $user->load('providerProfile');
        }

        abort_unless(
            $user?->role === UserRole::Provider
            && $user->providerProfile?->isApproved(),
            403,
            'Administrator approval is required to access the provider workspace.'
        );

        return $next($request);
    }
}
