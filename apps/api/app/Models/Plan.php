<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property string $name
 * @property array<string, mixed> $limits
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'limits'];

    protected function casts(): array
    {
        return ['limits' => 'array'];
    }
}
