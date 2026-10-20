<?php

namespace App\Jobs;

use App\Ingestion\DocumentStore;
use App\Ingestion\SourceRegistry;
use App\Ingestion\SourceUnavailable;
use App\Models\RegulatoryDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Fetches a document's full text and splits it into passages. Retried
 * with backoff when the source is unreachable; the document's
 * text_status stays "pending" until it succeeds.
 */
class FetchDocumentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $documentId, public string $sourceCode) {}

    /** @return list<object> */
    public function middleware(): array
    {
        // Be a polite client of public government services.
        return [new RateLimited('source-text')];
    }

    public function handle(SourceRegistry $registry, DocumentStore $store): void
    {
        $document = RegulatoryDocument::query()->find($this->documentId);
        if (! $document || ! $document->raw_text_url) {
            return;
        }

        try {
            $text = $registry->adapter($this->sourceCode)->fetchText($document->raw_text_url);
        } catch (SourceUnavailable $e) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 3600);

            return;
        }

        $store->storeText($document, $text);
    }

    public function failed(): void
    {
        RegulatoryDocument::query()->whereKey($this->documentId)->update(['text_status' => 'unavailable']);
    }
}
