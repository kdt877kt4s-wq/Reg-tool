<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email' => 'boolean'];
    }
}
