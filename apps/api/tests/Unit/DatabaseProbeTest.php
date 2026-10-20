<?php

use App\Support\Health\DatabaseProbe;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('hides driver error details when the query fails', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('SQLSTATE secret-host:5432'));

    $result = (new DatabaseProbe)->check();

    expect($result)->toBe(['status' => 'error', 'detail' => 'database unreachable']);
});
