<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
        ],

        // Evidence files: never public, never served directly; downloads go
        // through the API's permission checks.
        'evidence' => [
            'driver' => 'local',
            'root' => storage_path('app/private/evidence'),
            'serve' => false,
            'throw' => true,
        ],
    ],
];
