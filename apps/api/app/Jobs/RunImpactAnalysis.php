<?php

namespace App\Jobs;

use App\Ai\AiRequestFailed;
use App\Ai\LanguageModel;
use App\Analysis\AnalysisValidator;
use App\Analysis\ImpactPrompt;
use App\Analysis\InvalidAnalysis;
use App\Analysis\PassageSelector;
use App\Analysis\ProfileFacts;
use App\Models\AiAnalysis;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RegulatoryDocument;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one impact analysis inside its organization's tenant context, so
 * row-level security applies to everything it reads and writes. Invalid
 * output is retried once with the problem stated; then the analysis is
 * marked failed for manual review rather than shown half-checked.
 */
class RunImpactAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $analysisId, public int $organizationId) {}

    public function handle(LanguageModel $model, PassageSelector $selector, AnalysisValidator $validator, TenantContext $context): void
    {
        $previous = $context->snapshot();

        try {
            $this->run($model, $selector, $validator, $context);
        } finally {
            // A synchronous run (tests, local) must not leave the caller's context changed.
            $context->restore($previous);
        }
    }

    private function run(LanguageModel $model, PassageSelector $selector, AnalysisValidator $validator, TenantContext $context): void
    {
        // Queue workers are console processes: leave system mode and act
        // as the organization the analysis belongs to.
        $context->setBypass(false);
        $organization = $context->bypass(fn () => Organization::query()->findOrFail($this->organizationId));
        $context->setOrganization($organization, new Membership(['organization_id' => $organization->id]));

        $analysis = AiAnalysis::query()->find($this->analysisId);
        if (! $analysis || $analysis->status === AiAnalysis::COMPLETED) {
            return;
        }
        $analysis->update(['status' => AiAnalysis::RUNNING, 'attempts' => $analysis->attempts + 1]);

        $document = RegulatoryDocument::query()->findOrFail($analysis->document_id);
        $profile = ProfileFacts::current();
        $passages = $selector->select($analysis->version_id, $profile['query'], (int) config('ai.analysis.max_passages'), (int) config('ai.analysis.max_passage_chars'));

        if ($passages->isEmpty()) {
            $this->markFailed($analysis, 'The document text is not available yet, so there is nothing to analyze.');

            return;
        }

        $matches = array_values(array_intersect($document->cfr_parts, $profile['cfr_parts']));
        $prompt = ImpactPrompt::user($document, $passages, $profile['facts'], $matches);
        $tool = ImpactPrompt::tool($profile['functions']);
        $stated = array_values(array_filter([
            $document->publication_date->toDateString(),
            $document->effective_date?->toDateString(),
            $document->comments_close_date?->toDateString(),
        ]));
        $texts = $passages->pluck('text', 'id')->all();

        $analysis->update([
            'profile_version' => $profile['version'],
            'passage_refs' => $prompt['refs'],
            'input_hash' => hash('sha256', ImpactPrompt::VERSION.$prompt['text']),
            'model' => config('ai.models.analysis'),
        ]);

        $userText = $prompt['text'];
        $lastProblem = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $result = $model->callTool((string) config('ai.models.analysis'), ImpactPrompt::system(), $userText, $tool, (int) config('ai.analysis.max_output_tokens'));
                $checked = $validator->validate($result->input, $prompt['refs'], $texts, $stated, $profile['functions']);

                $analysis->update([
                    'status' => AiAnalysis::COMPLETED,
                    'model' => $result->model,
                    'output' => $checked['output'],
                    'validation' => $checked['validation'] + ['passages_sent' => count($prompt['refs']), 'attempt' => $attempt],
                    'confidence' => $checked['confidence'],
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                    'error' => null,
                    'completed_at' => now(),
                ]);
                Log::info('analysis.completed', ['analysis' => $analysis->id, 'removed' => count($checked['validation']['removed'])]);

                return;
            } catch (InvalidAnalysis $e) {
                $lastProblem = $e->getMessage();
                $userText = $prompt['text']."\n\nYour previous answer could not be used: {$lastProblem} Call record_impact_analysis again with every required field.";
            } catch (AiRequestFailed $e) {
                if ($e->retryable && $this->attempts() < $this->tries) {
                    $analysis->update(['status' => AiAnalysis::QUEUED, 'error' => $e->getMessage()]);
                    $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                    return;
                }
                $this->markFailed($analysis, $e->getMessage());

                return;
            }
        }

        $this->markFailed($analysis, "The AI returned an incomplete analysis twice ({$lastProblem}). Run it again, or assess the document manually.");
    }

    public function failed(?Throwable $e): void
    {
        $context = app(TenantContext::class);
        $context->bypass(fn () => AiAnalysis::query()->withoutGlobalScope('organization')->whereKey($this->analysisId)
            ->where('status', '!=', AiAnalysis::COMPLETED)
            ->update(['status' => AiAnalysis::FAILED, 'error' => 'The analysis stopped unexpectedly. Run it again.']));
    }

    private function markFailed(AiAnalysis $analysis, string $message): void
    {
        $analysis->update(['status' => AiAnalysis::FAILED, 'error' => $message]);
        Log::warning('analysis.failed', ['analysis' => $analysis->id, 'error' => $message]);
    }
}
