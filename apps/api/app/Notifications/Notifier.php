<?php

namespace App\Notifications;

use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * In-app notifications, plus email unless the person turned that kind
 * off. Runs inside an organization context. A dedupe key makes repeated
 * checks (daily jobs) notify once.
 */
final class Notifier
{
    public const KINDS = [
        'task_assigned' => 'A task is assigned to you',
        'task_submitted' => 'A task is ready for your review',
        'task_returned' => 'A task you submitted was returned',
        'task_due_soon' => 'A task of yours is due within 3 days',
        'task_overdue' => 'A task of yours is overdue',
        'profile_matches' => 'New publications match your regulatory profile',
        'source_changed' => 'An approved assessment needs another look',
    ];

    public function __construct(private TenantContext $context) {}

    public function send(int $userId, string $kind, string $title, ?string $body = null, ?string $path = null, ?string $dedupeKey = null): ?UserNotification
    {
        $organizationId = $this->context->organizationId();
        if ($organizationId === null || ! array_key_exists($kind, self::KINDS)) {
            return null;
        }

        // Only active members of this organization are notified.
        $isMember = Membership::query()->where('user_id', $userId)->where('status', Membership::ACTIVE)->exists();
        if (! $isMember) {
            return null;
        }
        if ($dedupeKey !== null && UserNotification::query()->where('user_id', $userId)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $url = $path ? rtrim((string) config('ruleradar.frontend_url'), '/').$path : null;
        $notification = UserNotification::create([
            'user_id' => $userId,
            'kind' => $kind,
            'title' => mb_substr($title, 0, 255),
            'body' => $body,
            'url' => $path,
            'dedupe_key' => $dedupeKey,
            'created_at' => now(),
        ]);

        $wantsEmail = NotificationPreference::query()->where('user_id', $userId)->where('kind', $kind)->value('email') ?? true;
        $email = User::query()->whereKey($userId)->value('email');

        if ($wantsEmail && $email) {
            try {
                Notification::route('mail', $email)->notify(new RuleRadarNotice(
                    organizationName: (string) $this->context->organization()?->name,
                    title: $title,
                    body: $body,
                    url: $url,
                ));
                $notification->update(['emailed_at' => now()]);
            } catch (Throwable $e) {
                // The in-app notification stands even if email fails.
                Log::warning('notification.email_failed', ['notification' => $notification->id, 'error' => $e->getMessage()]);
            }
        }

        return $notification;
    }

    /** @param iterable<int> $userIds */
    public function sendMany(iterable $userIds, string $kind, string $title, ?string $body = null, ?string $path = null, ?string $dedupeKey = null): int
    {
        $sent = 0;
        foreach (collect($userIds)->unique() as $userId) {
            if ($this->send((int) $userId, $kind, $title, $body, $path, $dedupeKey)) {
                $sent++;
            }
        }

        return $sent;
    }
}
