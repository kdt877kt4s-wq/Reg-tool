<?php

namespace App\Models;

use App\Support\Role;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property Role $role
 * @property ?int $department_id
 * @property string $status
 */
class Membership extends Model
{
    use BelongsToOrganization;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const REMOVED = 'removed';

    protected $fillable = ['organization_id', 'user_id', 'role', 'department_id', 'status'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
