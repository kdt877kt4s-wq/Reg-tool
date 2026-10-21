<?php

namespace Tests\Support;

use App\Ai\LanguageModel;
use App\Ai\ToolCallResult;
use Closure;
use LogicException;
use RuntimeException;

/**
 * Stands in for Claude in tests. Each call is answered by the next
 * responder: a closure receiving the user prompt and returning the tool
 * input (or throwing). Calls are recorded for assertions.
 */
final class FakeLanguageModel implements LanguageModel
{
    /** @var list<array{model: string, system: string, user: string, tool: array<string, mixed>}> */
    public array $calls = [];

    /** @param list<Closure(string): array<string, mixed>> $responders */
    public function __construct(private array $responders = [], private bool $configured = true) {}

    public function configured(): bool
    {
        return $this->configured;
    }

    public function callTool(string $model, string $system, string $user, array $tool, int $maxTokens): ToolCallResult
    {
        $this->calls[] = compact('model', 'system', 'user', 'tool');
        $responder = $this->responders[min(count($this->calls), count($this->responders)) - 1] ?? null;
        if ($responder === null) {
            throw new LogicException('FakeLanguageModel has no responder for this call.');
        }

        return new ToolCallResult($responder($user), $model, 1000, 500, 'tool_use');
    }

    /** The ref ("P3") of the passage in the prompt containing $needle. */
    public static function refFor(string $user, string $needle): string
    {
        preg_match_all('/<passage ref="(P\d+)"[^>]*>\n(.*?)\n<\/passage>/s', $user, $m, PREG_SET_ORDER);
        foreach ($m as [, $ref, $text]) {
            if (str_contains(preg_replace('/\s+/', ' ', $text), $needle)) {
                return $ref;
            }
        }
        throw new RuntimeException("No passage in the prompt contains: {$needle}");
    }
}
