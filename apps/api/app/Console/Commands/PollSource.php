<?php

namespace App\Console\Commands;

use App\Ingestion\IngestSource;
use App\Ingestion\SourceRegistry;
use Illuminate\Console\Command;

class PollSource extends Command
{
    protected $signature = 'ruleradar:poll
        {source=federal_register : Source code}
        {--days= : Look back this many days instead of from the last cursor}';

    protected $description = 'Retrieve new and changed publications from a regulatory source';

    public function handle(SourceRegistry $registry, IngestSource $ingest): int
    {
        $days = $this->option('days');
        $run = $ingest->run($registry->adapter((string) $this->argument('source')), $days === null ? null : (int) $days);

        $this->line(sprintf(
            '%s %s to %s: fetched %d, created %d, updated %d, unchanged %d%s',
            $run->status,
            $run->window_start->toDateString(),
            $run->window_end->toDateString(),
            $run->fetched,
            $run->created,
            $run->updated,
            $run->unchanged,
            $run->error ? " ({$run->error})" : '',
        ));

        return $run->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }
}
