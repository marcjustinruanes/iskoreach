<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        Auth::guard(config('fortify.guard'))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = 'Registration submitted for administrator review. Sign in to check your account status.';
        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'redirect' => route('login')], 201);
        }

        return to_route('login')->with('status', $message);
    }
}
