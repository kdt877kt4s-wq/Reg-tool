<?php

return [
    'version' => env('RULERADAR_VERSION', '0.2.0'),

    // Where links in emails (invitations, password resets) point.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // API tokens stop working after this many minutes without use.
    'session_idle_minutes' => (int) env('SESSION_IDLE_MINUTES', 30),

    /*
    | Seed / demonstration data is always flagged and shown with a visible
    | "Demonstration data" badge. It is never mixed into live results.
    */
    'demo_data_enabled' => (bool) env('RULERADAR_DEMO_DATA', false),
];
