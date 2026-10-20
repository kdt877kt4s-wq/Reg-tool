<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RegulatoryDocument;
use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\Http\JsonResponse;

/** Health of each monitored source, so silence is never mistaken for "no news". */
class SourceController extends Controller
{
    /** A source not read successfully within this many hours is reported stale. */
    public const STALE_AFTER_HOURS = 36;

    public function index(): JsonResponse
    {
        $this->authorizePermission('regulations.view');

        $sources = Source::query()->orderBy('name')->get();

        return response()->json(['data' => $sources->map(function (Source $s) {
            $lastRun = SourceRun::query()->where('source_id', $s->id)->orderByDesc('id')->first();
            $stale = $s->last_success_at === null || $s->last_success_at->lt(now()->subHours(self::STALE_AFTER_HOURS));

            return [
                'code' => $s->code,
                'name' => $s->name,
                'status' => $s->status,
                'stale' => $stale,
                'last_success_at' => $s->last_success_at?->toIso8601String(),
                'last_run_at' => $s->last_run_at?->toIso8601String(),
                'last_error' => $s->status === Source::DEGRADED ? 'The last attempt to read this source failed. RuleRadar will retry on schedule.' : null,
                'documents' => RegulatoryDocument::query()->where('source_id', $s->id)->count(),
                'last_run' => $lastRun ? [
                    'status' => $lastRun->status,
                    'window_start' => $lastRun->window_start->toDateString(),
                    'window_end' => $lastRun->window_end->toDateString(),
                    'fetched' => $lastRun->fetched,
                    'created' => $lastRun->created,
                    'updated' => $lastRun->updated,
                    'finished_at' => $lastRun->finished_at?->toIso8601String(),
                ] : null,
            ];
        })->values()]);
    }
}
