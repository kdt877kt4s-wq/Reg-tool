<?php

namespace App\Console\Commands;

use App\Ingestion\IngestSource;
use App\Ingestion\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Loads recorded Federal Register responses (real API output, captured by
 * the "Record Federal Register fixtures" workflow) into the end-to-end
 * test database. Runs only when APP_ENV=e2e.
 */
class E2ePrepare extends Command
{
    protected $signature = 'ruleradar:e2e-prepare';

    protected $description = 'Load recorded Federal Register documents for browser tests (e2e environment only)';

    public function handle(IngestSource $ingest, SourceRegistry $sources): int
    {
        if (! app()->environment('e2e')) {
            $this->error('This command runs only when APP_ENV=e2e.');

            return self::FAILURE;
        }

        $dir = base_path('tests/Fixtures/federal_register');
        $documents = [];
        foreach (['document-2026-15588', 'document-2026-20134', 'document-2026-20281'] as $name) {
            $documents[] = json_decode((string) file_get_contents("{$dir}/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
        }

        config(['sources.federal_register.retry_sleep_ms' => 0]);
        Http::preventStrayRequests();
        Http::fake([
            'www.federalregister.gov/api/v1/documents.json*' => Http::response(['count' => count($documents), 'total_pages' => 1, 'results' => $documents]),
            'www.federalregister.gov/documents/full_text/text/2026/10/01/2026-20134.txt' => Http::response(
                (string) file_get_contents("{$dir}/document-2026-20134.raw.txt"), 200, ['Content-Type' => 'text/html'],
            ),
            'www.federalregister.gov/documents/full_text/*' => Http::response('Not found', 404),
        ]);

        $run = $ingest->run($sources->adapter('federal_register'), 400);
        $this->line(json_encode(['status' => $run->status, 'documents' => count($documents)]));

        return self::SUCCESS;
    }
}
