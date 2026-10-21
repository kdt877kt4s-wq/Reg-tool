<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed compliance action. Becomes an assignable task only after a
 * compliance officer accepts it and approves the assessment (Stage 4).
 *
 * @property int $id
 * @property int $assessment_id
 * @property string $title
 * @property ?string $description
 * @property string $priority
 * @property list<int> $citation_passage_ids
 * @property string $origin ai|person
 * @property string $state proposed|accepted|dismissed
 * @property int $position
 */
class SuggestedAction extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'citation_passage_ids' => 'array',
            'position' => 'integer',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ImpactAssessment::class, 'assessment_id');
    }
}
