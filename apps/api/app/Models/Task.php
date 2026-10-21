<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A compliance task: work the organization committed to.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $assessment_id
 * @property ?int $action_id
 * @property ?int $document_id
 * @property string $title
 * @property ?string $description
 * @property ?int $owner_id
 * @property ?int $department_id
 * @property string $priority
 * @property ?Carbon $due_date
 * @property ?string $due_date_type official|internal
 * @property ?string $due_date_basis
 * @property string $status
 * @property ?string $status_note
 * @property int $created_by
 * @property ?int $reviewed_by
 * @property ?Carbon $submitted_at
 * @property ?Carbon $completed_at
 */
class Task extends Model
{
    use BelongsToOrganization;

    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const ACTIVE = [self::OPEN, self::IN_PROGRESS, self::SUBMITTED, self::RETURNED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null && in_array($this->status, self::ACTIVE, true) && $this->due_date->lt(today());
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDocument::class, 'document_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(SuggestedAction::class, 'action_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(TaskEvidence::class)->orderBy('id');
    }
}
