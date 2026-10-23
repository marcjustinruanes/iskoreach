<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Services\ProviderRegistration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth', ['mode' => 'login']));
        Fortify::registerView(fn (Request $request) => Inertia::render('Auth', app(ProviderRegistration::class)->page($request->query('account') === 'provider' ? 'provider' : 'student')));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth', ['mode' => 'forgot']));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth', [
            'mode' => 'reset', 'token' => $request->route('token'), 'email' => $request->query('email', ''),
        ]));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth', ['mode' => 'confirm']));
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('google-auth', fn (Request $request) => Limit::perMinute(20)->by('google-auth|'.$request->ip()));
        RateLimiter::for('google-link', fn (Request $request) => Limit::perMinute(5)->by('google-link|'.$request->ip()));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
