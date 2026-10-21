<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * An uploaded file in private storage. Downloads go through the API.
 *
 * @property int $id
 * @property string $disk
 * @property string $storage_key
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $scan_status not_scanned|clean|infected
 */
class StoredFile extends Model
{
    use BelongsToOrganization;

    protected $table = 'files';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
