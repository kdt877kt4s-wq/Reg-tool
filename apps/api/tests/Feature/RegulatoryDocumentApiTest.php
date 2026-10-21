<?php

use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Models\RegulatoryPassage;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;

beforeEach(function () {
    $this->seed(HealthcareTaxonomySeeder::class);
    $docs = frDocuments();
    fakeFederalRegister(array_values($docs));
    pollFederalRegister();

    $this->org = createOrganization('Lone Star Medicare Plan');
    $this->employee = addMember($this->org, Role::Employee);
});

function setProfilePrograms($org, array $programs): void
{
    asSystem(fn () => OrganizationProfile::create([
        'organization_id' => $org->id,
        'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => $programs,
        'functions' => [], 'products' => [], 'obligations' => [], 'version' => 1, 'completed_at' => now(),
    ]));
}

it('lists retrieved publications newest first with official links', function () {
    $data = actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents')
        ->assertOk()
        ->json('data');

    $dates = collect($data)->pluck('publication_date')->all();
    expect($dates)->toBe(collect($dates)->sortDesc()->values()->all())
        ->and($data[0]['official_pdf_url'])->toStartWith('https://www.govinfo.gov/')
        ->and(collect($data)->pluck('is_demo')->unique()->all())->toBe([false]);
});

it('searches by words, document number and citation', function () {
    $words = actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents?q=psychiatric')->json('data');
    expect(collect($words)->pluck('document_number')->all())->toContain('2026-20134', '2026-15588')->not->toContain('2026-20281');

    actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents?q=2026-20281')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Global Benchmark for Efficient Drug Pricing (GLOBE) Model');

    actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents?q='.urlencode('91 FR 62323'))
        ->assertJsonPath('data.0.document_number', '2026-20134');
});

it('filters by type and status', function () {
    $proposed = actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents?type=proposed_rule')->json('data');
    expect(collect($proposed)->pluck('type')->unique()->all())->toBe(['proposed_rule']);

    actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents?status=corrected')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.document_number', '2026-15588');
});

it('shows which publications amend a CFR part linked to the profile', function () {
    setProfilePrograms($this->org, ['medicare_advantage']);

    $res = actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents?relevance=profile')->assertOk();
    $numbers = collect($res->json('data'))->pluck('document_number');

    expect($res->json('meta.profile_cfr_parts'))->toBe(['42 CFR 422'])
        ->and($numbers)->toContain('2026-21420')
        ->and($numbers)->not->toContain('2026-20281')
        ->and($res->json('data.0.profile_matches'))->toBe(['42 CFR 422']);
});

it('says when the profile cannot be used to filter', function () {
    actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents?relevance=profile')
        ->assertOk()
        ->assertJsonPath('meta.profile_filter_unavailable', true)
        ->assertJsonPath('data', []);
});

it('shows a document with versions, relations and its text', function () {
    $id = RegulatoryDocument::where('external_id', '2026-20134')->value('id');

    $doc = actingIn($this->employee, $this->org)->getJson("/api/v1/regulatory-documents/{$id}")->assertOk()->json('data');

    expect($doc['action'])->toBe('Final rule; correction.')
        ->and($doc['passage_count'])->toBeGreaterThanOrEqual(10)
        ->and($doc['versions'][0]['version'])->toBe(1)
        ->and($doc['relations'][0])->toMatchArray(['direction' => 'outgoing', 'relation' => 'corrects', 'document_number' => '2026-15588', 'method' => 'parsed_from_text'])
        ->and($doc['source']['name'])->toBe('Federal Register');
});

it('searches inside a document and marks matches in plain text', function () {
    $id = RegulatoryDocument::where('external_id', '2026-20134')->value('id');

    $passages = actingIn($this->employee, $this->org)->getJson("/api/v1/regulatory-documents/{$id}/passages?q=".urlencode('wage index'))
        ->assertOk()
        ->json('data');

    expect($passages)->not->toBeEmpty()
        ->and($passages[0]['snippet'])->toContain('[[[')
        ->and($passages[0]['snippet'])->not->toContain('<b>');
});

it('opens the page of text that contains a cited passage', function () {
    $id = RegulatoryDocument::where('external_id', '2026-20134')->value('id');
    $passage = RegulatoryPassage::where('document_id', $id)->orderByDesc('ordinal')->first();

    actingIn($this->employee, $this->org)->getJson("/api/v1/regulatory-documents/{$id}/passages?around={$passage->id}")
        ->assertOk()
        ->assertJsonPath('meta.page', 1)
        ->assertJsonFragment(['id' => $passage->id]);
});

it('reports source health', function () {
    actingIn($this->employee, $this->org)->getJson('/api/v1/sources')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'federal_register')
        ->assertJsonPath('data.0.status', 'healthy')
        ->assertJsonPath('data.0.stale', false)
        ->assertJsonPath('data.0.documents', RegulatoryDocument::count());
});

it('lists agencies and types for filters', function () {
    $facets = actingIn($this->employee, $this->org)->getJson('/api/v1/regulatory-documents/facets')->assertOk()->json();

    expect(collect($facets['agencies'])->pluck('slug'))->toContain('centers-for-medicare-medicaid-services')
        ->and(collect($facets['types'])->pluck('type'))->toContain('rule', 'proposed_rule');
});

it('requires sign-in', function () {
    $this->getJson('/api/v1/regulatory-documents')->assertUnauthorized();
});
