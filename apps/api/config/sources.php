<?php

return [
    'federal_register' => [
        'base_url' => env('FEDERAL_REGISTER_API', 'https://www.federalregister.gov/api/v1'),

        /*
        | What to monitor. Agency slugs are the Federal Register's own
        | (verified against /api/v1/agencies.json). HHS Office for Civil
        | Rights and ONC/ASTP publish under the department itself, so the
        | department query covers HIPAA and health IT rules.
        */
        'queries' => [
            // Rules and proposed rules from the whole HHS family.
            ['agencies' => ['health-and-human-services-department'], 'types' => ['RULE', 'PRORULE']],
            // Notices from CMS and OIG (payment notices, advisory opinions, safe harbors).
            ['agencies' => ['centers-for-medicare-medicaid-services', 'inspector-general-office-health-and-human-services-department'], 'types' => ['NOTICE']],
        ],

        // Re-check this many days before the newest publication date seen,
        // to catch late-indexed documents and metadata changes.
        'overlap_days' => 3,
        // History to load on the very first run.
        'initial_days' => 60,
        'per_page' => 100,
        'timeout' => 30,
        'retry_sleep_ms' => 2000,
        'user_agent' => env('SOURCES_USER_AGENT', 'RuleRadar/0.3 (regulatory monitoring)'),
    ],
];
