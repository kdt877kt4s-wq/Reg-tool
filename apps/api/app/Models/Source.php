<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property int $consecutive_failures
 * @property ?Carbon $cursor_date
 * @property ?Carbon $last_run_at
 * @property ?Carbon $last_success_at
 * @property ?string $last_error
 */
class Source extends Model
{
    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cursor_date' => 'date',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }
}
