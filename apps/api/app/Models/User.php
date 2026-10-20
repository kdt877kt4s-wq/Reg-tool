<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?string $mfa_secret
 * @property ?Carbon $mfa_confirmed_at
 * @property ?int $mfa_last_used_step
 * @property ?array<int, string> $mfa_recovery_codes
 * @property bool $is_platform_admin
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'mfa_secret', 'mfa_recovery_codes', 'mfa_last_used_step'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_last_used_step' => 'integer',
            'mfa_recovery_codes' => 'encrypted:array',
        ];
    }

    public function hasMfa(): bool
    {
        return $this->mfa_confirmed_at !== null;
    }

    /** All memberships, across organizations (row-level security limits them to this user's). */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope('organization');
    }
}
