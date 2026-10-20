<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tenant root. Row-level security limits reads to organizations the
 * current user belongs to, and writes to the current organization.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class Organization extends Model
{
    protected $fillable = ['name', 'slug', 'industry', 'data_retention_days', 'settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'data_retention_days' => 'integer',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }
}
