<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global reference vocabulary (not tenant data). Industry packs let the
 * same profile and screening logic serve healthcare now and other
 * regulated industries later.
 *
 * @property string $kind
 * @property string $code
 * @property string $label
 */
class TaxonomyTerm extends Model
{
    public const KINDS = ['org_type', 'program', 'function', 'topic', 'state'];

    protected $fillable = [
        'industry', 'kind', 'code', 'label', 'description', 'metadata', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
