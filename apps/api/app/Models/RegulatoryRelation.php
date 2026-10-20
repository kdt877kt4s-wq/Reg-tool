<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This document corrects/withdraws that one." The target may not be
 * ingested yet; to_external_id always holds the number as published.
 *
 * @property int $id
 * @property int $from_document_id
 * @property string $relation
 * @property string $to_external_id
 * @property ?int $to_document_id
 * @property string $method structured_field | parsed_from_text
 */
class RegulatoryRelation extends Model
{
    protected $guarded = [];

    public function from(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDocument::class, 'from_document_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDocument::class, 'to_document_id');
    }
}
