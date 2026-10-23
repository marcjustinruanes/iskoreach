<?php

use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ApplicantCountController as AdminApplicantCountController;
use App\Http\Controllers\Admin\AwardMonitoringController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PolicyDocumentController;
use App\Http\Controllers\Admin\ProviderReviewController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ScholarshipController as AdminScholarshipController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\RegistrationEmailController;
use App\Http\Controllers\Auth\RegistrationStepController;
use App\Http\Controllers\AwardBenefitController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentReviewController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Provider\AccountController as ProviderAccountController;
use App\Http\Controllers\Provider\ApplicantCountController as ProviderApplicantCountController;
use App\Http\Controllers\Provider\ScholarshipController as ProviderScholarshipController;
use App\Http\Controllers\ProviderVerificationDocumentController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PasserController;
use App\Http\Controllers\Public\ScholarshipController as PublicScholarshipController;
use App\Http\Controllers\RenewalController;
use App\Http\Controllers\ScholarshipAnnouncementController;
use App\Http\Controllers\Student\AccountController as StudentAccountController;
use App\Http\Controllers\Student\ApplicationController;
use App\Http\Controllers\Student\AwardController as StudentAwardController;
use App\Http\Controllers\Student\DocumentController;
use App\Http\Controllers\Student\RequirementsController;
use App\Http\Controllers\StudentPhotoController;
use App\Services\ProviderRegistration;
use App\Services\RegistrationPolicies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['guest', 'throttle:google-auth'])->group(function (): void {
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->block()->name('google.callback');
    Route::get('/auth/google/link', [GoogleAuthController::class, 'showLink'])->name('google.link');
    Route::post('/auth/google/link', [GoogleAuthController::class, 'link'])->middleware('throttle:google-link')->block()->name('google.link.store');
});

Route::middleware(['auth', 'role:student,provider'])->group(function (): void {
    Route::get('/scholarship-announcements', [ScholarshipAnnouncementController::class, 'index'])->name('scholarship-announcements.index');
    Route::post('/scholarship-announcements/{scholarship}', [ScholarshipAnnouncementController::class, 'store'])->middleware('throttle:10,1')->name('scholarship-announcements.store');
    Route::patch('/document-reviews/{document}', [DocumentReviewController::class, 'update'])->middleware('throttle:30,1')->name('document-reviews.update');
});
Route::get('/register/student', fn () => Inertia::render('Auth', app(ProviderRegistration::class)->page('student')))->middleware('guest')->name('register.student');
Route::get('/register/provider', fn () => Inertia::render('Auth', app(ProviderRegistration::class)->page('provider')))->middleware('guest')->name('register.provider');
Route::get('/terms', fn (RegistrationPolicies $policies) => Inertia::render('Policy', $policies->page('terms')))->name('terms');
Route::get('/privacy', fn (RegistrationPolicies $policies) => Inertia::render('Policy', $policies->page('privacy')))->name('privacy');
Route::get('/register/policies', fn (RegistrationPolicies $policies) => response()->json($policies->versions()))->middleware('guest')->name('register.policies');
Route::get('/register/policies/{kind}', fn (string $kind, RegistrationPolicies $policies) => response()->json($policies->page($kind)))->whereIn('kind', ['terms', 'privacy'])->middleware('guest')->name('register.policies.show');
Route::post('/register/email/send', [RegistrationEmailController::class, 'send'])->middleware(['guest', 'throttle:5,10'])->block()->name('register.email.send');
Route::post('/register/email/verify', [RegistrationEmailController::class, 'verify'])->middleware(['guest', 'throttle:10,1'])->block()->name('register.email.verify');
Route::get('/locations/cities/{city}/barangays', [LocationLookupController::class, 'barangays'])->where('city', '[0-9]{9}')->middleware('throttle:60,1')->name('locations.barangays');
Route::get('/locations/regions/{region}/provinces', [LocationLookupController::class, 'provinces'])->where('region', '[0-9]{9}')->middleware('throttle:60,1')->name('locations.provinces');
Route::get('/', HomeController::class)->name('home');
Route::get('/locations/names', [LocationLookupController::class, 'names'])->middleware('throttle:60,1')->name('locations.names');
Route::get('/locations/regions', LocationLookupController::class)->middleware('throttle:60,1')->name('locations.regions');
Route::get('/locations/regions/{region}/cities', LocationLookupController::class)->where('region', '[0-9]{9}')->middleware('throttle:60,1')->name('locations.cities');
Route::get('/passers', PasserController::class)->name('passers.index');
Route::get('/scholarships', [PublicScholarshipController::class, 'index'])->name('scholarships.index');
Route::get('/scholarships/{slug}', [PublicScholarshipController::class, 'show'])->name('scholarships.show');
Route::get('/scholarships/{slug}/apply', [PublicScholarshipController::class, 'apply'])->middleware(['auth', 'role:student'])->name('scholarships.apply');
Route::get('/dashboard', DashboardController::class)->middleware(['auth'])->name('dashboard');
Route::get('/student/registration-status', function (Request $request) {
    $profile = $request->user()->studentProfile;
    if ($profile?->registration_status === 'approved') {
        return to_route('student.dashboard');
    }

    return Inertia::render('StudentRegistrationStatus', ['registrationStatus' => $profile?->registration_status ?? 'pending', 'reviewReason' => $profile?->registration_review_reason]);
})->middleware(['auth', 'role:student'])->name('student.registration-status');
Route::middleware(['auth', 'role:student,provider'])->prefix('benefits')->name('benefits.')->group(function (): void {
    Route::get('/', [AwardBenefitController::class, 'index'])->name('index');
    Route::get('/applications/{application}/create', [AwardBenefitController::class, 'create'])->name('create');
    Route::post('/applications/{application}', [AwardBenefitController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('/{award}', [AwardBenefitController::class, 'show'])->name('show');
    Route::patch('/{award}', [AwardBenefitController::class, 'update'])->name('update');
    Route::post('/{award}/releases', [AwardBenefitController::class, 'storeRelease'])->middleware('throttle:20,1')->name('releases.store');
    Route::patch('/releases/{release}', [AwardBenefitController::class, 'updateRelease'])->middleware('throttle:30,1')->name('releases.update');
});
Route::middleware(['auth', 'role:student,provider'])->prefix('renewals')->name('renewals.')->group(function (): void {
    Route::get('/', [RenewalController::class, 'index'])->name('index');
    Route::post('/', [RenewalController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('/documents/{attachment}', [RenewalController::class, 'download'])->name('download');
    Route::get('/{renewal}', [RenewalController::class, 'show'])->name('show');
    Route::patch('/{renewal}', [RenewalController::class, 'update'])->middleware('throttle:30,1')->name('update');
});
Route::middleware(['auth', 'role:student,provider'])->prefix('messages')->name('messages.')->group(function (): void {
    Route::get('/', [MessageController::class, 'index'])->name('index');
    Route::post('/applications/{application}', [MessageController::class, 'start'])->middleware('throttle:20,1')->name('start');
    Route::get('/attachments/{attachment}', [MessageController::class, 'download'])->name('download');
    Route::get('/{conversation}', [MessageController::class, 'show'])->name('show');
    Route::get('/{conversation}/state', [MessageController::class, 'state'])->name('state');
    Route::post('/{conversation}', [MessageController::class, 'send'])->middleware('throttle:30,1')->name('send');
    Route::post('/{conversation}/read', [MessageController::class, 'read'])->middleware('throttle:60,1')->name('read');
    Route::post('/{conversation}/typing', [MessageController::class, 'typing'])->middleware('throttle:60,1')->name('typing');
});
Route::middleware(['auth'])->group(function (): void {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/summary', [NotificationController::class, 'summary'])->name('notifications.summary');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
});
Route::get('/application-documents/{applicationDocument}/download', [DocumentController::class, 'downloadAttachment'])->middleware(['auth'])->name('application-documents.download');

Route::middleware(['auth', 'role:student'])->prefix('student')->name('student.')->group(function (): void {
    Route::get('/requirements', [RequirementsController::class, 'index'])->name('requirements.index');
    Route::post('/applications/{application}/requirements/{requirement}/upload', [RequirementsController::class, 'upload'])->middleware('throttle:20,1');
    Route::get('/scholarships', [PublicScholarshipController::class, 'index'])->name('scholarships.index');
    Route::get('/scholarships/{slug}', [PublicScholarshipController::class, 'show'])->name('scholarships.show');
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::post('/applications/{application}/requirements/{requirement}', [DocumentController::class, 'attach'])->name('documents.attach');
    Route::delete('/applications/{application}/requirements/{requirement}', [DocumentController::class, 'attach'])->name('documents.detach');
    Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::post('/scholarships/{scholarship}/applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::get('/applications/{application}', [ApplicationController::class, 'show'])->withTrashed()->name('applications.show');
    Route::patch('/applications/{application}/submit', [ApplicationController::class, 'submit'])->name('applications.submit');
    Route::get('/awards', [StudentAwardController::class, 'index'])->name('awards.index');
    Route::patch('/awards/{award}/consent', [StudentAwardController::class, 'update'])->name('awards.consent');
    Route::get('/dashboard', [StudentAccountController::class, 'show'])->name('dashboard');
    Route::get('/profile', [StudentAccountController::class, 'profile'])->name('profile');
    Route::patch('/profiles/{studentProfile}', [StudentAccountController::class, 'update'])->name('profiles.update');
});

Route::middleware(['auth', 'role:provider'])->prefix('provider')->name('provider.')->group(function (): void {
    Route::middleware('provider.verified')->group(function (): void {
        Route::get('/applications', [App\Http\Controllers\Provider\ApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/{application}', [App\Http\Controllers\Provider\ApplicationController::class, 'show'])->name('applications.show');
        Route::patch('/applications/{application}', [App\Http\Controllers\Provider\ApplicationController::class, 'update'])->name('applications.update');
        Route::get('/scholarships/{scholarship}/preview', [ProviderScholarshipController::class, 'preview'])->name('scholarships.preview');
        Route::post('/applications/{application}/notes', [App\Http\Controllers\Provider\ApplicationController::class, 'note'])->name('applications.notes');
        Route::resource('scholarships', ProviderScholarshipController::class)->except(['show', 'destroy']);
        Route::patch('/scholarships/{scholarship}/capacity', [ProviderScholarshipController::class, 'capacity'])->name('scholarships.capacity');
        Route::patch('/scholarships/{scholarship}/lifecycle', [ProviderScholarshipController::class, 'lifecycle'])->name('scholarships.lifecycle');
        Route::get('/applicant-counts', [ProviderApplicantCountController::class, 'index'])->name('applicant-counts.index');
        Route::get('/applicant-counts/{scholarship}', [ProviderApplicantCountController::class, 'show'])->name('applicant-counts.show');
    });
    Route::get('/status', [ProviderAccountController::class, 'status'])->name('status');
    Route::get('/profile', [ProviderAccountController::class, 'profile'])->name('profile');
    Route::get('/dashboard', [ProviderAccountController::class, 'show'])->name('dashboard');
    Route::patch('/profiles/{providerProfile}', [ProviderAccountController::class, 'update'])->name('profiles.update');
    Route::get('/workspace', [ProviderAccountController::class, 'workspace'])
        ->middleware('provider.verified')->name('workspace');
});

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/policies', [PolicyDocumentController::class, 'index'])->name('policies.index');
    Route::post('/policies/{kind}', [PolicyDocumentController::class, 'store'])->whereIn('kind', ['terms', 'privacy'])->name('policies.store');
    Route::get('/academic-terms', [AcademicTermController::class, 'index'])->name('academic-terms.index');
    Route::post('/academic-terms', [AcademicTermController::class, 'store'])->name('academic-terms.store');
    Route::patch('/academic-terms/{academicTerm}', [AcademicTermController::class, 'update'])->name('academic-terms.update');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
    Route::get('/awards', [AwardMonitoringController::class, 'awards'])->name('awards.index');
    Route::patch('/awards/{award}/publication', [AwardMonitoringController::class, 'publish'])->name('awards.publication');
    Route::get('/renewals', [AwardMonitoringController::class, 'renewals'])->name('renewals.index');
    Route::get('/releases', [AwardMonitoringController::class, 'releases'])->name('releases.index');
    Route::get('/applicant-counts', [AdminApplicantCountController::class, 'index'])->name('applicant-counts.index');
    Route::get('/applicant-counts/{scholarship}', [AdminApplicantCountController::class, 'show'])->withTrashed()->name('applicant-counts.show');
    Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
    Route::get('/scholarships', [AdminScholarshipController::class, 'index'])->name('scholarships.index');
    Route::get('/scholarships/{scholarship}', [AdminScholarshipController::class, 'show'])->withTrashed()->name('scholarships.show');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::patch('/users/{user}/registration-review', [AdminUserController::class, 'reviewStudent'])->name('users.registration-review');
    Route::get('/providers', [ProviderReviewController::class, 'index'])->name('providers.index');
    Route::get('/providers/{providerProfile}', [ProviderReviewController::class, 'show'])->name('providers.show');
    Route::patch('/providers/{providerProfile}/review', [ProviderReviewController::class, 'update'])->name('providers.review');
});

Route::post('/register/validate-step', RegistrationStepController::class)->middleware(['guest', 'throttle:30,1'])->name('register.validate-step');
Route::get('/provider-verification/{providerProfile}/{kind}', [ProviderVerificationDocumentController::class, 'show'])->middleware(['auth'])->name('provider-verification.show');

Route::post('/provider-verification/{providerProfile}', [ProviderVerificationDocumentController::class, 'update'])->middleware(['auth', 'throttle:10,1'])->name('provider-verification.update');

Route::middleware(['auth', 'role:student'])->group(function (): void {
    Route::post('/student/profile/photo', [StudentPhotoController::class, 'photo']);
    Route::delete('/student/profile/photo', [StudentPhotoController::class, 'removePhoto']);
    Route::get('/student/profile/photo', [StudentPhotoController::class, 'showPhoto'])->name('student.photo.show');
});
