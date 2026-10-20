<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class Department extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name'];
}
