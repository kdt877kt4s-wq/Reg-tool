<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\LanguageModel;
use App\Analysis\ImpactPrompt;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Jobs\RunImpactAnalysis;
use App\Models\AiAnalysis;
use App\Models\ImpactAssessment;
use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Models\RegulatoryPassage;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI impact analyses for the current organization. Raw AI output is
 * returned with every citation resolved to a stored passage, clearly
 * separated from the human assessment.
 */
class AnalysisController extends Controller
{
    public function store(Request $request, int $documentId, LanguageModel $model, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('analysis.run');

        if (! $model->configured()) {
            return response()->json([
                'message' => 'AI analysis is not configured for this installation. An administrator needs to add the AI service key.',
                'code' => 'ai_not_configured',
            ], 503);
        }

        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version', 'text_status']);
        if ($document->text_status !== 'available') {
            return response()->json([
                'message' => $document->text_status === 'pending'
                    ? 'The full text is still being retrieved. Try again shortly.'
                    : 'The Federal Register offers no plain text for this document, so it cannot be analyzed automatically.',
                'code' => 'text_unavailable',
            ], 409);
        }

        $versionId = (int) $document->versions()->where('version_no', $document->current_version)->value('id');
        $profileVersion = (int) (OrganizationProfile::query()->value('version') ?? 0);

        // One analysis per document version, profile version and prompt
        // version unless a new one is asked for explicitly.
        $existing = AiAnalysis::query()
            ->where('document_id', $document->id)
            ->where('version_id', $versionId)
            ->where('profile_version', $profileVersion)
            ->where('prompt_version', ImpactPrompt::VERSION)
            ->whereIn('status', [AiAnalysis::QUEUED, AiAnalysis::RUNNING, AiAnalysis::COMPLETED])
            ->latest('id')
            ->first();

        if ($existing && ! $request->boolean('rerun')) {
            return response()->json(['data' => $this->present($existing), 'reused' => true], 200);
        }

        $analysis = AiAnalysis::create([
            'document_id' => $document->id,
            'version_id' => $versionId,
            'profile_version' => $profileVersion,
            'status' => AiAnalysis::QUEUED,
            'prompt_version' => ImpactPrompt::VERSION,
            'requested_by' => $this->tenant()->userId(),
        ]);
        $audit->record('analysis.requested', $analysis, null, ['document_id' => $document->id, 'version_id' => $versionId]);

        RunImpactAnalysis::dispatch($analysis->id, (int) $this->tenant()->organizationId());

        return response()->json(['data' => $this->present($analysis->fresh())], 202);
    }

    public function show(int $documentId): JsonResponse
    {
        $this->authorizePermission('assessments.view');

        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version']);
        $analysis = AiAnalysis::query()->where('document_id', $document->id)->latest('id')->first();

        return response()->json([
            'data' => $analysis ? $this->present($analysis) : null,
            'can_run' => Permissions::allows($this->tenant()->role(), 'analysis.run'),
            'configured' => app(LanguageModel::class)->configured(),
        ]);
    }

    /** @return array<string, mixed> */
    public function present(AiAnalysis $analysis): array
    {
        $output = $analysis->output;

        return [
            'id' => $analysis->id,
            'status' => $analysis->status,
            'error' => $analysis->status === AiAnalysis::FAILED ? $analysis->error : null,
            'model' => $analysis->model,
            'prompt_version' => $analysis->prompt_version,
            'profile_version' => $analysis->profile_version,
            'document_version' => $analysis->version_id,
            'confidence' => $analysis->confidence,
            'output' => $output,
            'citations' => $output ? $this->citations($output) : [],
            'removed' => $analysis->validation['removed'] ?? [],
            'passages_sent' => $analysis->validation['passages_sent'] ?? null,
            'created_at' => $analysis->created_at->toIso8601String(),
            'completed_at' => $analysis->completed_at?->toIso8601String(),
            'has_assessment' => ImpactAssessment::query()->where('document_id', $analysis->document_id)->exists(),
        ];
    }

    /**
     * Every passage id cited anywhere in the output, with where it is.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, array<string, mixed>>
     */
    private function citations(array $output): array
    {
        $ids = [];
        array_walk_recursive($output, function ($value, $key) use (&$ids) {
            if (is_int($key) && is_int($value)) {
                $ids[] = $value;
            }
        });
        $ids = array_values(array_unique($ids));

        return RegulatoryPassage::query()
            ->whereIn('id', $ids)
            ->get(['id', 'ordinal', 'heading_path', 'page_start', 'page_end', 'text'])
            ->mapWithKeys(fn (RegulatoryPassage $p) => [(string) $p->id => [
                'id' => $p->id,
                'ordinal' => $p->ordinal,
                'heading_path' => $p->heading_path,
                'page_start' => $p->page_start,
                'page_end' => $p->page_end,
                'excerpt' => mb_substr(preg_replace('/\s+/', ' ', $p->text) ?? '', 0, 280),
            ]])
            ->all();
    }
}
