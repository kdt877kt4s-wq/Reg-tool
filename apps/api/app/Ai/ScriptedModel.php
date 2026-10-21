<?php

namespace App\Ai;

use RuntimeException;

/**
 * A stand-in for Claude used ONLY by the end-to-end test environment
 * (APP_ENV=e2e), so browser tests do not spend AI credits. It answers from
 * the passages it was sent: every claim cites the first passage, and it is
 * labelled "e2e-scripted" wherever the model name is shown.
 *
 * It refuses to run in any other environment.
 */
final class ScriptedModel implements LanguageModel
{
    public const NAME = 'e2e-scripted';

    public function configured(): bool
    {
        return true;
    }

    public function callTool(string $model, string $system, string $user, array $tool, int $maxTokens): ToolCallResult
    {
        if (! app()->environment('e2e')) {
            throw new RuntimeException('ScriptedModel runs only in the e2e environment.');
        }
        if (! preg_match('/<passage ref="(P\d+)"/', $user, $m)) {
            throw new AiRequestFailed('No passages were sent.');
        }
        $ref = $m[1];

        return new ToolCallResult([
            'what_changed' => [[
                'text' => 'Scripted test summary of the cited passage.',
                'basis' => 'ai_interpretation',
                'citations' => [$ref],
            ]],
            'why_it_matters' => [[
                'text' => 'Scripted test explanation; not a real analysis.',
                'basis' => 'ai_interpretation',
                'citations' => [$ref],
            ]],
            'applicability' => [
                'level' => 'possible',
                'reasons' => [['text' => 'Scripted test reason.', 'basis' => 'ai_interpretation', 'citations' => [$ref]]],
                'missing_facts' => ['Scripted test: what the organization actually does'],
            ],
            'affected_functions' => ['claims'],
            'operational_impacts' => [],
            'policies_to_review' => [],
            'deadlines' => [],
            'suggested_actions' => [[
                'title' => 'Review the cited passage with the claims team',
                'description' => 'Scripted test action.',
                'priority' => 'medium',
                'citations' => [$ref],
            ]],
            'confidence' => 'low',
            'confidence_reason' => 'Scripted end-to-end test output, not a model.',
        ], self::NAME, 0, 0, 'tool_use');
    }
}
