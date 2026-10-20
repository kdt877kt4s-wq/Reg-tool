<?php

use Illuminate\Support\Facades\Route;

// The API serves no HTML. The web app lives in apps/web.
Route::get('/', fn () => response()->json([
    'service' => 'ruleradar-api',
    'docs' => '/api/v1/health',
]));
