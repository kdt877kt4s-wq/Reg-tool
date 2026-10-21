<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImpactAssessment;
use App\Models\RegulatoryDocument;
use App\Models\Task;
use App\Regulations\ProfileMatcher;
use App\Tasks\TaskAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deadlines, computed on request:
 *   - official: effective and comment-close dates published in the
 *     Federal Register, for documents this organization assessed or that
 *     match its profile; task due dates marked official,
 *   - internal: task due dates the organization set itself.
 * Nothing is inferred: a document without a published date has no entry.
 */
class CalendarController extends Controller
{
    public function index(Request $request, TaskAccess $access, ProfileMatcher $matcher): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        if ($from->diffInDays($to) > 400) {
            return response()->json(['message' => 'Ask for at most 400 days at a time.', 'errors' => ['to' => ['Ask for at most 400 days at a time.']]], 422);
        }

        return response()->json(['data' => self::entries($from, $to, $access, $matcher)->values()]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function entries(Carbon $from, Carbon $to, TaskAccess $access, ProfileMatcher $matcher): Collection
    {
        $assessed = ImpactAssessment::query()->pluck('decision', 'document_id');
        $parts = $matcher->parts();

        $documents = RegulatoryDocument::query()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('effective_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('comments_close_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->where(function ($q) use ($assessed, $parts, $matcher) {
                $q->whereIn('id', $assessed->keys()->all());
                if ($parts !== []) {
                    $q->orWhere(fn ($m) => $matcher->scope($m, $parts));
                }
            })
            ->get(['id', 'external_id', 'title', 'type', 'effective_date', 'comments_close_date', 'cfr_parts']);

        $entries = collect();
        foreach ($documents as $d) {
            $base = [
                'document' => ['id' => $d->id, 'document_number' => $d->external_id, 'title' => $d->title, 'type' => $d->type],
                'assessed' => $assessed->has($d->id),
                'decision' => $assessed->get($d->id),
                'profile_match' => array_intersect($d->cfr_parts ?? [], $parts) !== [],
                'basis' => "Federal Register document {$d->external_id}",
            ];
            foreach (['effective' => $d->effective_date, 'comments_close' => $d->comments_close_date] as $kind => $date) {
                if ($date && $date->betweenIncluded($from, $to)) {
                    $entries->push(['id' => "doc-{$d->id}-{$kind}", 'date' => $date->toDateString(), 'kind' => $kind, 'deadline_type' => 'official', 'title' => $d->title] + $base);
                }
            }
        }

        $tasks = $access->scope(Task::query())
            ->whereIn('status', Task::ACTIVE)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->with('owner:id,name')
            ->get();
        foreach ($tasks as $t) {
            $entries->push([
                'id' => "task-{$t->id}",
                'date' => $t->due_date->toDateString(),
                'kind' => 'task_due',
                'deadline_type' => $t->due_date_type ?? 'internal',
                'title' => $t->title,
                'basis' => $t->due_date_basis,
                'task' => ['id' => $t->id, 'status' => $t->status, 'priority' => $t->priority, 'owner' => $t->owner?->name, 'overdue' => $t->isOverdue()],
            ]);
        }

        return $entries->sortBy([['date', 'asc'], ['deadline_type', 'desc']]);
    }
}
