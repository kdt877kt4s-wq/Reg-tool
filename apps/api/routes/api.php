<?php

use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicInvitationController;
use App\Http\Controllers\Api\V1\TaxonomyController;
use Illuminate\Support\Facades\Route;

/*
| Versioned REST API. Every route lives under /api/v1.
| Tenant routes: auth:sanctum -> tenant:organization -> mfa.enforced,
| then a permission check in the controller.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');
    Route::get('taxonomy', [TaxonomyController::class, 'index'])->name('taxonomy.index');

    // Public account routes.
    Route::middleware('throttle:public-forms')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');
        Route::get('invitations/{token}', [PublicInvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}/accept', [PublicInvitationController::class, 'accept'])->name('invitations.accept');
    });
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('auth/mfa/challenge', [AuthController::class, 'challenge'])->middleware('throttle:mfa')->name('auth.mfa.challenge');

    // Signed-in account routes (no organization needed).
    Route::middleware(['auth:sanctum', 'tenant:user'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/mfa/setup', [MfaController::class, 'setup'])->name('auth.mfa.setup');
        Route::middleware('throttle:mfa')->group(function () {
            Route::post('auth/mfa/confirm', [MfaController::class, 'confirm'])->name('auth.mfa.confirm');
            Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->name('auth.mfa.recovery');
            Route::delete('auth/mfa', [MfaController::class, 'disable'])->name('auth.mfa.disable');
        });
    });

    // Organization routes: tenant-isolated and permission-checked.
    Route::middleware(['auth:sanctum', 'tenant:organization', 'mfa.enforced'])->group(function () {
        Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
        Route::patch('organization', [OrganizationController::class, 'update'])->name('organization.update');

        Route::get('organization/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('organization/profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::get('organization/members', [MemberController::class, 'index'])->name('members.index');
        Route::patch('organization/members/{membershipId}', [MemberController::class, 'update'])->whereNumber('membershipId')->name('members.update');
        Route::delete('organization/members/{membershipId}', [MemberController::class, 'destroy'])->whereNumber('membershipId')->name('members.destroy');

        Route::get('organization/invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('organization/invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::delete('organization/invitations/{invitationId}', [InvitationController::class, 'destroy'])->whereNumber('invitationId')->name('invitations.destroy');

        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::patch('departments/{departmentId}', [DepartmentController::class, 'update'])->whereNumber('departmentId')->name('departments.update');

        Route::get('audit-events', [AuditEventController::class, 'index'])->name('audit.index');
        Route::get('audit-events/verify', [AuditEventController::class, 'verify'])->name('audit.verify');
    });
});
