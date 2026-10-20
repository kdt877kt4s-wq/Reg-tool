<?php

return [
    'version' => env('RULERADAR_VERSION', '0.1.0'),

    /*
    | Seed / demonstration data is always flagged and shown with a visible
    | "Demonstration data" badge. It is never mixed into live results.
    */
    'demo_data_enabled' => (bool) env('RULERADAR_DEMO_DATA', false),
];
