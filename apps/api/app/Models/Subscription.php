<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subscription record only. No payment processing in the MVP.
 */
class Subscription extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'plan_id', 'status', 'current_period_end'];

    protected function casts(): array
    {
        return ['current_period_end' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
