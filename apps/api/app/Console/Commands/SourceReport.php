<?php

namespace App\Console\Commands;

use App\Models\RegulatoryDocument;
use App\Models\RegulatoryPassage;
use App\Models\Source;
use Illuminate\Console\Command;

/** Prints what has been ingested, as one line of JSON (for ops and CI). */
class SourceReport extends Command
{
    protected $signature = 'ruleradar:source-report';

    protected $description = 'Summarize ingested regulatory documents';

    public function handle(): int
    {
        $newest = RegulatoryDocument::query()->orderByDesc('publication_date')->orderByDesc('id')->first(['external_id', 'title', 'publication_date', 'type']);

        $this->line(json_encode([
            'sources' => Source::query()->get(['code', 'status', 'last_success_at'])->toArray(),
            'documents' => RegulatoryDocument::query()->count(),
            'by_type' => RegulatoryDocument::query()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type'),
            'text_available' => RegulatoryDocument::query()->where('text_status', 'available')->count(),
            'passages' => RegulatoryPassage::query()->count(),
            'newest' => $newest ? [
                'document_number' => $newest->external_id,
                'type' => $newest->type,
                'published' => $newest->publication_date->toDateString(),
                'title' => mb_substr($newest->title, 0, 120),
            ] : null,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
