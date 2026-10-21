<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An in-app notification for one person in one organization.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $title
 * @property ?string $body
 * @property ?string $url
 * @property ?Carbon $read_at
 * @property ?Carbon $emailed_at
 */
class UserNotification extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'emailed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
