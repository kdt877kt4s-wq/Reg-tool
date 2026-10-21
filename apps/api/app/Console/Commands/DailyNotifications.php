<?php

namespace App\Console\Commands;

use App\Models\ImpactAssessment;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RegulatoryDocument;
use App\Models\Task;
use App\Notifications\Notifier;
use App\Regulations\ProfileMatcher;
use App\Support\Role;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Once a day, per organization:
 *   - tasks due within 3 days, and overdue tasks (owner),
 *   - a digest of newly ingested publications that match the profile
 *     (compliance officers and administrators),
 *   - approved assessments whose document changed since approval.
 * Each item is reported once (dedupe keys and "already reported" markers).
 */
class DailyNotifications extends Command
{
    protected $signature = 'ruleradar:daily-notifications';

    protected $description = 'Send due-date reminders, profile-match digests and source-change alerts';

    public function handle(TenantContext $context, Notifier $notifier, ProfileMatcher $matcher): int
    {
        $organizations = $context->bypass(fn () => Organization::query()->orderBy('id')->get());
        $totals = ['due_soon' => 0, 'overdue' => 0, 'profile_matches' => 0, 'source_changed' => 0];
        $state = $context->snapshot();

        foreach ($organizations as $organization) {
            try {
                // A system context inside the organization: RLS applies.
                $context->setBypass(false);
                $context->setUser(null);
                $context->setOrganization($organization, new Membership(['organization_id' => $organization->id, 'role' => Role::OrgAdmin->value, 'status' => Membership::ACTIVE]));

                foreach ($this->forOrganization($organization, $notifier, $matcher, $context) as $k => $n) {
                    $totals[$k] += $n;
                }
            } catch (Throwable $e) {
                Log::error('daily_notifications.failed', ['organization' => $organization->id, 'error' => $e->getMessage()]);
                $this->error("Organization {$organization->id}: {$e->getMessage()}");
            } finally {
                $context->restore($state);
            }
        }

        $this->line(json_encode(['organizations' => $organizations->count()] + $totals));

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function forOrganization(Organization $organization, Notifier $notifier, ProfileMatcher $matcher, TenantContext $context): array
    {
        $today = today();
        $counts = ['due_soon' => 0, 'overdue' => 0, 'profile_matches' => 0, 'source_changed' => 0];

        $tasks = Task::query()->whereIn('status', [Task::OPEN, Task::IN_PROGRESS, Task::RETURNED])
            ->whereNotNull('owner_id')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->copy()->addDays(3))
            ->get();
        foreach ($tasks as $task) {
            $overdue = $task->due_date->lt($today);
            $type = $task->due_date_type === 'official' ? 'official deadline' : 'internal target';
            $sent = $notifier->send(
                $task->owner_id,
                $overdue ? 'task_overdue' : 'task_due_soon',
                ($overdue ? 'Overdue: ' : 'Due soon: ').$task->title,
                'Due '.$task->due_date->format('M j, Y')." ({$type}).",
                "/tasks/{$task->id}",
                "task:{$task->id}:".($overdue ? 'overdue' : 'due_soon').':'.$task->due_date->toDateString(),
            );
            if ($sent) {
                $counts[$overdue ? 'overdue' : 'due_soon']++;
            }
        }

        $leads = Membership::query()->where('status', Membership::ACTIVE)
            ->whereIn('role', [Role::ComplianceOfficer->value, Role::OrgAdmin->value])
            ->pluck('user_id');

        // Profile matches first seen since the last digest.
        $parts = $matcher->parts();
        $through = $organization->match_digest_through ?? now()->subDay();
        $cutoff = now();
        if ($parts !== []) {
            $matches = $matcher->scope(RegulatoryDocument::query(), $parts)
                ->where('first_seen_at', '>', $through)
                ->where('first_seen_at', '<=', $cutoff)
                ->orderByDesc('publication_date')
                ->get(['id', 'external_id', 'title']);
            if ($matches->isNotEmpty()) {
                $n = $matches->count();
                $list = $matches->take(5)->map(fn ($d) => "{$d->external_id}: ".mb_substr($d->title, 0, 120))->implode("\n");
                $counts['profile_matches'] = $notifier->sendMany(
                    $leads,
                    'profile_matches',
                    $n === 1 ? '1 new publication matches your profile' : "{$n} new publications match your profile",
                    $list.($n > 5 ? "\nand ".($n - 5).' more.' : '')."\nA match is a screening hint, not a finding that the rule applies.",
                    '/regulations?relevance=profile',
                    'profile_matches:'.$cutoff->format('Y-m-d-His'),
                );
            }
        }
        $context->bypass(fn () => Organization::query()->whereKey($organization->id)->update(['match_digest_through' => $cutoff]));

        // Approved decisions made on an older version of the document.
        $changed = ImpactAssessment::query()
            ->where('status', 'approved')
            ->with('document:id,external_id,title,current_version')
            ->get()
            ->filter(fn ($a) => $a->document && $a->document_version !== $a->document->current_version
                && ($a->change_notified_version === null || $a->change_notified_version < $a->document->current_version));
        foreach ($changed as $assessment) {
            $doc = $assessment->document;
            $recipients = $leads->merge([$assessment->approved_by])->filter()->unique();
            $counts['source_changed'] += $notifier->sendMany(
                $recipients,
                'source_changed',
                "Document changed after approval: {$doc->external_id}",
                "The approved assessment was made on version {$assessment->document_version}; the document is now at version {$doc->current_version}. Review whether the decision still holds.",
                "/regulations/{$doc->id}",
                "assessment:{$assessment->id}:changed:{$doc->current_version}",
            );
            $assessment->forceFill(['change_notified_version' => $doc->current_version])->saveQuietly();
        }

        return $counts;
    }
}
