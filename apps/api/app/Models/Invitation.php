<?php

namespace App\Models;

use App\Support\Role;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property Role $role
 * @property ?int $department_id
 * @property Carbon $expires_at
 * @property ?Carbon $accepted_at
 * @property ?Carbon $revoked_at
 */
class Invitation extends Model
{
    use BelongsToOrganization;

    public const TTL_DAYS = 7;

    protected $fillable = ['organization_id', 'email', 'role', 'department_id', 'token_hash', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
