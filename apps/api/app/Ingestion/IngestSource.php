<?php

namespace App\Ingestion;

use App\Ingestion\Contracts\SourceAdapter;
use App\Jobs\FetchDocumentText;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One polling run of one source. Every run is recorded. A failed read
 * marks the source degraded and creates nothing: RuleRadar never shows
 * placeholder updates for a source it could not read.
 */
final class IngestSource
{
    public function __construct(private DocumentStore $store) {}

    public function run(SourceAdapter $adapter, ?int $days = null): SourceRun
    {
        $source = Source::query()->where('code', $adapter->code())->firstOrFail();
        $config = config("sources.{$adapter->code()}");

        $to = CarbonImmutable::today();
        $from = match (true) {
            $days !== null => $to->subDays($days),
            $source->cursor_date !== null => CarbonImmutable::parse($source->cursor_date)->subDays((int) $config['overlap_days']),
            default => $to->subDays((int) $config['initial_days']),
        };

        $run = SourceRun::create([
            'source_id' => $source->id,
            'status' => 'running',
            'window_start' => $from->toDateString(),
            'window_end' => $to->toDateString(),
            'started_at' => now(),
        ]);

        $counts = ['fetched' => 0, DocumentStore::CREATED => 0, DocumentStore::UPDATED => 0, DocumentStore::UNCHANGED => 0];
        $newest = $source->cursor_date ? CarbonImmutable::parse($source->cursor_date) : null;
        $needText = [];

        try {
            foreach ($adapter->discover($from, $to) as $doc) {
                $counts['fetched']++;
                [$result, $document] = $this->store->upsert($source, $doc);
                $counts[$result]++;

                if ($result !== DocumentStore::UNCHANGED && $document->raw_text_url) {
                    $needText[] = $document->id;
                }

                $published = CarbonImmutable::parse($doc->publicationDate);
                if ($newest === null || $published->gt($newest)) {
                    $newest = $published;
                }
            }
        } catch (Throwable $e) {
            // Documents stored before the failure are real and stay; the run
            // and the source are marked so nobody mistakes silence for "no news".
            $run->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 2000), 'finished_at' => now()] + $this->countColumns($counts));
            $source->update([
                'status' => Source::DEGRADED,
                'consecutive_failures' => $source->consecutive_failures + 1,
                'last_run_at' => now(),
                'last_error' => substr($e->getMessage(), 0, 2000),
            ]);
            Log::warning('ingestion.failed', ['source' => $source->code, 'run' => $run->id, 'error' => $e->getMessage()]);

            return $run->fresh();
        }

        $run->update(['status' => 'succeeded', 'finished_at' => now()] + $this->countColumns($counts));
        $source->update([
            'status' => Source::HEALTHY,
            'consecutive_failures' => 0,
            'cursor_date' => $newest?->toDateString(),
            'last_run_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
        ]);

        foreach ($needText as $documentId) {
            FetchDocumentText::dispatch($documentId, $adapter->code());
        }

        Log::info('ingestion.succeeded', ['source' => $source->code, 'run' => $run->id] + $counts);

        return $run->fresh();
    }

    /** @param array<string, int> $counts @return array<string, int> */
    private function countColumns(array $counts): array
    {
        return [
            'fetched' => $counts['fetched'],
            'created' => $counts[DocumentStore::CREATED],
            'updated' => $counts[DocumentStore::UPDATED],
            'unchanged' => $counts[DocumentStore::UNCHANGED],
        ];
    }
}
