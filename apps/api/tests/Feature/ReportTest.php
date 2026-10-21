<?php

use App\Ai\LanguageModel;
use App\Models\AuditEvent;
use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Reports\Csv;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeLanguageModel;

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');
    $this->seed(HealthcareTaxonomySeeder::class);
    $docs = frDocuments();
    fakeFederalRegister([$docs['2026-20134'], $docs['2026-20281']]);
    pollFederalRegister();

    $this->org = createOrganization('Lone Star Medicare Plan');
    $this->officer = addMember($this->org, Role::ComplianceOfficer);
    $this->employee = addMember($this->org, Role::Employee);
    $this->auditor = addMember($this->org, Role::Auditor);
    asSystem(fn () => OrganizationProfile::create([
        'organization_id' => $this->org->id,
        'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => ['medicare_advantage'],
        'functions' => ['claims'], 'products' => [], 'obligations' => [], 'version' => 1, 'completed_at' => now(),
    ]));

    // An approved assessment with one evidenced, accepted task.
    $this->doc = RegulatoryDocument::where('external_id', '2026-20134')->firstOrFail();
    app()->instance(LanguageModel::class, new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));
    $base = "/api/v1/regulatory-documents/{$this->doc->id}/assessment";
    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$this->doc->id}/analysis")->assertAccepted();
    $draft = actingIn($this->officer, $this->org)->postJson($base)->json('data');
    actingIn($this->officer, $this->org)->putJson($base, [
        'decision' => 'applicable',
        'rationale' => 'We pay IPF claims at Medicare rates under two contracts.',
        'affected_functions' => ['claims'],
        'actions' => array_map(fn ($a) => ['id' => $a['id'], 'title' => $a['title'], 'description' => $a['description'], 'priority' => $a['priority'], 'state' => 'accepted', 'citation_passage_ids' => $a['citation_passage_ids']], $draft['actions']),
    ])->assertOk();
    actingIn($this->officer, $this->org)->postJson("{$base}/approve")->assertOk();

    $this->taskId = actingIn($this->officer, $this->org)->getJson('/api/v1/tasks?view=visible')->json('data.0.id');
    actingIn($this->officer, $this->org)->patchJson("/api/v1/tasks/{$this->taskId}", ['owner_id' => $this->employee->id])->assertOk();
    actingIn($this->employee, $this->org)->post("/api/v1/tasks/{$this->taskId}/evidence", [
        'file' => UploadedFile::fake()->create('repricing-check.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    actingIn($this->employee, $this->org)->postJson("/api/v1/tasks/{$this->taskId}/submit")->assertOk();
    actingIn($this->officer, $this->org)->postJson("/api/v1/tasks/{$this->taskId}/accept")->assertOk();
});

it('reports regulatory activity with the organization\'s decisions', function () {
    $day = $this->doc->publication_date->toDateString();
    $all = actingIn($this->officer, $this->org)->getJson("/api/v1/reports/regulatory-activity?from={$day}&to={$day}&scope=all")->assertOk();

    $row = collect($all->json('data'))->firstWhere('document_number', '2026-20134');
    expect($row)->toMatchArray([
        'assessment_status' => 'approved', 'decision' => 'applicable', 'approved_by' => $this->officer->name,
        'ai_applicability' => 'possible', 'tasks' => 1, 'tasks_done' => 1, 'source_changed_since_approval' => false,
    ])->and($all->json('meta.notice'))->toContain('not legal advice')
        ->and($all->json('summary.approved'))->toBe(1);

    asSystem(fn () => expect(AuditEvent::withoutGlobalScopes()->where('action', 'report.generated')->count())->toBe(1));
});

it('exports CSV that spreadsheet software opens safely', function () {
    $day = $this->doc->publication_date->toDateString();
    $res = actingIn($this->officer, $this->org)->get("/api/v1/reports/regulatory-activity?from={$day}&to={$day}&scope=all&format=csv")->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('text/csv')
        ->and($res->headers->get('Content-Disposition'))->toContain('ruleradar-regulatory-activity-');
    $csv = $res->streamedContent();
    expect($csv)->toContain('document_number,citation,type,title')->and($csv)->toContain('2026-20134');

    expect(Csv::cell('=HYPERLINK("x")'))->toBe('\'=HYPERLINK("x")')
        ->and(Csv::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(Csv::cell('Plain text'))->toBe('Plain text')
        ->and(Csv::cell(true))->toBe('yes');
});

it('reports task status including evidence counts', function () {
    $res = actingIn($this->auditor, $this->org)->getJson('/api/v1/reports/tasks')->assertOk();
    expect($res->json('summary'))->toMatchArray(['tasks' => 1, 'done' => 1, 'done_without_evidence' => 0])
        ->and($res->json('data.0'))->toMatchArray(['status' => 'done', 'evidence_items' => 1, 'accepted_by' => $this->officer->name, 'from_assessment_action' => true]);

    actingIn($this->auditor, $this->org)->get('/api/v1/reports/tasks?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

it('assembles an evidence pack with the audit trail and a chain check', function () {
    $pack = actingIn($this->auditor, $this->org)->getJson("/api/v1/reports/evidence-pack/{$this->doc->id}")->assertOk();

    expect($pack->json('assessment.decision'))->toBe('applicable')
        ->and($pack->json('analysis.output.what_changed.0.basis'))->toBe('regulatory_text')
        ->and($pack->json('tasks.0.evidence.0.file.filename'))->toBe('repricing-check.pdf')
        ->and($pack->json('tasks.0.evidence.0.file.sha256'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($pack->json('audit_chain.valid'))->toBeTrue()
        ->and(collect($pack->json('audit_trail'))->pluck('action')->all())->toContain('assessment.approved', 'task.accepted');
});

it('limits reports to administrators, compliance officers and auditors', function () {
    $day = today()->toDateString();
    actingIn($this->employee, $this->org)->getJson("/api/v1/reports/regulatory-activity?from={$day}&to={$day}")->assertForbidden();
    actingIn($this->employee, $this->org)->getJson('/api/v1/reports/tasks')->assertForbidden();
    actingIn($this->employee, $this->org)->getJson("/api/v1/reports/evidence-pack/{$this->doc->id}")->assertForbidden();
});

it('keeps evidence packs inside their organization', function () {
    $other = createOrganization('Elsewhere');
    $outsider = addMember($other, Role::ComplianceOfficer);

    actingIn($outsider, $other)->getJson("/api/v1/reports/evidence-pack/{$this->doc->id}")->assertNotFound();
    expect(actingIn($outsider, $other)->getJson('/api/v1/reports/tasks')->json('summary.tasks'))->toBe(0);
});
