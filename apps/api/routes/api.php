<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\TaxonomyController;
use Illuminate\Support\Facades\Route;

/*
| Versioned REST API. Every route lives under /api/v1.
| Tenant-scoped routes arrive in Stage 1 behind auth + policies.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // Public reference data (no tenant data): taxonomy used by the
    // onboarding profile wizard.
    Route::get('taxonomy', [TaxonomyController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('taxonomy.index');
});
