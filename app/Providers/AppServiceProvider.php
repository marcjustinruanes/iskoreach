<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Models\User;
use App\Policies\AcademicTermPolicy;
use App\Policies\ProviderProfilePolicy;
use App\Policies\ScholarshipAwardPolicy;
use App\Policies\ScholarshipPolicy;
use App\Policies\StudentProfilePolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('local')) {
            if (config('queue.default') === 'sync') {
                DevCommands::except('queue');
            }
            DevCommands::artisan('schedule:work', 'scheduler');
        }
        Gate::policy(AcademicTerm::class, AcademicTermPolicy::class);
        Gate::policy(StudentProfile::class, StudentProfilePolicy::class);
        Gate::policy(ProviderProfile::class, ProviderProfilePolicy::class);
        Gate::policy(Scholarship::class, ScholarshipPolicy::class);
        Gate::policy(ScholarshipAward::class, ScholarshipAwardPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::define('access-admin', fn (User $user) => $user->role === UserRole::Admin);
        Gate::define('access-provider', fn (User $user) => $user->role === UserRole::Provider);
        Gate::define('access-student', fn (User $user) => $user->role === UserRole::Student);
    }
}
