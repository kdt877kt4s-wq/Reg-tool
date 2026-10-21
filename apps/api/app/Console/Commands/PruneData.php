<?php

namespace App\Console\Commands;

use App\Models\UserNotification;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Routine retention: read notifications older than the retention period
 * and expired sign-in tokens. Audit events, tasks and evidence are never
 * pruned here; they are compliance records.
 */
class PruneData extends Command
{
    protected $signature = 'ruleradar:prune';

    protected $description = 'Delete old read notifications and expired sign-in tokens';

    public function handle(): int
    {
        $days = (int) config('ruleradar.notification_retention_days', 180);
        $notifications = UserNotification::query()->withoutGlobalScopes()
            ->whereNotNull('read_at')->where('created_at', '<', now()->subDays($days))->delete();
        $tokens = PersonalAccessToken::query()->where('expires_at', '<', now()->subDay())->delete();

        $this->line(json_encode(['notifications_deleted' => $notifications, 'tokens_deleted' => $tokens]));

        return self::SUCCESS;
    }
}
