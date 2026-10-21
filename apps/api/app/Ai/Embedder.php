<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Text embeddings for semantic passage retrieval (Voyage AI). Optional:
 * without a key, retrieval uses PostgreSQL full-text ranking only.
 */
class Embedder
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config) {}

    public function enabled(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function dimensions(): int
    {
        return (int) $this->config['dimensions'];
    }

    /**
     * @param  list<string>  $texts
     * @param  'document'|'query'  $inputType
     * @return list<list<float>>
     *
     * @throws AiRequestFailed
     */
    public function embed(array $texts, string $inputType): array
    {
        if (! $this->enabled()) {
            throw new AiRequestFailed('Embeddings are not configured.');
        }

        $vectors = [];
        foreach (array_chunk($texts, (int) $this->config['batch_size']) as $batch) {
            try {
                $response = Http::baseUrl(rtrim((string) $this->config['base_url'], '/'))
                    ->withToken((string) $this->config['api_key'])
                    ->acceptJson()
                    ->timeout((int) $this->config['timeout'])
                    ->retry(3, 2000, throw: false)
                    ->post('/v1/embeddings', [
                        'input' => $batch,
                        'model' => $this->config['model'],
                        'input_type' => $inputType,
                        'output_dimension' => $this->dimensions(),
                    ]);
            } catch (ConnectionException $e) {
                throw new AiRequestFailed('The embedding service could not be reached.', retryable: true, previous: $e);
            }

            if (! $response->successful()) {
                throw new AiRequestFailed("The embedding service answered HTTP {$response->status()}.", retryable: $response->status() >= 429);
            }

            $data = collect($response->json('data') ?? [])->sortBy('index')->values();
            if ($data->count() !== count($batch)) {
                throw new AiRequestFailed('The embedding service returned the wrong number of vectors.');
            }
            foreach ($data as $item) {
                $vector = array_map('floatval', $item['embedding'] ?? []);
                if (count($vector) !== $this->dimensions()) {
                    throw new AiRequestFailed('The embedding service returned vectors of the wrong size.');
                }
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    /** pgvector literal, e.g. "[0.1,0.2]". @param list<float> $vector */
    public static function literal(array $vector): string
    {
        return '['.implode(',', array_map(fn ($v) => rtrim(rtrim(sprintf('%.7F', $v), '0'), '.') ?: '0', $vector)).']';
    }
}
