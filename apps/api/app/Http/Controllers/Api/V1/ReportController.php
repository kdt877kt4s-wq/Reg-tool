<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\AiAnalysis;
use App\Models\AuditEvent;
use App\Models\ImpactAssessment;
use App\Models\RegulatoryDocument;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Regulations\ProfileMatcher;
use App\Reports\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports built from stored records only. Each states what it covers and
 * when it was generated; AI output is labelled as such and kept apart from
 * the organization's own decisions. Exports are recorded in the audit log.
 */
class ReportController extends Controller
{
    public function activity(Request $request, ProfileMatcher $matcher, AuditLogger $audit): JsonResponse|StreamedResponse
    {
        $this->authorizePermission('reports.view');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'scope' => ['sometimes', Rule::in(['profile', 'all'])],
            'format' => ['sometimes', Rule::in(['json', 'csv'])],
        ]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 400) {
            return response()->json(['message' => 'Choose at most 400 days.', 'errors' => ['to' => ['Choose at most 400 days.']]], 422);
        }
        $scope = $data['scope'] ?? 'profile';
        $parts = $matcher->parts();

        $query = RegulatoryDocument::query()
            ->whereBetween('publication_date', [$data['from'], $data['to']])
            ->orderBy('publication_date')->orderBy('external_id');
        if ($scope === 'profile') {
            $matcher->scope($query, $parts);
        }
        $documents = $query->limit(2000)->get();

        $assessments = ImpactAssessment::query()->whereIn('document_id', $documents->pluck('id'))->with('approver:id,name')->get()->keyBy('document_id');
        $analyses = AiAnalysis::query()->whereIn('document_id', $documents->pluck('id'))->where('status', AiAnalysis::COMPLETED)
            ->orderBy('id')->get(['id', 'document_id', 'output', 'confidence'])->keyBy('document_id');
        $tasks = Task::query()->whereIn('document_id', $documents->pluck('id'))
            ->selectRaw("document_id, count(*) as total, count(*) filter (where status = 'done') as done")
            ->groupBy('document_id')->get()->keyBy('document_id');

        $rows = $documents->map(function (RegulatoryDocument $d) use ($assessments, $analyses, $tasks, $parts) {
            $a = $assessments->get($d->id);
            $ai = $analyses->get($d->id);

            return [
                'document_id' => $d->id,
                'document_number' => $d->external_id,
                'citation' => $d->citation,
                'type' => $d->type,
                'title' => $d->title,
                'agencies' => collect($d->agencies)->pluck('name')->implode('; '),
                'publication_date' => $d->publication_date->toDateString(),
                'effective_date' => $d->effective_date?->toDateString(),
                'comments_close_date' => $d->comments_close_date?->toDateString(),
                'cfr_parts' => implode('; ', $d->cfr_parts ?? []),
                'profile_match' => array_intersect($d->cfr_parts ?? [], $parts) !== [],
                'ai_applicability' => $ai?->output['applicability']['level'] ?? null,
                'ai_confidence' => $ai?->confidence,
                'assessment_status' => $a?->status,
                'decision' => $a?->decision,
                'approved_by' => $a?->approver?->name,
                'approved_at' => $a?->approved_at?->toIso8601String(),
                'source_changed_since_approval' => $a && $a->status === 'approved' ? $a->document_version !== $d->current_version : null,
                'tasks' => (int) ($tasks->get($d->id)->total ?? 0),
                'tasks_done' => (int) ($tasks->get($d->id)->done ?? 0),
                'source_url' => $d->html_url,
            ];
        });

        $audit->record('report.generated', null, null, ['report' => 'regulatory_activity', 'from' => $data['from'], 'to' => $data['to'], 'scope' => $scope, 'format' => $data['format'] ?? 'json', 'rows' => $rows->count()]);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->csv("ruleradar-regulatory-activity-{$data['from']}-to-{$data['to']}.csv", $rows);
        }

        return response()->json([
            'meta' => $this->meta() + ['from' => $data['from'], 'to' => $data['to'], 'scope' => $scope, 'profile_cfr_parts' => $parts, 'truncated' => $documents->count() >= 2000],
            'summary' => [
                'publications' => $rows->count(),
                'profile_matches' => $rows->where('profile_match', true)->count(),
                'analyzed' => $rows->whereNotNull('ai_applicability')->count(),
                'approved' => $rows->where('assessment_status', 'approved')->count(),
                'applies' => $rows->where('decision', 'applicable')->where('assessment_status', 'approved')->count(),
                'source_changed' => $rows->where('source_changed_since_approval', true)->count(),
            ],
            'data' => $rows->values(),
        ]);
    }

    public function tasks(Request $request, AuditLogger $audit): JsonResponse|StreamedResponse
    {
        $this->authorizePermission('reports.view');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'all', 'done', 'overdue'])],
            'format' => ['sometimes', Rule::in(['json', 'csv'])],
        ]);
        $status = $data['status'] ?? 'all';

        $tasks = Task::query()
            ->with(['owner:id,name', 'department:id,name', 'document:id,external_id,title', 'reviewer:id,name'])
            ->withCount(['evidence', 'events as returns_count' => fn ($q) => $q->where('type', 'returned')])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', Task::ACTIVE))
            ->when($status === 'done', fn ($q) => $q->where('status', Task::DONE))
            ->when($status === 'overdue', fn ($q) => $q->whereIn('status', Task::ACTIVE)->whereDate('due_date', '<', today()))
            ->orderByRaw('due_date IS NULL, due_date')->orderBy('id')
            ->limit(5000)->get();

        $rows = $tasks->map(fn (Task $t) => [
            'task_id' => $t->id,
            'title' => $t->title,
            'status' => $t->status,
            'priority' => $t->priority,
            'owner' => $t->owner?->name,
            'department' => $t->department?->name,
            'due_date' => $t->due_date?->toDateString(),
            'deadline_type' => $t->due_date_type,
            'deadline_source' => $t->due_date_basis,
            'overdue' => $t->isOverdue(),
            'evidence_items' => $t->evidence_count,
            'times_returned' => $t->returns_count,
            'submitted_at' => $t->submitted_at?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'accepted_by' => $t->status === Task::DONE ? $t->reviewer?->name : null,
            'source_document' => $t->document?->external_id,
            'source_title' => $t->document?->title,
            'from_assessment_action' => $t->action_id !== null,
        ]);

        $audit->record('report.generated', null, null, ['report' => 'task_status', 'status' => $status, 'format' => $data['format'] ?? 'json', 'rows' => $rows->count()]);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->csv('ruleradar-task-status-'.today()->toDateString().'.csv', $rows);
        }

        return response()->json([
            'meta' => $this->meta() + ['status' => $status],
            'summary' => [
                'tasks' => $rows->count(),
                'active' => $tasks->whereIn('status', Task::ACTIVE)->count(),
                'overdue' => $rows->where('overdue', true)->count(),
                'done' => $tasks->where('status', Task::DONE)->count(),
                'done_without_evidence' => $rows->where('status', Task::DONE)->where('evidence_items', 0)->count(),
                'unassigned' => $tasks->whereIn('status', Task::ACTIVE)->whereNull('owner_id')->count(),
            ],
            'data' => $rows->values(),
        ]);
    }

    /** Everything an auditor needs about one decision, in one place. */
    public function evidencePack(int $documentId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('reports.view');
        $document = RegulatoryDocument::query()->findOrFail($documentId);
        $assessment = ImpactAssessment::query()->where('document_id', $document->id)
            ->with(['actions', 'approver:id,name', 'editor:id,name'])->first();
        abort_if($assessment === null, 404, 'This publication has no assessment yet.');

        $analysis = $assessment->analysis_id ? AiAnalysis::query()->find($assessment->analysis_id) : null;
        $tasks = Task::query()->where('document_id', $document->id)
            ->with(['owner:id,name', 'department:id,name', 'events.actor:id,name', 'evidence.file', 'evidence.submitter:id,name'])
            ->orderBy('id')->get();

        $taskIds = $tasks->pluck('id')->all();
        $trail = AuditEvent::query()
            ->where(function ($q) use ($assessment, $taskIds, $analysis) {
                $q->where(fn ($s) => $s->where('subject_type', 'ImpactAssessment')->where('subject_id', $assessment->id))
                    ->orWhere(fn ($s) => $s->where('subject_type', 'Task')->whereIn('subject_id', $taskIds));
                if ($analysis) {
                    $q->orWhere(fn ($s) => $s->where('subject_type', 'AiAnalysis')->where('subject_id', $analysis->id));
                }
            })
            ->with('actor:id,name')
            ->orderBy('id')->get();

        $chain = AuditLogger::verify((int) $this->tenant()->organizationId());
        $audit->record('report.generated', $assessment, null, ['report' => 'evidence_pack', 'document' => $document->external_id]);

        return response()->json([
            'meta' => $this->meta(),
            'document' => [
                'id' => $document->id,
                'document_number' => $document->external_id,
                'citation' => $document->citation,
                'title' => $document->title,
                'type' => $document->type,
                'publication_date' => $document->publication_date->toDateString(),
                'effective_date' => $document->effective_date?->toDateString(),
                'comments_close_date' => $document->comments_close_date?->toDateString(),
                'current_version' => $document->current_version,
                'html_url' => $document->html_url,
                'official_pdf_url' => $document->official_pdf_url,
            ],
            'assessment' => app(AssessmentController::class)->present($assessment, $document),
            'analysis' => $analysis ? app(AnalysisController::class)->present($analysis) : null,
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'owner' => $t->owner?->name,
                'department' => $t->department?->name,
                'due_date' => $t->due_date?->toDateString(),
                'due_date_type' => $t->due_date_type,
                'due_date_basis' => $t->due_date_basis,
                'completed_at' => $t->completed_at?->toIso8601String(),
                'evidence' => $t->evidence->map(fn ($e) => [
                    'note' => $e->note,
                    'submitted_by' => $e->submitter?->name,
                    'created_at' => $e->created_at->toIso8601String(),
                    'file' => $e->file ? ['filename' => $e->file->filename, 'size' => $e->file->size, 'sha256' => $e->file->sha256, 'scan_status' => $e->file->scan_status] : null,
                ])->values(),
                'history' => $t->events->map(fn (TaskEvent $e) => ['type' => $e->type, 'actor' => $e->actor?->name, 'created_at' => $e->created_at->toIso8601String()])->values(),
            ])->values(),
            'audit_trail' => $trail->map(fn (AuditEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'actor' => $e->actor?->name,
                'created_at' => $e->created_at->toIso8601String(),
                'hash' => $e->hash,
            ])->values(),
            'audit_chain' => $chain,
        ]);
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        return [
            'organization' => $this->tenant()->organization()?->name,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => auth()->user()?->name,
            'notice' => 'Generated from records stored in RuleRadar. AI analysis is labelled as such and is not legal advice; decisions shown are the organization\'s own.',
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function csv(string $filename, Collection $rows): StreamedResponse
    {
        $header = $rows->isEmpty() ? ['no_rows'] : array_keys($rows->first());

        return Csv::download($filename, $header, $rows->map(fn ($r) => array_values($r))->all());
    }
}
