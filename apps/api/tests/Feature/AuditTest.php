<?php

use App\Audit\AuditLogger;
use App\Models\AuditEvent;
use App\Support\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = createOrganization();
    $this->admin = addMember($this->org, Role::OrgAdmin);

    // Generate a few real events through the API.
    actingIn($this->admin, $this->org)->patchJson('/api/v1/organization', ['name' => 'Renamed Once'])->assertOk();
    actingIn($this->admin, $this->org)->postJson('/api/v1/departments', ['name' => 'Compliance'])->assertCreated();
    actingIn($this->admin, $this->org)->patchJson('/api/v1/organization', ['name' => 'Renamed Twice'])->assertOk();
});

it('builds a valid hash chain and lists events newest first', function () {
    $events = actingIn($this->admin, $this->org)->getJson('/api/v1/audit-events')->assertOk()->json('data');

    expect(collect($events)->pluck('action')->all())->toBe(['organization.updated', 'department.created', 'organization.updated']);

    actingIn($this->admin, $this->org)->getJson('/api/v1/audit-events/verify')
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('checked', 3);
});

it('refuses updates and deletes at the database level', function () {
    asSystem(function () {
        // A savepoint keeps the test transaction usable after the expected error.
        expect(fn () => DB::transaction(fn () => DB::table('audit_events')->update(['action' => 'tampered'])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('audit_events')->delete()))
            ->toThrow(QueryException::class);
    });
});

it('detects tampering if the protection is bypassed', function () {
    asSystem(function () {
        $middle = AuditEvent::withoutGlobalScopes()->orderBy('id')->skip(1)->first();

        DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_change');
        DB::table('audit_events')->where('id', $middle->id)->update(['after' => json_encode(['name' => 'Forged'])]);
        DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_change');

        expect(AuditLogger::verify($this->org->id))->toBe(['valid' => false, 'checked' => 2, 'broken_at_id' => $middle->id]);
    });
});
