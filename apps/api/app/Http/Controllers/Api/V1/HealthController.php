<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Health\DatabaseProbe;
use Illuminate\Http\JsonResponse;

/**
 * Liveness and dependency check used by the web app, CI and Azure probes.
 * Reports what it actually observed; it never reports "ok" for a check
 * it could not run.
 */
class HealthController extends Controller
{
    public function __invoke(DatabaseProbe $database): JsonResponse
    {
        $checks = ['database' => $database->check()];

        $healthy = collect($checks)->every(fn (array $c) => $c['status'] === 'ok');

        return response()->json([
            'service' => 'ruleradar-api',
            'version' => config('ruleradar.version'),
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
