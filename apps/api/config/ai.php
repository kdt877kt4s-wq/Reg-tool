<?php

return [
    /*
    | Server-side only. Keys come from the environment (Azure Key Vault in
    | deployed environments) and never reach the web app.
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 180),
        'retry_sleep_ms' => 3000,
    ],

    'models' => [
        // Verified against platform.claude.com/docs/en/models/overview.
        'analysis' => env('AI_ANALYSIS_MODEL', 'claude-sonnet-5-5'),
    ],

    'analysis' => [
        'max_output_tokens' => 8000,
        // Passage budget sent to the model for one document.
        'max_passages' => 40,
        'max_passage_chars' => 90_000,
    ],

    'voyage' => [
        'api_key' => env('VOYAGE_API_KEY'),
        'base_url' => env('VOYAGE_BASE_URL', 'https://api.voyageai.com'),
        // 1024-dimension output matches regulatory_passages.embedding.
        'model' => env('VOYAGE_MODEL', 'voyage-4'),
        'dimensions' => 1024,
        'batch_size' => 64,
        'timeout' => 60,
    ],
];
