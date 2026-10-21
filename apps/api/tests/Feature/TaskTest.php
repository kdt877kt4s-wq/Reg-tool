<?php

use App\Ai\LanguageModel;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Membership;
use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\UserNotification;
use App\Notifications\RuleRadarNotice;
use App\Support\Role;
use Database\Seeders\HealthcareTaxonomySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeLanguageModel;

/** Put a member in a department. */
function inDepartment($organization, $user, Department $department): void
{
    asSystem(fn () => Membership::where('organization_id', $organization->id)->where('user_id', $user->id)->update(['department_id' => $department->id]));
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');

    $this->org = createOrganization('Lone Star Medicare Plan');
    $this->officer = addMember($this->org, Role::ComplianceOfficer);
    $this->manager = addMember($this->org, Role::DepartmentManager);
    $this->employee = addMember($this->org, Role::Employee);
    $this->colleague = addMember($this->org, Role::Employee);
    $this->auditor = addMember($this->org, Role::Auditor);

    $this->claims = asSystem(fn () => Department::create(['organization_id' => $this->org->id, 'name' => 'Claims']));
    $this->pharmacy = asSystem(fn () => Department::create(['organization_id' => $this->org->id, 'name' => 'Pharmacy']));
    inDepartment($this->org, $this->manager, $this->claims);
    inDepartment($this->org, $this->employee, $this->claims);
    inDepartment($this->org, $this->colleague, $this->pharmacy);
});

function newTask(array $overrides = []): array
{
    return actingIn(test()->officer, test()->org)->postJson('/api/v1/tasks', array_merge([
        'title' => 'Update the claims edit for revised IPF rates',
        'priority' => 'high',
        'owner_id' => test()->employee->id,
        'department_id' => test()->claims->id,
        'due_date' => today()->addDays(10)->toDateString(),
        'due_date_type' => 'internal',
    ], $overrides))->assertCreated()->json('data');
}

function attachEvidence(array $task, $user = null, string $name = 'test-results.pdf'): TestResponse
{
    return actingIn($user ?? test()->employee, test()->org)->post("/api/v1/tasks/{$task['id']}/evidence", [
        'file' => UploadedFile::fake()->create($name, 120, 'application/pdf'),
        'note' => 'Tested against 20 sample claims.',
    ], ['Accept' => 'application/json']);
}

it('creates and assigns a task, notifying the owner in the app and by email', function () {
    $task = newTask();

    expect($task)->toMatchArray(['status' => 'open', 'priority' => 'high', 'due_date_type' => 'internal'])
        ->and($task['owner']['id'])->toBe($this->employee->id)
        ->and($task['events'][0]['type'])->toBe('created');

    asSystem(fn () => expect(UserNotification::where('user_id', $this->employee->id)->where('kind', 'task_assigned')->count())->toBe(1));
    Notification::assertSentOnDemand(RuleRadarNotice::class);
    asSystem(fn () => expect(AuditEvent::withoutGlobalScopes()->where('action', 'task.created')->count())->toBe(1));
});

it('requires a source for an official deadline', function () {
    actingIn($this->officer, $this->org)->postJson('/api/v1/tasks', [
        'title' => 'File attestation', 'priority' => 'medium',
        'due_date' => today()->addDays(5)->toDateString(), 'due_date_type' => 'official',
    ])->assertUnprocessable()->assertJsonValidationErrors(['due_date_basis']);
});

it('rejects an owner from another organization', function () {
    $other = createOrganization('Elsewhere');
    $stranger = addMember($other, Role::Employee);

    actingIn($this->officer, $this->org)->postJson('/api/v1/tasks', [
        'title' => 'Something', 'priority' => 'low', 'owner_id' => $stranger->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);
});

it('shows each person only the tasks they may see', function () {
    $task = newTask();

    actingIn($this->employee, $this->org)->getJson("/api/v1/tasks/{$task['id']}")->assertOk();
    actingIn($this->manager, $this->org)->getJson("/api/v1/tasks/{$task['id']}")->assertOk();
    actingIn($this->auditor, $this->org)->getJson("/api/v1/tasks/{$task['id']}")->assertOk()->assertJsonPath('data.allowed_actions', []);
    actingIn($this->colleague, $this->org)->getJson("/api/v1/tasks/{$task['id']}")->assertNotFound();

    expect(actingIn($this->colleague, $this->org)->getJson('/api/v1/tasks')->json('data'))->toBe([])
        ->and(actingIn($this->manager, $this->org)->getJson('/api/v1/tasks')->json('data'))->toHaveCount(1);
});

it('keeps tasks inside their organization', function () {
    $task = newTask();
    $other = createOrganization('Elsewhere');
    $outsider = addMember($other, Role::ComplianceOfficer);

    actingIn($outsider, $other)->getJson("/api/v1/tasks/{$task['id']}")->assertNotFound();
    actingIn($outsider, $other)->postJson("/api/v1/tasks/{$task['id']}/cancel", ['reason' => 'Not ours at all'])->assertNotFound();
    expect(actingIn($outsider, $other)->getJson('/api/v1/tasks')->json('data'))->toBe([]);
});

it('lets a manager create tasks only in their own department', function () {
    actingIn($this->manager, $this->org)->postJson('/api/v1/tasks', ['title' => 'Claims check', 'priority' => 'low', 'department_id' => $this->claims->id])->assertCreated();
    actingIn($this->manager, $this->org)->postJson('/api/v1/tasks', ['title' => 'Pharmacy check', 'priority' => 'low', 'department_id' => $this->pharmacy->id])->assertForbidden();
    actingIn($this->employee, $this->org)->postJson('/api/v1/tasks', ['title' => 'My idea', 'priority' => 'low', 'department_id' => $this->claims->id])->assertForbidden();
});

it('runs the full workflow: start, evidence, submit, review, accept', function () {
    $task = newTask();

    actingIn($this->employee, $this->org)->postJson("/api/v1/tasks/{$task['id']}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');

    actingIn($this->employee, $this->org)->postJson("/api/v1/tasks/{$task['id']}/submit")
        ->assertUnprocessable()->assertJsonValidationErrors(['evidence']);

    $evidence = attachEvidence($task)->assertCreated()->json('data');
    expect($evidence['file'])->toMatchArray(['filename' => 'test-results.pdf', 'scan_status' => 'not_scanned'])
        ->and($evidence['file']['sha256'])->toMatch('/^[0-9a-f]{64}$/');

    actingIn($this->employee, $this->org)->postJson("/api/v1/tasks/{$task['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    asSystem(function () {
        $reviewers = UserNotification::where('kind', 'task_submitted')->pluck('user_id')->sort()->values()->all();
        expect($reviewers)->toBe(collect([$this->officer->id, $this->manager->id])->sort()->values()->all());
    });

    // The person who did the work cannot accept it; an auditor cannot either.
    actingIn($this->employee, $this->org)->postJson("/api/v1/tasks/{$task['id']}/accept")->assertForbidden();
    actingIn($this->auditor, $this->org)->postJson("/api/v1/tasks/{$task['id']}/accept")->assertForbidden();

    $done = actingIn($this->manager, $this->org)->postJson("/api/v1/tasks/{$task['id']}/accept")->assertOk()->json('data');
    expect($done['status'])->toBe('done')
        ->and($done['completed_at'])->not->toBeNull()
        ->and(collect($done['events'])->pluck('type')->all())->toBe(['created', 'started', 'evidence_added', 'submitted', 'accepted']);
});

it('does not let a reviewer accept work they submitted themselves', function () {
    $task = newTask(['owner_id' => $this->officer->id]);
    attachEvidence($task, $this->officer)->assertCreated();
    actingIn($this->officer, $this->org)->postJson("/api/v1/tasks/{$task['id']}/submit")->assertOk();

    actingIn($this->officer, $this->org)->postJson("/api/v1/tasks/{$task['id']}/accept")
        ->assertForbidden()->assertJsonPath('message', 'Someone other than the person who did the work must accept it.');
    actingIn($this->manager, $this->org)->postJson("/api/v1/tasks/{$task['id']}/accept")->assertOk();
});

it('returns a task with a reason and tells the owner', function () {
    $task = newTask();
    attachEvidence($task)->assertCreated();
    actingIn($this->employee, $this->org)->postJson("/api/v1/tasks/{$task['id']}/submit")->assertOk();

    actingIn($this->officer, $this->org)->postJson("/api/v1/tasks/{$task['id']}/return")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    actingIn($this->officer, $this->org)->postJson("/api/v1/tasks/{$task['id']}/return", ['reason' => 'Attach the signed version.'])
        ->assertOk()->assertJsonPath('data.status', 'returned')->assertJsonPath('data.status_note', 'Attach the signed version.');

    asSystem(fn () => expect(UserNotification::where('user_id', $this->employee->id)->where('kind', 'task_returned')->value('body'))->toBe('Reason: Attach the signed version.'));
});

it('keeps task history append-only', function () {
    $task = newTask();
    asSystem(fn () => expect(fn () => TaskEvent::where('task_id', $task['id'])->update(['type' => 'tampered']))->toThrow(QueryException::class));
});

it('rejects evidence file types outside the allowlist', function () {
    $task = newTask();
    actingIn($this->employee, $this->org)->post("/api/v1/tasks/{$task['id']}/evidence", [
        'file' => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['file']);
});

it('serves evidence only to people who can see the task', function () {
    $task = newTask();
    $fileId = attachEvidence($task)->json('data.file.id');

    actingIn($this->manager, $this->org)->get("/api/v1/files/{$fileId}/download")
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('test-results.pdf');
    actingIn($this->colleague, $this->org)->getJson("/api/v1/files/{$fileId}/download")->assertNotFound();

    $other = createOrganization('Elsewhere');
    $outsider = addMember($other, Role::ComplianceOfficer);
    actingIn($outsider, $other)->getJson("/api/v1/files/{$fileId}/download")->assertNotFound();
});

it('holds unscanned files back when scanning is required', function () {
    config(['files.require_scan' => true]);
    $task = newTask();
    $fileId = attachEvidence($task)->json('data.file.id');

    actingIn($this->manager, $this->org)->getJson("/api/v1/files/{$fileId}/download")
        ->assertStatus(423)->assertJsonPath('code', 'file_not_scanned');
});

it('turns accepted actions into tasks when an assessment is approved', function () {
    $this->seed(HealthcareTaxonomySeeder::class);
    $docs = frDocuments();
    fakeFederalRegister([$docs['2026-20134']]);
    pollFederalRegister();
    asSystem(fn () => OrganizationProfile::create([
        'organization_id' => $this->org->id,
        'org_types' => ['medicare_advantage_org'], 'states' => ['TX'], 'programs' => ['medicare_advantage'],
        'functions' => ['claims'], 'products' => [], 'obligations' => [], 'version' => 1, 'completed_at' => now(),
    ]));
    $document = RegulatoryDocument::where('external_id', '2026-20134')->firstOrFail();
    app()->instance(LanguageModel::class, new FakeLanguageModel([fn ($u) => goodAnalysis($u)]));
    $base = "/api/v1/regulatory-documents/{$document->id}/assessment";

    actingIn($this->officer, $this->org)->postJson("/api/v1/regulatory-documents/{$document->id}/analysis")->assertAccepted();
    $draft = actingIn($this->officer, $this->org)->postJson($base)->assertCreated()->json('data');
    actingIn($this->officer, $this->org)->putJson($base, [
        'decision' => 'applicable',
        'rationale' => 'We pay IPF claims at Medicare rates under two contracts.',
        'affected_functions' => ['claims'],
        'actions' => array_map(fn ($a) => [
            'id' => $a['id'], 'title' => $a['title'], 'description' => $a['description'],
            'priority' => $a['priority'], 'state' => 'accepted', 'citation_passage_ids' => $a['citation_passage_ids'],
        ], $draft['actions']),
    ])->assertOk();

    actingIn($this->officer, $this->org)->postJson("{$base}/approve")->assertOk()->assertJsonPath('tasks_created', 1);

    $task = actingIn($this->officer, $this->org)->getJson("/api/v1/tasks?document_id={$document->id}")->json('data.0');
    $detail = actingIn($this->officer, $this->org)->getJson("/api/v1/tasks/{$task['id']}")->json('data');
    expect($detail['from_action'])->toBeTrue()
        ->and($detail['citations'])->not->toBeEmpty()
        ->and($detail['owner'])->toBeNull();

    if ($document->effective_date?->isFuture()) {
        expect($detail['due_date'])->toBe($document->effective_date->toDateString())
            ->and($detail['due_date_type'])->toBe('official')
            ->and($detail['due_date_basis'])->toContain('2026-20134');
    } else {
        // No future published date: no deadline is invented.
        expect($detail['due_date'])->toBeNull();
    }

    // Approving again does not duplicate tasks.
    actingIn($this->officer, $this->org)->postJson("{$base}/reopen")->assertOk();
    actingIn($this->officer, $this->org)->postJson("{$base}/approve")->assertOk()->assertJsonPath('tasks_created', 0);
    asSystem(fn () => expect(Task::where('document_id', $document->id)->count())->toBe(1));
});
