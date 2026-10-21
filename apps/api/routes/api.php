<?php

use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicInvitationController;
use App\Http\Controllers\Api\V1\RegulatoryDocumentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaxonomyController;
use App\Http\Controllers\Api\V1\Webhooks\MalwareScanController;
use Illuminate\Support\Facades\Route;

/*
| Versioned REST API. Every route lives under /api/v1.
| Tenant routes: auth:sanctum -> tenant:organization -> mfa.enforced,
| then a permission check in the controller.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');
    Route::get('taxonomy', [TaxonomyController::class, 'index'])->name('taxonomy.index');
    Route::post('webhooks/malware-scan', MalwareScanController::class)->middleware('throttle:60,1')->name('webhooks.malware-scan');

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

        Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
        Route::get('regulatory-documents', [RegulatoryDocumentController::class, 'index'])->name('documents.index');
        Route::get('regulatory-documents/facets', [RegulatoryDocumentController::class, 'facets'])->name('documents.facets');
        Route::get('regulatory-documents/{documentId}', [RegulatoryDocumentController::class, 'show'])->whereNumber('documentId')->name('documents.show');
        Route::get('regulatory-documents/{documentId}/passages', [RegulatoryDocumentController::class, 'passages'])->whereNumber('documentId')->name('documents.passages');

        Route::middleware('throttle:ai')->post('regulatory-documents/{documentId}/analysis', [AnalysisController::class, 'store'])->whereNumber('documentId')->name('analysis.store');
        Route::get('regulatory-documents/{documentId}/analysis', [AnalysisController::class, 'show'])->whereNumber('documentId')->name('analysis.show');

        Route::get('assessments', [AssessmentController::class, 'index'])->name('assessments.index');
        Route::get('regulatory-documents/{documentId}/assessment', [AssessmentController::class, 'show'])->whereNumber('documentId')->name('assessments.show');
        Route::post('regulatory-documents/{documentId}/assessment', [AssessmentController::class, 'start'])->whereNumber('documentId')->name('assessments.start');
        Route::put('regulatory-documents/{documentId}/assessment', [AssessmentController::class, 'update'])->whereNumber('documentId')->name('assessments.update');
        Route::post('regulatory-documents/{documentId}/assessment/approve', [AssessmentController::class, 'approve'])->whereNumber('documentId')->name('assessments.approve');
        Route::post('regulatory-documents/{documentId}/assessment/reopen', [AssessmentController::class, 'reopen'])->whereNumber('documentId')->name('assessments.reopen');

        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('tasks/assignees', [TaskController::class, 'assignees'])->name('tasks.assignees');
        Route::get('tasks/{taskId}', [TaskController::class, 'show'])->whereNumber('taskId')->name('tasks.show');
        Route::patch('tasks/{taskId}', [TaskController::class, 'update'])->whereNumber('taskId')->name('tasks.update');
        Route::post('tasks/{taskId}/evidence', [TaskController::class, 'addEvidence'])->whereNumber('taskId')->name('tasks.evidence');
        Route::post('tasks/{taskId}/{action}', [TaskController::class, 'transition'])
            ->whereNumber('taskId')->whereIn('action', ['start', 'submit', 'accept', 'return', 'cancel'])->name('tasks.transition');
        Route::get('files/{fileId}/download', [TaskController::class, 'download'])->whereNumber('fileId')->name('files.download');

        Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notificationId}/read', [NotificationController::class, 'read'])->whereNumber('notificationId')->name('notifications.read');
        Route::get('notification-preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');

        Route::get('reports/regulatory-activity', [ReportController::class, 'activity'])->name('reports.activity');
        Route::get('reports/tasks', [ReportController::class, 'tasks'])->name('reports.tasks');
        Route::get('reports/evidence-pack/{documentId}', [ReportController::class, 'evidencePack'])->whereNumber('documentId')->name('reports.evidence-pack');

        Route::get('audit-events', [AuditEventController::class, 'index'])->name('audit.index');
        Route::get('audit-events/verify', [AuditEventController::class, 'verify'])->name('audit.verify');
    });
});
