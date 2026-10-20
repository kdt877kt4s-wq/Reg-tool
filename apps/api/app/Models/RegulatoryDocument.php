<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A publication retrieved from an authoritative source. Global (not tenant
 * data). Columns mirror the source; nothing is inferred.
 *
 * @property int $id
 * @property int $source_id
 * @property string $external_id
 * @property string $type
 * @property ?string $action
 * @property string $title
 * @property ?string $abstract
 * @property ?string $dates_text
 * @property list<array{slug: string, name: string}> $agencies
 * @property list<string> $cfr_parts
 * @property list<string> $docket_ids
 * @property list<string> $rins
 * @property ?string $citation
 * @property ?int $start_page
 * @property ?int $end_page
 * @property ?bool $significant
 * @property Carbon $publication_date
 * @property ?Carbon $effective_date
 * @property ?Carbon $comments_close_date
 * @property ?string $official_pdf_url
 * @property ?string $html_url
 * @property ?string $raw_text_url
 * @property string $status
 * @property string $text_status
 * @property string $content_hash
 * @property int $current_version
 * @property bool $is_demo
 * @property Carbon $first_seen_at
 * @property Carbon $last_changed_at
 */
class RegulatoryDocument extends Model
{
    public const TYPE_LABELS = [
        'rule' => 'Final rule',
        'proposed_rule' => 'Proposed rule',
        'notice' => 'Notice',
        'presidential' => 'Presidential document',
        'other' => 'Other',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'agencies' => 'array',
            'cfr_parts' => 'array',
            'docket_ids' => 'array',
            'rins' => 'array',
            'significant' => 'boolean',
            'is_demo' => 'boolean',
            'start_page' => 'integer',
            'end_page' => 'integer',
            'current_version' => 'integer',
            'publication_date' => 'date',
            'effective_date' => 'date',
            'comments_close_date' => 'date',
            'first_seen_at' => 'datetime',
            'last_changed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RegulatoryDocumentVersion::class, 'document_id');
    }

    public function passages(): HasMany
    {
        return $this->hasMany(RegulatoryPassage::class, 'document_id');
    }

    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(RegulatoryRelation::class, 'from_document_id');
    }

    public function incomingRelations(): HasMany
    {
        return $this->hasMany(RegulatoryRelation::class, 'to_document_id');
    }
}
