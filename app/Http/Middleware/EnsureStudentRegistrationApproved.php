<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentRegistrationApproved
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === UserRole::Student
            && $request->user()->studentProfile()->whereIn('registration_status', ['pending', 'rejected'])->exists()
            && ! $request->routeIs('student.registration-status', 'logout', 'password.*', 'user-password.*')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your student registration requires administrator approval.'], 403);
            }

            return to_route('student.registration-status');
        }

        return $next($request);
    }
}
