<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The organization's regulatory profile: the facts RuleRadar uses to judge
 * which regulations may be relevant. Never assumed; always stated.
 *
 * @property array<int, string> $org_types
 * @property array<int, string> $states
 * @property array<int, string> $programs
 * @property array<int, string> $functions
 * @property array<int, string> $products
 * @property array<int, string> $obligations
 * @property int $version
 * @property ?Carbon $completed_at
 */
class OrganizationProfile extends Model
{
    use BelongsToOrganization;

    public const LIST_FIELDS = ['org_types', 'states', 'programs', 'functions', 'products', 'obligations'];

    protected $fillable = [
        'organization_id', 'org_types', 'states', 'programs', 'functions',
        'products', 'obligations', 'version', 'completed_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'org_types' => 'array',
            'states' => 'array',
            'programs' => 'array',
            'functions' => 'array',
            'products' => 'array',
            'obligations' => 'array',
            'version' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return collect(self::LIST_FIELDS)
            ->mapWithKeys(fn (string $f) => [$f => $this->getAttribute($f) ?? []])
            ->all();
    }
}
