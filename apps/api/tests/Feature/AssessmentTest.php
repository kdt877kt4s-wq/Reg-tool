<?php

use App\Ai\LanguageModel;
use App\Models\AuditEvent;
use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Models\RegulatoryPassage;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;
use Tests\Support\FakeLanguageModel;

beforeEach(function () {
    $this->seed(HealthcareTaxonomySeeder::class);
    $this->docs = frDocuments();
    fakeFederalRegister([$this->docs['2026-20134'], $this->docs['2026-20281']]);
    pollFederalRegister();

    $this->org = createOrganization('Lone Star Medicare Plan');
    $this->officer = addMember($this->org, Role::ComplianceOfficer);
    $this->admin = addMember($this->org, Role::OrgAdmin);
    asSystem(fn () => OrganizationProfile::create([
        'organization_id' => $this->org->id,
        'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => ['medicare_advantage'],
        'functions' => ['claims'], 'products' => [], 'obligations' => [], 'version' => 1, 'completed_at' => now(),
    ]));

    $this->docId = RegulatoryDocument::where('external_id', '2026-20134')->value('id');
    $this->passageId = RegulatoryPassage::where('document_id', $this->docId)->orderBy('ordinal')->value('id');

    app()->instance(LanguageModel::class, new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));
    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->docId}/analysis")->assertAccepted();
    $this->base = "/api/v1/regulatory-documents/{$this->docId}/assessment";
});

/** The payload the editor sends, built from the current assessment. */
function editorPayload(array $assessment, array $overrides = []): array
{
    return array_merge([
        'decision' => 'monitor',
        'rationale' => 'We do not contract with IPFs at Medicare rates today; revisit at contract renewal.',
        'affected_functions' => ['claims'],
        'actions' => array_map(fn ($a) => [
            'id' => $a['id'], 'title' => $a['title'], 'description' => $a['description'],
            'priority' => $a['priority'], 'state' => 'accepted', 'citation_passage_ids' => $a['citation_passage_ids'],
        ], $assessment['actions']),
    ], $overrides);
}

it('starts a draft from the analysis with AI actions waiting for a decision', function () {
    $data = actingIn($this->officer, $this->org)->postJson($this->base)->assertCreated()->json('data');

    expect($data['status'])->toBe('draft')
        ->and($data['decision'])->toBeNull()
        ->and($data['affected_functions'])->toBe(['claims'])
        ->and($data['actions'])->toHaveCount(1)
        ->and($data['actions'][0])->toMatchArray(['origin' => 'ai', 'state' => 'proposed']);
});

it('saves edits, including a person-added action citing this document', function () {
    $draft = actingIn($this->officer, $this->org)->postJson($this->base)->json('data');
    $payload = editorPayload($draft);
    $payload['actions'][] = ['title' => 'Brief the claims team', 'description' => null, 'priority' => 'low', 'state' => 'accepted', 'citation_passage_ids' => [$this->passageId]];

    $data = actingIn($this->officer, $this->org)->putJson($this->base, $payload)->assertOk()->json('data');

    expect($data['decision'])->toBe('monitor')
        ->and(collect($data['actions'])->pluck('origin')->all())->toBe(['ai', 'person'])
        ->and($data['updated_by'])->toBe($this->officer->name);
});

it('rejects a citation to a passage from another document', function () {
    $draft = actingIn($this->officer, $this->org)->postJson($this->base)->json('data');
    $foreign = RegulatoryPassage::where('document_id', '!=', $this->docId)->value('id')
        ?? RegulatoryPassage::max('id') + 1000;
    $payload = editorPayload($draft);
    $payload['actions'][0]['citation_passage_ids'] = [$foreign];

    actingIn($this->officer, $this->org)->putJson($this->base, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['actions.0.citation_passage_ids.0']);
});

it('requires a decision, a rationale and a decision on every action before approval', function () {
    actingIn($this->officer, $this->org)->postJson($this->base);

    actingIn($this->officer, $this->org)->postJson("{$this->base}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['decision', 'rationale', 'actions']);
});

it('lets only a compliance officer approve, and records it', function () {
    $draft = actingIn($this->officer, $this->org)->postJson($this->base)->json('data');
    actingIn($this->admin, $this->org)->putJson($this->base, editorPayload($draft))->assertOk();

    actingIn($this->admin, $this->org)->postJson("{$this->base}/approve")->assertForbidden();

    $data = actingIn($this->officer, $this->org)->postJson("{$this->base}/approve")->assertOk()->json('data');
    expect($data['status'])->toBe('approved')
        ->and($data['approved_by'])->toBe($this->officer->name)
        ->and($data['source_changed'])->toBeFalse();

    asSystem(fn () => expect(AuditEvent::withoutGlobalScopes()->where('action', 'assessment.approved')->count())->toBe(1));
});

it('locks an approved assessment until it is reopened', function () {
    $draft = actingIn($this->officer, $this->org)->postJson($this->base)->json('data');
    actingIn($this->officer, $this->org)->putJson($this->base, editorPayload($draft));
    actingIn($this->officer, $this->org)->postJson("{$this->base}/approve")->assertOk();

    actingIn($this->officer, $this->org)->putJson($this->base, editorPayload($draft, ['decision' => 'applicable']))
        ->assertStatus(409)->assertJsonPath('code', 'assessment_approved');

    actingIn($this->officer, $this->org)->postJson("{$this->base}/reopen")->assertOk()->assertJsonPath('data.status', 'draft');
    actingIn($this->officer, $this->org)->putJson($this->base, editorPayload($draft, ['decision' => 'applicable']))->assertOk();
});

it('flags an approved assessment when the source document changes', function () {
    $draft = actingIn($this->officer, $this->org)->postJson($this->base)->json('data');
    actingIn($this->officer, $this->org)->putJson($this->base, editorPayload($draft));
    actingIn($this->officer, $this->org)->postJson("{$this->base}/approve")->assertOk();

    $changed = $this->docs['2026-20134'];
    $changed['dates'] = 'This correction is effective October 1, 2026. Updated.';
    fakeFederalRegister([$changed, $this->docs['2026-20281']]);
    pollFederalRegister();

    actingIn($this->officer, $this->org)->getJson($this->base)->assertJsonPath('data.source_changed', true);

    $row = collect(actingIn($this->officer, $this->org)->getJson('/api/v1/assessments')->json('data'))->firstWhere('document.id', $this->docId);
    expect($row['group'])->toBe('approved')
        ->and($row['assessment']['source_changed'])->toBeTrue();
});

it('keeps employees out of editing', function () {
    $employee = addMember($this->org, Role::Employee);
    actingIn($employee, $this->org)->postJson($this->base)->assertForbidden();
    actingIn($employee, $this->org)->getJson($this->base)->assertOk();
});

it('groups the review queue', function () {
    $rows = collect(actingIn($this->officer, $this->org)->getJson('/api/v1/assessments')->assertOk()->json('data'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['group'])->toBe('awaiting_review')
        ->and($rows[0]['analysis'])->toMatchArray(['status' => 'completed', 'applicability' => 'possible']);
});
