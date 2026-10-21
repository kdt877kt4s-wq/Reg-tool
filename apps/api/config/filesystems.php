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

        // Deployed environments: a private Azure Blob container scanned by
        // Microsoft Defender for Storage (EVIDENCE_DISK=evidence_azure).
        'evidence_azure' => [
            'driver' => 'azure-storage-blob',
            'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),
            'container' => env('AZURE_STORAGE_CONTAINER', 'tenant-files'),
            'throw' => true,
        ],
    ],
];
