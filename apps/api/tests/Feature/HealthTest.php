<?php

use App\Support\Health\DatabaseProbe;

it('reports ok when the database is reachable', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok');
});

it('reports degraded with 503 when the database check fails', function () {
    $this->mock(DatabaseProbe::class)
        ->shouldReceive('check')
        ->andReturn(['status' => 'error', 'detail' => 'database unreachable']);

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'error');
});

it('renders JSON errors for unknown API routes', function () {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});
