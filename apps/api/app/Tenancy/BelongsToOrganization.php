<?php

namespace App\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application-level tenant scope. Queries are limited to the current
 * organization; with no organization in context they return nothing
 * (fail closed). Row-level security enforces the same rule in PostgreSQL.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->bypassing()) {
                return;
            }

            $organizationId = $context->organizationId();
            $column = $query->getModel()->getTable().'.organization_id';

            $organizationId === null
                ? $query->whereRaw('1 = 0')
                : $query->where($column, $organizationId);
        });

        static::creating(function (Model $model) {
            if (! $model->getAttribute('organization_id')) {
                $model->setAttribute('organization_id', app(TenantContext::class)->organizationId());
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
