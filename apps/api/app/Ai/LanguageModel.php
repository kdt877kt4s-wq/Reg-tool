<?php

namespace App\Ai;

/**
 * The one way RuleRadar calls a language model: a single forced tool call
 * whose input is the structured result. No tool the model calls can act
 * on anything; it only returns data for validation.
 */
interface LanguageModel
{
    public function configured(): bool;

    /**
     * @param  array{name: string, description: string, input_schema: array<string, mixed>}  $tool
     *
     * @throws AiRequestFailed
     */
    public function callTool(string $model, string $system, string $user, array $tool, int $maxTokens): ToolCallResult;
}
