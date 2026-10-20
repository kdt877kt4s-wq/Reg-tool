<?php

return [
    // Token-only authentication: the Next.js server holds the token and
    // the browser never does, so no cookie/session guard is consulted.
    'stateful' => [],
    'guard' => [],

    // Absolute lifetime in minutes; the idle timeout is enforced separately.
    'expiration' => (int) env('SANCTUM_TOKEN_MINUTES', 720),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'rr_'),
];
