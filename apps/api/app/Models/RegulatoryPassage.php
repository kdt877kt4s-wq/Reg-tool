<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A span of a document version's text: the unit citations point at.
 *
 * @property int $id
 * @property int $document_id
 * @property int $version_id
 * @property int $ordinal
 * @property ?string $heading_path
 * @property string $text
 * @property ?int $page_start
 * @property ?int $page_end
 */
class RegulatoryPassage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['tsv', 'embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDocument::class, 'document_id');
    }
}
