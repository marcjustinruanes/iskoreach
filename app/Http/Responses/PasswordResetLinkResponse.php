<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse as FortifyFailedResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'If an account matches this email, check its inbox for password reset instructions. If you requested recently, allow a few minutes before trying again.';

    public function __construct(public string $status) {}

    /** @param Request $request */
    public function toResponse($request): Response
    {
        if (! in_array($this->status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return (new FortifyFailedResponse($this->status))->toResponse($request);
        }

        return $request->wantsJson()
            ? new JsonResponse(['message' => self::MESSAGE])
            : to_route('password.request')->with('status', self::MESSAGE);
    }
}
