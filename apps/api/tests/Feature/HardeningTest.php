<?php

use App\Models\UserNotification;
use App\Support\Role;

it('sends security headers on every API response', function () {
    $res = $this->getJson('/api/v1/health');

    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($res->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('verifies every organization\'s audit chain', function () {
    $org = createOrganization();
    $admin = addMember($org, Role::OrgAdmin);
    actingIn($admin, $org)->postJson('/api/v1/departments', ['name' => 'Claims'])->assertCreated();

    $this->artisan('ruleradar:audit-verify')->assertSuccessful()->expectsOutputToContain('"organizations_checked":1,"broken":0');
});

it('prunes old read notifications and keeps unread and recent ones', function () {
    $org = createOrganization();
    $user = addMember($org, Role::Employee);
    asSystem(function () use ($org, $user) {
        $base = ['organization_id' => $org->id, 'user_id' => $user->id, 'kind' => 'task_assigned', 'title' => 'x'];
        UserNotification::create($base + ['dedupe_key' => 'old-read', 'read_at' => now()->subDays(200), 'created_at' => now()->subDays(200)]);
        UserNotification::create($base + ['dedupe_key' => 'old-unread', 'created_at' => now()->subDays(200)]);
        UserNotification::create($base + ['dedupe_key' => 'new-read', 'read_at' => now(), 'created_at' => now()]);
    });

    $this->artisan('ruleradar:prune')->assertSuccessful();

    asSystem(fn () => expect(UserNotification::withoutGlobalScopes()->orderBy('dedupe_key')->pluck('dedupe_key')->all())->toBe(['new-read', 'old-unread']));
});
