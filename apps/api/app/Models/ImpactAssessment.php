<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The organization's human decision about a document.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $document_id
 * @property ?int $analysis_id
 * @property int $document_version
 * @property ?string $decision applicable|not_applicable|monitor
 * @property ?string $rationale
 * @property list<string> $affected_functions
 * @property string $status draft|approved
 * @property ?int $updated_by
 * @property ?int $approved_by
 * @property ?Carbon $approved_at
 */
class ImpactAssessment extends Model
{
    use BelongsToOrganization;

    public const DECISIONS = ['applicable', 'not_applicable', 'monitor'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'affected_functions' => 'array',
            'document_version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDocument::class, 'document_id');
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(AiAnalysis::class, 'analysis_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SuggestedAction::class, 'assessment_id')->orderBy('position')->orderBy('id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
