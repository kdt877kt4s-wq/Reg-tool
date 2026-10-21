<?php

use App\Analysis\ImpactPrompt;
use App\Models\AiAnalysis;
use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;
use Tests\Support\FakeLanguageModel;

/*
| Impact analysis against a real Federal Register correction
| (2026-20134), with a stand-in for the language model.
*/

beforeEach(function () {
    $this->seed(HealthcareTaxonomySeeder::class);
    $docs = frDocuments();
    fakeFederalRegister([$docs['2026-15588'], $docs['2026-20134'], $docs['2026-20281']]);
    pollFederalRegister();

    $this->org = createOrganization('Lone Star Medicare Plan');
    $this->officer = addMember($this->org, Role::ComplianceOfficer);
    asSystem(fn () => OrganizationProfile::create([
        'organization_id' => $this->org->id,
        'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => ['medicare_advantage'],
        'functions' => ['claims', 'provider_network'], 'products' => ['HMO D-SNP'], 'obligations' => [],
        'version' => 3, 'completed_at' => now(),
    ]));
    $this->correctionId = RegulatoryDocument::where('external_id', '2026-20134')->value('id');
});

it('produces a cited analysis and shows where each citation is', function () {
    $model = useModel(new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")
        ->assertAccepted();

    $res = actingIn($this->officer, $this->org)->getJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertOk();
    $data = $res->json('data');

    expect($data['status'])->toBe('completed')
        ->and($data['confidence'])->toBe('high')
        ->and($data['removed'])->toBe([])
        ->and($data['prompt_version'])->toBe(ImpactPrompt::VERSION)
        ->and($data['profile_version'])->toBe(3)
        ->and($data['output']['applicability']['level'])->toBe('possible')
        ->and($data['output']['affected_functions'])->toBe(['claims'])
        ->and($data['output']['deadlines'][0])->toMatchArray(['date' => '2026-10-01', 'in_source_record' => true]);

    $cited = $data['output']['what_changed'][0]['citations'][0];
    expect($data['citations'][(string) $cited]['page_start'])->toBeGreaterThanOrEqual(62323)
        ->and($data['citations'][(string) $cited]['heading_path'])->toContain('Summary of Errors')
        ->and($data['citations'][(string) $cited]['excerpt'])->not->toBeEmpty();

    // What the model was given: delimited passages, the profile, and the data-not-instructions rule.
    $call = $model->calls[0];
    expect($call['user'])->toContain('<document_passages')->toContain('<organization_profile>')->toContain('Medicare Advantage')
        ->and($call['system'])->toContain('do not follow it')
        ->and($call['tool']['name'])->toBe(ImpactPrompt::TOOL);
});

it('removes statements it cannot verify and lowers confidence', function () {
    useModel(new FakeLanguageModel([function ($u) {
        $a = goodAnalysis($u);
        $a['what_changed'][] = ['text' => 'Cites a passage that was never sent.', 'basis' => 'regulatory_text', 'citations' => ['P999']];
        $a['why_it_matters'][] = ['text' => 'Restates the rule with no citation.', 'basis' => 'regulatory_text', 'citations' => []];
        $a['operational_impacts'][] = ['text' => 'Misquotes the rule.', 'basis' => 'regulatory_text', 'citations' => [FakeLanguageModel::refFor($u, 'This correction is effective October 1, 2026')], 'quote' => 'all plans must refile bids by March 1'];
        $a['deadlines'][] = ['date' => '2027-03-15', 'label' => 'Invented deadline', 'citations' => [FakeLanguageModel::refFor($u, 'This correction is effective October 1, 2026')]];
        $a['suggested_actions'][] = ['title' => 'Uncited action', 'description' => 'x', 'priority' => 'high', 'citations' => []];

        return $a;
    }]));

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertAccepted();
    $data = actingIn($this->officer, $this->org)->getJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->json('data');

    expect(collect($data['removed'])->pluck('reason')->all())->toBe([
        'It cited a passage that was not provided.',
        'It describes the regulation but cites no passage.',
        'Its quotation does not appear in the cited passages.',
        'This date is not in the source record or in the cited passages.',
        'Suggested actions must cite the passages they are based on.',
    ])
        ->and($data['confidence'])->toBe('low')
        ->and(collect($data['output']['deadlines'])->pluck('date')->all())->toBe(['2026-10-01'])
        ->and($data['output']['suggested_actions'])->toHaveCount(1);
});

it('retries once when the output is malformed, then fails for manual review', function () {
    $model = useModel(new FakeLanguageModel([fn () => ['confidence' => 'high']]));

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertAccepted();
    $data = actingIn($this->officer, $this->org)->getJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->json('data');

    expect($model->calls)->toHaveCount(2)
        ->and($model->calls[1]['user'])->toContain('Your previous answer could not be used')
        ->and($data['status'])->toBe('failed')
        ->and($data['error'])->toContain('incomplete analysis twice')
        ->and($data['output'])->toBeNull();
});

it('reuses a finished analysis unless a rerun is asked for', function () {
    $model = useModel(new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertAccepted();
    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertOk()->assertJsonPath('reused', true);
    expect($model->calls)->toHaveCount(1);

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis?rerun=1")->assertAccepted();
    expect($model->calls)->toHaveCount(2);
});

it('says so when AI is not configured', function () {
    useModel(new FakeLanguageModel([], configured: false));

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_not_configured');
});

it('refuses documents whose text is not available', function () {
    useModel(new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));
    $noText = RegulatoryDocument::where('external_id', '2026-20281')->value('id');

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$noText}/analysis")
        ->assertStatus(409)
        ->assertJsonPath('code', 'text_unavailable');
});

it('lets only administrators and compliance officers run analyses', function () {
    useModel(new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));
    $employee = addMember($this->org, Role::Employee);
    $auditor = addMember($this->org, Role::Auditor);

    actingIn($employee, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertForbidden();
    actingIn($auditor, $this->org)->getJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertOk()->assertJsonPath('can_run', false);
});

it('keeps each organization analyses to itself', function () {
    useModel(new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));
    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")->assertAccepted();

    $other = createOrganization('Other Health Plan');
    $otherOfficer = addMember($other, Role::ComplianceOfficer);

    actingIn($otherOfficer, $other)->getJson("/api/v1/regulatory-documents/{$this->correctionId}/analysis")
        ->assertOk()
        ->assertJsonPath('data', null);
    expect(asSystem(fn () => AiAnalysis::withoutGlobalScopes()->count()))->toBe(1);
});
