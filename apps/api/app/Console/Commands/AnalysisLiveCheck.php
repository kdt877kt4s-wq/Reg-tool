<?php

namespace App\Console\Commands;

use App\Ai\LanguageModel;
use App\Analysis\ImpactPrompt;
use App\Jobs\RunImpactAnalysis;
use App\Models\AiAnalysis;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Runs one real analysis against the configured model, for a throwaway
 * test organization, and prints how validation treated the output.
 * For CI and staging checks only; never run against production data.
 */
class AnalysisLiveCheck extends Command
{
    protected $signature = 'ruleradar:analysis-live-check {document? : Federal Register document number (default: newest with text)}';

    protected $description = 'Run one real AI impact analysis for a test organization and report the validation result';

    public function handle(LanguageModel $model, TenantContext $context): int
    {
        if (app()->isProduction()) {
            $this->error('Not for production.');

            return self::FAILURE;
        }
        if (! $model->configured()) {
            $this->line(json_encode(['skipped' => 'ANTHROPIC_API_KEY is not set']));

            return self::SUCCESS;
        }

        $document = RegulatoryDocument::query()
            ->where('text_status', 'available')
            ->when($this->argument('document'), fn ($q, $n) => $q->where('external_id', $n))
            ->orderByDesc('publication_date')
            ->first();
        if (! $document) {
            $this->error('No document with text is available. Run ruleradar:poll first.');

            return self::FAILURE;
        }

        $organization = $context->bypass(function () {
            $org = Organization::create(['name' => 'Live check: Medicare Advantage plan', 'slug' => 'live-check-'.Str::lower(Str::random(6))]);
            OrganizationProfile::create([
                'organization_id' => $org->id,
                'org_types' => ['medicare_advantage_org', 'part_d_sponsor'],
                'states' => ['TX', 'LA'],
                'programs' => ['medicare_advantage', 'medicare_part_d'],
                'functions' => ['claims', 'utilization_management', 'appeals_grievances', 'quality_star_ratings'],
                'products' => ['HMO D-SNP'],
                'obligations' => [],
                'version' => 1,
                'completed_at' => now(),
            ]);

            return $org;
        });

        $analysis = $context->bypass(fn () => AiAnalysis::create([
            'organization_id' => $organization->id,
            'document_id' => $document->id,
            'version_id' => $document->versions()->where('version_no', $document->current_version)->value('id'),
            'profile_version' => 1,
            'status' => AiAnalysis::QUEUED,
            'prompt_version' => ImpactPrompt::VERSION,
        ]));

        RunImpactAnalysis::dispatchSync($analysis->id, $organization->id);

        $result = $context->bypass(fn () => AiAnalysis::query()->withoutGlobalScope('organization')->findOrFail($analysis->id));
        $output = $result->output ?? [];

        $this->line(json_encode([
            'document' => $document->external_id.' '.mb_substr($document->title, 0, 90),
            'status' => $result->status,
            'error' => $result->error,
            'model' => $result->model,
            'confidence' => $result->confidence,
            'applicability' => $output['applicability']['level'] ?? null,
            'passages_sent' => $result->validation['passages_sent'] ?? null,
            'kept' => $result->validation['kept'] ?? null,
            'removed' => array_map(fn ($r) => $r['reason'], $result->validation['removed'] ?? []),
            'counts' => collect(['what_changed', 'why_it_matters', 'operational_impacts', 'policies_to_review', 'deadlines', 'suggested_actions'])
                ->mapWithKeys(fn ($k) => [$k => count($output[$k] ?? [])]),
            'first_statement' => $output['what_changed'][0]['text'] ?? null,
            'tokens' => ['input' => $result->input_tokens, 'output' => $result->output_tokens],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $result->status === AiAnalysis::COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
