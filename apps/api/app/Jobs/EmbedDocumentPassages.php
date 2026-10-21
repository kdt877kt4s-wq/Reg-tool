<?php

namespace App\Jobs;

use App\Ai\AiRequestFailed;
use App\Ai\Embedder;
use App\Models\RegulatoryPassage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Adds embeddings to a document version's passages that lack one. */
class EmbedDocumentPassages implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public function __construct(public int $versionId) {}

    public function handle(Embedder $embedder): void
    {
        if (! $embedder->enabled()) {
            return;
        }

        $passages = RegulatoryPassage::query()
            ->where('version_id', $this->versionId)
            ->whereNull('embedding')
            ->orderBy('ordinal')
            ->get(['id', 'heading_path', 'text']);

        if ($passages->isEmpty()) {
            return;
        }

        try {
            $vectors = $embedder->embed(
                $passages->map(fn ($p) => trim(($p->heading_path ? $p->heading_path."\n" : '').$p->text))->all(),
                'document',
            );
        } catch (AiRequestFailed $e) {
            if ($e->retryable) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 1800);

                return;
            }
            throw $e;
        }

        foreach ($passages->values() as $i => $passage) {
            DB::update('UPDATE regulatory_passages SET embedding = ?::vector WHERE id = ?', [Embedder::literal($vectors[$i]), $passage->id]);
        }
    }
}
