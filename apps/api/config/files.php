<?php

return [
    // Private disk for evidence. Local in development; Azure Blob in deployed
    // environments (Stage 5), with Defender for Storage scanning uploads.
    'disk' => env('EVIDENCE_DISK', 'evidence'),

    'max_kb' => 25 * 1024,

    // Allowed evidence types (extensions checked against detected MIME type).
    'mimes' => ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'csv', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'eml', 'msg', 'zip'],

    // When true, files download only after a scanner marks them clean.
    'require_scan' => (bool) env('EVIDENCE_REQUIRE_SCAN', false),

    // Defender for Storage scan results arrive by Event Grid webhook,
    // authenticated with this secret (?key= in the subscription URL).
    'scan_webhook_secret' => env('MALWARE_SCAN_WEBHOOK_SECRET', ''),
    'scan_container' => env('AZURE_STORAGE_CONTAINER', 'tenant-files'),
];
