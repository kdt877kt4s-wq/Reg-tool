<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history: a new row whenever the source's metadata changes.
 *
 * @property int $id
 * @property int $document_id
 * @property int $version_no
 * @property array<string, mixed> $metadata
 * @property ?array<string, array{from: mixed, to: mixed}> $changes
 */
class RegulatoryDocumentVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'changes' => 'array',
            'retrieved_at' => 'datetime',
            'version_no' => 'integer',
            'text_chars' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDocument::class, 'document_id');
    }
}
