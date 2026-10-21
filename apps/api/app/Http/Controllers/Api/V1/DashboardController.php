<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiAnalysis;
use App\Models\ImpactAssessment;
use App\Models\RegulatoryDocument;
use App\Models\Task;
use App\Regulations\ProfileMatcher;
use App\Tasks\TaskAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Counts for the overview. Everything is computed from stored records. */
class DashboardController extends Controller
{
    public function summary(TaskAccess $access, ProfileMatcher $matcher): JsonResponse
    {
        $userId = (int) $this->tenant()->userId();
        $today = today()->toDateString();

        $mine = Task::query()->where('owner_id', $userId)->whereIn('status', Task::ACTIVE);
        $visible = fn () => $access->scope(Task::query());

        $toReview = $visible()->where('status', Task::SUBMITTED)->get()
            ->filter(fn (Task $t) => $access->canReview($t))->count();

        $parts = $matcher->parts();
        $weeks = 12;
        $since = today()->startOfWeek()->subWeeks($weeks - 1);
        $weekly = $this->weekly($since->toDateString(), $parts);

        $assessments = ImpactAssessment::query()
            ->join('regulatory_documents as d', 'd.id', '=', 'impact_assessments.document_id')
            ->selectRaw("count(*) filter (where impact_assessments.status = 'draft') as in_progress")
            ->selectRaw("count(*) filter (where impact_assessments.status = 'approved') as approved")
            ->selectRaw("count(*) filter (where impact_assessments.status = 'approved' and impact_assessments.document_version <> d.current_version) as source_changed")
            ->first();
        $awaiting = AiAnalysis::query()
            ->where('status', 'completed')
            ->whereNotIn('document_id', ImpactAssessment::query()->select('document_id'))
            ->distinct()->count('document_id');

        $deadlines = CalendarController::entries(today(), today()->addDays(30), $access, $matcher)
            ->take(8)->values();

        return response()->json(['data' => [
            'my_tasks' => [
                'active' => (clone $mine)->count(),
                'overdue' => (clone $mine)->whereDate('due_date', '<', $today)->count(),
                'due_this_week' => (clone $mine)->whereBetween('due_date', [$today, today()->addDays(7)->toDateString()])->count(),
                'returned' => (clone $mine)->where('status', Task::RETURNED)->count(),
            ],
            'to_review' => $toReview,
            'tasks' => [
                'scope' => $access->seesAll() ? 'organization' : 'visible',
                'active' => $visible()->whereIn('status', Task::ACTIVE)->count(),
                'overdue' => $visible()->whereIn('status', Task::ACTIVE)->whereDate('due_date', '<', $today)->count(),
                'unassigned' => $visible()->whereIn('status', Task::ACTIVE)->whereNull('owner_id')->count(),
                'done_30d' => $visible()->where('status', Task::DONE)->where('completed_at', '>=', now()->subDays(30))->count(),
            ],
            'assessments' => [
                'awaiting_review' => $awaiting,
                'in_progress' => (int) ($assessments->in_progress ?? 0),
                'approved' => (int) ($assessments->approved ?? 0),
                'source_changed' => (int) ($assessments->source_changed ?? 0),
            ],
            'deadlines' => $deadlines,
            'weekly_publications' => $weekly,
            'profile_configured' => $parts !== [],
        ]]);
    }

    /**
     * Federal Register publications per week, and how many matched the
     * profile. Weeks with none are included as zero.
     *
     * @param  list<string>  $parts
     * @return list<array{week: string, total: int, matches: int}>
     */
    private function weekly(string $since, array $parts): array
    {
        $match = $parts === []
            ? '0'
            : 'count(*) filter (where jsonb_exists_any(cfr_parts, '.DB::getPdo()->quote(ProfileMatcher::pgArray($parts)).'::text[]))';

        $rows = RegulatoryDocument::query()
            ->where('publication_date', '>=', $since)
            ->selectRaw("to_char(date_trunc('week', publication_date), 'YYYY-MM-DD') as week")
            ->selectRaw('count(*) as total')
            ->selectRaw("{$match} as matches")
            ->groupBy('week')
            ->get()
            ->keyBy('week');

        $out = [];
        for ($w = Carbon::parse($since); $w->lte(today()); $w = $w->copy()->addWeek()) {
            $key = $w->toDateString();
            $out[] = ['week' => $key, 'total' => (int) ($rows[$key]->total ?? 0), 'matches' => (int) ($rows[$key]->matches ?? 0)];
        }

        return $out;
    }
}
