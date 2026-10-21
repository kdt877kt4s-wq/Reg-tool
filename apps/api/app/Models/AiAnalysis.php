<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI analysis of one document version for one organization. Never
 * edited after completion; people edit the impact assessment instead.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $document_id
 * @property int $version_id
 * @property int $profile_version
 * @property string $status queued|running|completed|failed
 * @property ?string $model
 * @property string $prompt_version
 * @property ?array<string, int> $passage_refs
 * @property ?array<string, mixed> $output
 * @property ?array<string, mixed> $validation
 * @property ?string $confidence
 * @property ?string $error
 * @property int $attempts
 * @property ?int $requested_by
 * @property ?Carbon $completed_at
 */
class AiAnalysis extends Model
{
    use BelongsToOrganization;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $table = 'ai_analyses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'passage_refs' => 'array',
            'output' => 'array',
            'validation' => 'array',
            'profile_version' => 'integer',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDocument::class, 'document_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
