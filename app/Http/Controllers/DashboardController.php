<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return to_route(match ($request->user()->role) {
            UserRole::Student => 'student.dashboard',
            UserRole::Provider => $request->user()->providerProfile?->isApproved() ? 'provider.dashboard' : 'provider.status',
            UserRole::Admin => 'admin.dashboard',
        });
    }
}
