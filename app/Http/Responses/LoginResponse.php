<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\LoginResponse as FortifyLoginResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LoginResponse extends FortifyLoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        $intended = $request->session()->get('url.intended');
        if ($intended !== null && (! is_string($intended) || ! $this->canVisit($request, $intended))) {
            $request->session()->forget('url.intended');
        }

        return parent::toResponse($request);
    }

    private function canVisit(Request $request, string $url): bool
    {
        $relative = str_starts_with($url, '/') && ! str_starts_with($url, '//');
        if (! $relative && ! str_starts_with($url, $request->getSchemeAndHttpHost().'/')) {
            return false;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($url, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if (str_starts_with($middleware, 'role:') && ! in_array($request->user()->role->value, explode(',', substr($middleware, 5)), true)) {
                return false;
            }
            if ($middleware === 'provider.verified' && ! $request->user()->providerProfile?->isApproved()) {
                return false;
            }
        }

        return true;
    }
}
