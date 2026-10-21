<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\AiAnalysis;
use App\Models\ImpactAssessment;
use App\Models\RegulatoryDocument;
use App\Models\RegulatoryPassage;
use App\Models\SuggestedAction;
use App\Models\TaxonomyTerm;
use App\Tasks\TaskWorkflow;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The human assessment of a document: decision, rationale, affected
 * functions and the actions accepted or dismissed. AI output can seed a
 * draft; only a compliance officer can approve it.
 */
class AssessmentController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('assessments.view');

        $latest = AiAnalysis::query()
            ->selectRaw('DISTINCT ON (document_id) id, document_id, status, confidence, output, created_at, completed_at')
            ->orderBy('document_id')->orderByDesc('id')
            ->get()->keyBy('document_id');
        $assessments = ImpactAssessment::query()->get()->keyBy('document_id');

        $documentIds = $latest->keys()->merge($assessments->keys())->unique()->values();
        $documents = RegulatoryDocument::query()
            ->whereIn('id', $documentIds)
            ->get(['id', 'external_id', 'title', 'type', 'publication_date', 'current_version', 'comments_close_date', 'effective_date'])
            ->keyBy('id');

        $rows = $documentIds->map(function ($id) use ($latest, $assessments, $documents) {
            $doc = $documents[$id] ?? null;
            if (! $doc) {
                return null;
            }
            $analysis = $latest[$id] ?? null;
            $assessment = $assessments[$id] ?? null;

            $group = match (true) {
                $assessment?->status === 'approved' => 'approved',
                $analysis && in_array($analysis->status, [AiAnalysis::QUEUED, AiAnalysis::RUNNING], true) => 'in_progress',
                $analysis?->status === AiAnalysis::FAILED && ! $assessment => 'failed',
                default => 'awaiting_review',
            };

            return [
                'group' => $group,
                'document' => [
                    'id' => $doc->id,
                    'document_number' => $doc->external_id,
                    'title' => $doc->title,
                    'type_label' => RegulatoryDocument::TYPE_LABELS[$doc->type] ?? $doc->type,
                    'publication_date' => $doc->publication_date->toDateString(),
                    'effective_date' => $doc->effective_date?->toDateString(),
                    'comments_close_date' => $doc->comments_close_date?->toDateString(),
                ],
                'analysis' => $analysis ? [
                    'status' => $analysis->status,
                    'confidence' => $analysis->confidence,
                    'applicability' => $analysis->output['applicability']['level'] ?? null,
                ] : null,
                'assessment' => $assessment ? [
                    'status' => $assessment->status,
                    'decision' => $assessment->decision,
                    'source_changed' => $assessment->status === 'approved' && $assessment->document_version !== $doc->current_version,
                    'approved_at' => $assessment->approved_at?->toIso8601String(),
                ] : null,
                'updated_at' => max(
                    $analysis?->created_at?->toIso8601String() ?? '',
                    $assessment?->updated_at?->toIso8601String() ?? '',
                ),
            ];
        })->filter()->sortByDesc('updated_at')->values();

        return response()->json(['data' => $rows]);
    }

    public function show(int $documentId): JsonResponse
    {
        $this->authorizePermission('assessments.view');
        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version']);

        $assessment = ImpactAssessment::query()->with(['actions', 'approver:id,name', 'editor:id,name'])->where('document_id', $document->id)->first();

        return response()->json(['data' => $assessment ? $this->present($assessment, $document) : null]);
    }

    /** Start a draft from the latest completed analysis (or empty when there is none). */
    public function start(int $documentId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('assessments.edit');
        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version']);

        if (ImpactAssessment::query()->where('document_id', $document->id)->exists()) {
            return response()->json(['message' => 'An assessment already exists for this document.', 'code' => 'assessment_exists'], 409);
        }

        $analysis = AiAnalysis::query()->where('document_id', $document->id)->where('status', AiAnalysis::COMPLETED)->latest('id')->first();

        $assessment = DB::transaction(function () use ($document, $analysis, $audit) {
            $assessment = ImpactAssessment::create([
                'document_id' => $document->id,
                'analysis_id' => $analysis?->id,
                'document_version' => $document->current_version,
                'affected_functions' => $analysis->output['affected_functions'] ?? [],
                'status' => 'draft',
                'updated_by' => $this->tenant()->userId(),
            ]);

            foreach ($analysis->output['suggested_actions'] ?? [] as $i => $action) {
                SuggestedAction::create([
                    'assessment_id' => $assessment->id,
                    'title' => $action['title'],
                    'description' => $action['description'] ?? null,
                    'priority' => $action['priority'] ?? 'medium',
                    'citation_passage_ids' => $action['citations'] ?? [],
                    'origin' => 'ai',
                    'state' => 'proposed',
                    'position' => $i,
                ]);
            }

            $audit->record('assessment.started', $assessment, null, ['document_id' => $document->id, 'from_analysis' => $analysis?->id]);

            return $assessment;
        });

        return response()->json(['data' => $this->present($assessment->load(['actions', 'approver:id,name', 'editor:id,name']), $document)], 201);
    }

    public function update(Request $request, int $documentId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('assessments.edit');
        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version']);
        $assessment = ImpactAssessment::query()->where('document_id', $document->id)->firstOrFail();

        if ($assessment->status === 'approved') {
            return response()->json(['message' => 'This assessment is approved. Reopen it to make changes.', 'code' => 'assessment_approved'], 409);
        }

        $functionCodes = TaxonomyTerm::query()->where('kind', 'function')->pluck('code')->all();
        $data = $request->validate([
            'decision' => ['present', 'nullable', Rule::in(ImpactAssessment::DECISIONS)],
            'rationale' => ['present', 'nullable', 'string', 'max:10000'],
            'affected_functions' => ['present', 'array'],
            'affected_functions.*' => ['string', Rule::in($functionCodes)],
            'actions' => ['present', 'array', 'max:50'],
            'actions.*.id' => ['nullable', 'integer'],
            'actions.*.title' => ['required', 'string', 'min:3', 'max:200'],
            'actions.*.description' => ['nullable', 'string', 'max:4000'],
            'actions.*.priority' => ['required', Rule::in(['high', 'medium', 'low'])],
            'actions.*.state' => ['required', Rule::in(['proposed', 'accepted', 'dismissed'])],
            'actions.*.citation_passage_ids' => ['present', 'array', 'max:20'],
            'actions.*.citation_passage_ids.*' => ['integer', $this->passageOf($document->id)],
        ]);

        DB::transaction(function () use ($assessment, $data, $audit) {
            $before = $this->snapshot($assessment);
            $assessment->update([
                'decision' => $data['decision'],
                'rationale' => $data['rationale'] !== null ? trim($data['rationale']) : null,
                'affected_functions' => array_values(array_unique($data['affected_functions'])),
                'updated_by' => $this->tenant()->userId(),
            ]);

            $keep = [];
            foreach (array_values($data['actions']) as $i => $item) {
                $fields = [
                    'title' => trim($item['title']),
                    'description' => isset($item['description']) ? trim($item['description']) : null,
                    'priority' => $item['priority'],
                    'state' => $item['state'],
                    'citation_passage_ids' => array_values(array_unique($item['citation_passage_ids'])),
                    'position' => $i,
                ];
                $existing = isset($item['id']) ? $assessment->actions()->whereKey($item['id'])->first() : null;
                if ($existing) {
                    $existing->update($fields);
                    $keep[] = $existing->id;
                } else {
                    $keep[] = SuggestedAction::create($fields + ['assessment_id' => $assessment->id, 'origin' => 'person'])->id;
                }
            }
            // Actions left out of the list were deleted by the editor.
            $assessment->actions()->whereNotIn('id', $keep)->delete();

            $audit->record('assessment.updated', $assessment, $before, $this->snapshot($assessment->fresh()));
        });

        return response()->json(['data' => $this->present($assessment->fresh(['actions', 'approver:id,name', 'editor:id,name']), $document)]);
    }

    public function approve(int $documentId, AuditLogger $audit, TaskWorkflow $tasks): JsonResponse
    {
        $this->authorizePermission('assessments.approve');
        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version']);
        $assessment = ImpactAssessment::query()->with('actions')->where('document_id', $document->id)->firstOrFail();

        $problems = [];
        if ($assessment->decision === null) {
            $problems['decision'] = 'Choose a decision before approving.';
        }
        if (mb_strlen(trim((string) $assessment->rationale)) < 20) {
            $problems['rationale'] = 'Explain the decision in at least a sentence before approving.';
        }
        if ($assessment->actions->contains('state', 'proposed')) {
            $problems['actions'] = 'Accept or dismiss every suggested action before approving.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $before = $this->snapshot($assessment);
        $assessment->update([
            'status' => 'approved',
            'approved_by' => $this->tenant()->userId(),
            'approved_at' => now(),
            'document_version' => $document->current_version,
        ]);
        $audit->record('assessment.approved', $assessment, $before, $this->snapshot($assessment->fresh()));

        // Approval is the human sign-off: accepted actions become tasks.
        $created = $tasks->syncFromAssessment($assessment);

        return response()->json([
            'data' => $this->present($assessment->fresh(['actions', 'approver:id,name', 'editor:id,name']), $document),
            'tasks_created' => $created,
        ]);
    }

    public function reopen(int $documentId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('assessments.approve');
        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version']);
        $assessment = ImpactAssessment::query()->where('document_id', $document->id)->firstOrFail();

        if ($assessment->status !== 'approved') {
            return response()->json(['message' => 'Only an approved assessment can be reopened.', 'code' => 'not_approved'], 409);
        }

        $before = $this->snapshot($assessment);
        $assessment->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'updated_by' => $this->tenant()->userId()]);
        $audit->record('assessment.reopened', $assessment, $before, $this->snapshot($assessment->fresh()));

        return response()->json(['data' => $this->present($assessment->fresh(['actions', 'approver:id,name', 'editor:id,name']), $document)]);
    }

    /** A passage of this document. */
    private function passageOf(int $documentId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($documentId) {
            if (! RegulatoryPassage::query()->where('document_id', $documentId)->whereKey($value)->exists()) {
                $fail('A cited passage is not part of this document.');
            }
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(ImpactAssessment $a): array
    {
        return [
            'status' => $a->status,
            'decision' => $a->decision,
            'rationale' => $a->rationale,
            'affected_functions' => $a->affected_functions,
            'document_version' => $a->document_version,
            'actions' => $a->actions()->get(['title', 'priority', 'state'])->map(fn ($x) => [$x->title, $x->priority, $x->state])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(ImpactAssessment $a, RegulatoryDocument $document): array
    {
        return [
            'id' => $a->id,
            'status' => $a->status,
            'decision' => $a->decision,
            'rationale' => $a->rationale,
            'affected_functions' => $a->affected_functions,
            'analysis_id' => $a->analysis_id,
            'document_version' => $a->document_version,
            'source_changed' => $a->document_version !== $document->current_version,
            'approved_by' => $a->approver?->name,
            'approved_at' => $a->approved_at?->toIso8601String(),
            'updated_by' => $a->editor?->name,
            'updated_at' => $a->updated_at->toIso8601String(),
            'actions' => $a->actions->map(fn (SuggestedAction $x) => [
                'id' => $x->id,
                'title' => $x->title,
                'description' => $x->description,
                'priority' => $x->priority,
                'state' => $x->state,
                'origin' => $x->origin,
                'citation_passage_ids' => $x->citation_passage_ids,
            ])->values(),
        ];
    }
}
