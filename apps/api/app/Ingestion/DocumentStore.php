<?php

namespace App\Ingestion;

use App\Models\RegulatoryDocument;
use App\Models\RegulatoryDocumentVersion;
use App\Models\RegulatoryPassage;
use App\Models\RegulatoryRelation;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Stores normalized documents: one row per (source, document number), a
 * new version only when the source's metadata actually changes, and
 * corrections/withdrawals linked to their targets.
 */
final class DocumentStore
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    /** @return array{0: string, 1: RegulatoryDocument} */
    public function upsert(Source $source, NormalizedDocument $doc): array
    {
        return DB::transaction(function () use ($source, $doc) {
            $hash = $doc->hash();
            $existing = RegulatoryDocument::query()
                ->where('source_id', $source->id)
                ->where('external_id', $doc->externalId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->content_hash === $hash) {
                return [self::UNCHANGED, $existing];
            }

            $now = now();
            $columns = $this->columns($doc) + ['content_hash' => $hash, 'last_changed_at' => $now];

            if (! $existing) {
                $document = RegulatoryDocument::create($columns + [
                    'source_id' => $source->id,
                    'external_id' => $doc->externalId,
                    'current_version' => 1,
                    'first_seen_at' => $now,
                    'text_status' => $doc->rawTextUrl ? 'pending' : 'unavailable',
                ]);
                $this->addVersion($document, 1, $doc, null);
                $result = self::CREATED;
            } else {
                $previous = $existing->versions()->orderByDesc('version_no')->first();
                $changes = $this->diff($previous?->metadata ?? [], $doc->metadata());
                // A new version gets its own text, fetched again.
                $existing->update($columns + [
                    'current_version' => $existing->current_version + 1,
                    'text_status' => $doc->rawTextUrl ? 'pending' : 'unavailable',
                ]);
                $this->addVersion($existing, $existing->current_version, $doc, $changes);
                $document = $existing;
                $result = self::UPDATED;
            }

            if ($doc->relation !== null && $doc->relatesTo !== null) {
                $this->link($document, $doc->relation, $doc->relatesTo, 'structured_field');
            }
            $this->resolveIncoming($document);

            return [$result, $document];
        });
    }

    /**
     * Store the full text for the document's current version as passages.
     * When the document is a correction or withdrawal without a structured
     * target, the target is parsed from the text ("In FR Doc. 2026-15588").
     */
    public function storeText(RegulatoryDocument $document, ?string $text): void
    {
        DB::transaction(function () use ($document, $text) {
            $version = $document->versions()->where('version_no', $document->current_version)->firstOrFail();

            if ($text === null || trim($text) === '') {
                $document->update(['text_status' => 'unavailable']);

                return;
            }

            $chunks = PassageChunker::chunk($text);
            RegulatoryPassage::query()->where('version_id', $version->id)->delete();

            foreach (array_chunk($chunks['passages'], 200) as $batch) {
                RegulatoryPassage::insert(array_map(fn (array $p) => $p + [
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                ], $batch));
            }

            $version->update([
                'text_sha256' => hash('sha256', $chunks['text']),
                'text_chars' => strlen($chunks['text']),
            ]);
            $document->update(['text_status' => 'available']);

            $relation = FederalRegisterReferences::relationFor($document->action);
            $hasStructured = $document->outgoingRelations()->where('method', 'structured_field')->exists();
            if ($relation !== null && ! $hasStructured) {
                $target = FederalRegisterReferences::firstReferencedDocument($chunks['text'], $document->external_id);
                if ($target !== null) {
                    $this->link($document, $relation, $target, 'parsed_from_text');
                }
            }
        });
    }

    private function link(RegulatoryDocument $from, string $relation, string $targetNumber, string $method): void
    {
        $target = RegulatoryDocument::query()
            ->where('source_id', $from->source_id)
            ->where('external_id', $targetNumber)
            ->first();

        RegulatoryRelation::updateOrCreate(
            ['from_document_id' => $from->id, 'relation' => $relation, 'to_external_id' => $targetNumber],
            ['to_document_id' => $target?->id, 'method' => $method],
        );

        if ($target) {
            $this->markTarget($target, $relation);
        }
    }

    /** Connect relations that named this document before it was ingested. */
    private function resolveIncoming(RegulatoryDocument $document): void
    {
        $pending = RegulatoryRelation::query()
            ->where('to_external_id', $document->external_id)
            ->whereNull('to_document_id')
            ->whereHas('from', fn ($q) => $q->where('source_id', $document->source_id))
            ->get();

        foreach ($pending as $relation) {
            $relation->update(['to_document_id' => $document->id]);
            $this->markTarget($document, $relation->relation);
        }
    }

    private function markTarget(RegulatoryDocument $target, string $relation): void
    {
        $status = $relation === 'withdraws' ? 'withdrawn' : 'corrected';
        // A withdrawal outranks a correction.
        if ($target->status !== 'withdrawn') {
            $target->update(['status' => $status]);
        }
    }

    private function addVersion(RegulatoryDocument $document, int $number, NormalizedDocument $doc, ?array $changes): void
    {
        RegulatoryDocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => $number,
            'content_hash' => $doc->hash(),
            'metadata' => $doc->metadata(),
            'changes' => $changes,
            'retrieved_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            // Stored metadata comes back from jsonb with object keys reordered;
            // compare canonical forms so only real changes count.
            if (self::canonical($before[$key] ?? null) !== self::canonical($value)) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @return array<string, mixed> */
    private function columns(NormalizedDocument $doc): array
    {
        return $doc->metadata();
    }
}
