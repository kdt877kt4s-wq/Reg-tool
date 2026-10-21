<?php

namespace App\Ai;

final readonly class ToolCallResult
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public array $input,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $stopReason,
    ) {}
}
