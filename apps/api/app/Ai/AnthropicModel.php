<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API (POST /v1/messages) with a forced tool call.
 * Request and response shapes per platform.claude.com/docs/en/api/messages.
 */
final class AnthropicModel implements LanguageModel
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function callTool(string $model, string $system, string $user, array $tool, int $maxTokens): ToolCallResult
    {
        if (! $this->configured()) {
            throw new AiRequestFailed('AI analysis is not configured for this installation.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) $this->config['base_url'], '/'))
                ->withHeaders([
                    'x-api-key' => (string) $this->config['api_key'],
                    'anthropic-version' => (string) $this->config['version'],
                ])
                ->acceptJson()
                ->asJson()
                ->timeout((int) $this->config['timeout'])
                // 429 rate limited, 529 overloaded, 5xx transient.
                ->retry(3, (int) ($this->config['retry_sleep_ms'] ?? 3000), fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504, 529], true)), throw: false)
                ->post('/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                    'tools' => [$tool],
                    'tool_choice' => ['type' => 'tool', 'name' => $tool['name'], 'disable_parallel_tool_use' => true],
                ]);
        } catch (ConnectionException $e) {
            throw new AiRequestFailed('The AI service could not be reached.', retryable: true, previous: $e);
        }

        if (! $response->successful()) {
            $status = $response->status();
            // Never log or surface the request body: it contains document text.
            throw new AiRequestFailed("The AI service answered HTTP {$status}.", retryable: in_array($status, [429, 500, 502, 503, 504, 529], true));
        }

        $json = $response->json();
        $block = collect($json['content'] ?? [])->first(fn ($b) => ($b['type'] ?? null) === 'tool_use' && ($b['name'] ?? null) === $tool['name']);

        if (! is_array($block) || ! is_array($block['input'] ?? null)) {
            $reason = (string) ($json['stop_reason'] ?? 'unknown');
            throw new AiRequestFailed("The AI service returned no structured result (stop reason: {$reason}).", retryable: $reason === 'max_tokens');
        }

        return new ToolCallResult(
            input: $block['input'],
            model: (string) ($json['model'] ?? $model),
            inputTokens: (int) ($json['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['output_tokens'] ?? 0),
            stopReason: (string) ($json['stop_reason'] ?? ''),
        );
    }
}
