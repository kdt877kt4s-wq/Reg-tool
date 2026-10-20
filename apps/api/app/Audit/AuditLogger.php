<?php

namespace App\Audit;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes tamper-evident audit events. Each organization has its own hash
 * chain: hash = sha256(previous hash + canonical event content). Changing
 * or removing any past event breaks every hash after it.
 */
final class AuditLogger
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?int $organizationId = null, ?int $actorId = null): AuditEvent
    {
        $organizationId ??= $this->context->organizationId();
        if ($organizationId === null) {
            throw new RuntimeException('Audit events need an organization.');
        }

        $request = app()->bound('request') ? request() : null;

        return DB::transaction(function () use ($action, $subject, $before, $after, $organizationId, $actorId, $request) {
            // Serialize writers per organization so the chain never forks.
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            $previous = AuditEvent::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->value('hash');

            $fields = [
                'organization_id' => $organizationId,
                'actor_id' => $actorId ?? $this->context->userId(),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'before' => $before,
                'after' => $after,
                'created_at' => Carbon::now('UTC')->startOfSecond(),
            ];

            $event = new AuditEvent;
            $event->forceFill($fields + [
                'ip' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
                'prev_hash' => $previous,
                'hash' => self::hash($previous, $fields),
            ]);
            $event->save();

            return $event;
        });
    }

    /** @param array<string, mixed> $fields */
    public static function hash(?string $previous, array $fields): string
    {
        $content = [
            'organization_id' => (int) $fields['organization_id'],
            'actor_id' => $fields['actor_id'] === null ? null : (int) $fields['actor_id'],
            'action' => $fields['action'],
            'subject_type' => $fields['subject_type'],
            'subject_id' => $fields['subject_id'] === null ? null : (int) $fields['subject_id'],
            'before' => self::canonical($fields['before']),
            'after' => self::canonical($fields['after']),
            'created_at' => Carbon::parse($fields['created_at'])->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        return hash('sha256', ($previous ?? '').'|'.json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Sort keys recursively: jsonb does not preserve key order. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * Recompute an organization's chain.
     *
     * @return array{valid: bool, checked: int, broken_at_id: ?int}
     */
    public static function verify(int $organizationId): array
    {
        $previous = null;
        $checked = 0;

        foreach (AuditEvent::query()->withoutGlobalScope('organization')->where('organization_id', $organizationId)->orderBy('id')->cursor() as $event) {
            $checked++;
            $expected = self::hash($previous, [
                'organization_id' => $event->organization_id,
                'actor_id' => $event->actor_id,
                'action' => $event->action,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'before' => $event->before,
                'after' => $event->after,
                'created_at' => $event->created_at,
            ]);

            if ($event->prev_hash !== $previous || ! hash_equals($expected, $event->hash)) {
                return ['valid' => false, 'checked' => $checked, 'broken_at_id' => $event->id];
            }
            $previous = $event->hash;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at_id' => null];
    }
}
