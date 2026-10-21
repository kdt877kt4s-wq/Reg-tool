<?php

use App\Ai\LanguageModel;
use App\Ingestion\IngestSource;
use App\Ingestion\SourceRegistry;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\SourceRun;
use App\Models\User;
use App\Security\Totp;
use App\Support\Role;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeLanguageModel;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Fixture helpers. Fixtures are system work, so they run with row-level
| security bypassed; the code under test never does.
*/

function asSystem(callable $callback): mixed
{
    return app(TenantContext::class)->bypass($callback);
}

const TEST_PASSWORD = 'correct horse battery staple';

function createUser(?string $email = null, bool $mfa = false): User
{
    $user = User::create([
        'name' => 'Test '.Str::random(4),
        'email' => $email ?? Str::lower(Str::random(8)).'@example.test',
        'password' => TEST_PASSWORD,
    ]);

    if ($mfa) {
        enableMfa($user);
    }

    // Reload so every column is present, as it is for a signed-in user.
    return $user->fresh();
}

/** Turns on two-factor for a user and returns the TOTP secret. */
function enableMfa(User $user): string
{
    $secret = Totp::generateSecret();
    $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();

    return $secret;
}

function createOrganization(string $name = 'Acme Health Plan'): Organization
{
    return asSystem(fn () => Organization::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
    ]));
}

/** Adds a user to an organization. Roles that require MFA get it unless told otherwise. */
function addMember(Organization $organization, Role $role, ?User $user = null, ?bool $mfa = null): User
{
    $user ??= createUser(mfa: $mfa ?? $role->requiresMfa());

    asSystem(fn () => Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => Membership::ACTIVE,
    ]));

    return $user;
}

/** Authenticate as a user and address requests to an organization. */
function actingIn(User $user, Organization $organization): TestCase
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Organization-Id', (string) $organization->id);
}

/*
| Federal Register fixtures: real API responses recorded by the
| "Record Federal Register fixtures" workflow.
*/

/** @return array<string, mixed> */
function frFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/federal_register/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

/** All recorded documents, keyed by document number. @return array<string, array<string, mixed>> */
function frDocuments(): array
{
    $docs = [];
    foreach (['document-2026-15588', 'document-2026-20134', 'document-2026-20281'] as $file) {
        $doc = frFixture($file);
        $docs[$doc['document_number']] = $doc;
    }
    foreach (frFixture('search-cfr-42-422')['results'] as $doc) {
        $docs[$doc['document_number']] ??= $doc;
    }

    return $docs;
}

/**
 * Fake the Federal Register: every search returns $documents; full text
 * is served for 2026-20134 (recorded) and 404 for everything else.
 *
 * @param  list<array<string, mixed>>  $documents
 */
function fakeFederalRegister(array $documents, int $searchStatus = 200): void
{
    // Later calls in the same test change what the fake serves: Laravel
    // keeps the first registered stubs, so the stubs read this state.
    app()->instance('fr.fake', ['documents' => array_values($documents), 'status' => $searchStatus]);
    if (app()->bound('fr.fake.registered')) {
        return;
    }
    app()->instance('fr.fake.registered', true);

    config(['sources.federal_register.retry_sleep_ms' => 0]);
    Http::preventStrayRequests();
    Http::fake([
        'www.federalregister.gov/api/v1/documents.json*' => function () {
            $state = app('fr.fake');

            return $state['status'] === 200
                ? Http::response(['count' => count($state['documents']), 'total_pages' => 1, 'results' => $state['documents']])
                : Http::response(['errors' => 'unavailable'], $state['status']);
        },
        'www.federalregister.gov/documents/full_text/text/2026/10/01/2026-20134.txt' => Http::response(
            file_get_contents(__DIR__.'/Fixtures/federal_register/document-2026-20134.raw.txt'),
            200,
            ['Content-Type' => 'text/html'],
        ),
        'www.federalregister.gov/documents/full_text/*' => Http::response('Not found', 404),
    ]);
}

/** Run one Federal Register poll over a wide window. */
function pollFederalRegister(): SourceRun
{
    return app(IngestSource::class)->run(app(SourceRegistry::class)->adapter('federal_register'), 400);
}

/** A well-formed, fully supported analysis of 2026-20134. */
function goodAnalysis(string $user): array
{
    $factor = FakeLanguageModel::refFor($user, 'budget neutrality factor changed from 0.9989 to 0.9986');
    $dates = FakeLanguageModel::refFor($user, 'This correction is effective October 1, 2026');

    return [
        'what_changed' => [[
            'text' => 'Corrects the FY 2027 IPF PPS wage index budget neutrality factor.',
            'basis' => 'regulatory_text',
            'citations' => [$factor],
            'quote' => 'the wage index budget neutrality factor changed from 0.9989 to 0.9986',
        ]],
        'why_it_matters' => [[
            'text' => 'Inpatient psychiatric facility payment rates change slightly.',
            'basis' => 'ai_interpretation',
            'citations' => [$factor],
        ]],
        'applicability' => [
            'level' => 'possible',
            'reasons' => [['text' => 'It corrects fee-for-service IPF rates; Medicare Advantage plans may price IPF claims from them.', 'basis' => 'ai_interpretation', 'citations' => [$factor]]],
            'missing_facts' => ['Whether provider contracts pay inpatient psychiatric facilities at Medicare rates'],
        ],
        'affected_functions' => ['claims', 'not_a_real_function'],
        'operational_impacts' => [],
        'policies_to_review' => [],
        'deadlines' => [['date' => '2026-10-01', 'label' => 'Correction effective', 'citations' => [$dates]]],
        'suggested_actions' => [[
            'title' => 'Check IPF claims priced on the corrected rates',
            'description' => 'Confirm whether contracts reference Medicare IPF PPS rates.',
            'priority' => 'medium',
            'citations' => [$factor],
        ]],
        'confidence' => 'high',
        'confidence_reason' => 'The correction text is short and fully provided.',
    ];
}

function useModel(FakeLanguageModel $model): FakeLanguageModel
{
    app()->instance(LanguageModel::class, $model);

    return $model;
}
