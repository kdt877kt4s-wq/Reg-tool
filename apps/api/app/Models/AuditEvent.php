<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (enforced by a database trigger). Written only through
 * App\Audit\AuditLogger, which maintains the hash chain.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $actor_id
 * @property string $action
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property ?array<string, mixed> $before
 * @property ?array<string, mixed> $after
 * @property \Illuminate\Support\Carbon $created_at
 * @property ?string $prev_hash
 * @property string $hash
 */
class AuditEvent extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
