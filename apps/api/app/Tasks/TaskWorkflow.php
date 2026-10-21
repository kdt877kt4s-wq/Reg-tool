<?php

namespace App\Tasks;

use App\Audit\AuditLogger;
use App\Models\ImpactAssessment;
use App\Models\Membership;
use App\Models\RegulatoryDocument;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Notifications\Notifier;
use App\Support\Role;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a task goes through here: one history row, one audit
 * event and the right notifications per change.
 */
final class TaskWorkflow
{
    public function __construct(private TenantContext $context, private AuditLogger $audit, private Notifier $notifier) {}

    /**
     * Turn the accepted actions of a just-approved assessment into tasks.
     * Actions no longer accepted cancel their open tasks.
     *
     * @return int tasks created
     */
    public function syncFromAssessment(ImpactAssessment $assessment): int
    {
        $document = RegulatoryDocument::query()->find($assessment->document_id, ['id', 'external_id', 'effective_date']);
        $officialDue = $document?->effective_date?->isFuture() ? $document->effective_date : null;
        $created = 0;

        foreach ($assessment->actions()->get() as $action) {
            $task = Task::query()->where('action_id', $action->id)->first();

            if ($action->state === 'accepted' && ! $task) {
                $this->create([
                    'assessment_id' => $assessment->id,
                    'action_id' => $action->id,
                    'document_id' => $assessment->document_id,
                    'title' => $action->title,
                    'description' => $action->description,
                    'priority' => $action->priority,
                    'due_date' => $officialDue?->toDateString(),
                    'due_date_type' => $officialDue ? 'official' : null,
                    'due_date_basis' => $officialDue ? "Effective date of Federal Register document {$document->external_id}" : null,
                ]);
                $created++;
            } elseif ($action->state !== 'accepted' && $task && in_array($task->status, Task::ACTIVE, true)) {
                $this->transition($task, Task::CANCELLED, 'cancelled', ['reason' => 'The action was dismissed when the assessment was revised.'], 'The action was dismissed when the assessment was revised.');
            }
        }

        return $created;
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): Task
    {
        return DB::transaction(function () use ($fields) {
            $task = Task::create($fields + ['status' => Task::OPEN, 'created_by' => $this->context->userId()]);
            $this->event($task, 'created', array_filter([
                'title' => $task->title,
                'from_action' => $task->action_id,
                'due_date' => $task->due_date?->toDateString(),
                'due_date_type' => $task->due_date_type,
            ]));
            $this->audit->record('task.created', $task, null, ['title' => $task->title, 'action_id' => $task->action_id]);

            if ($task->owner_id) {
                $this->notifyAssigned($task);
            }

            return $task;
        });
    }

    /** @param array<string, mixed> $changes */
    public function update(Task $task, array $changes): Task
    {
        return DB::transaction(function () use ($task, $changes) {
            $before = $task->only(array_keys($changes));
            $previousOwner = $task->owner_id;
            $task->fill($changes);
            $dirty = $task->getDirty();
            if ($dirty === []) {
                return $task;
            }
            $task->save();

            $diff = [];
            foreach (array_keys($dirty) as $key) {
                $diff[$key] = ['from' => $this->plain($before[$key] ?? null), 'to' => $this->plain($task->getAttribute($key))];
            }
            $this->event($task, isset($dirty['owner_id']) ? 'assigned' : 'edited', $diff);
            $this->audit->record('task.updated', $task, array_map(fn ($d) => $d['from'], $diff), array_map(fn ($d) => $d['to'], $diff));

            if (isset($dirty['owner_id']) && $task->owner_id && $task->owner_id !== $previousOwner) {
                $this->notifyAssigned($task);
            }

            return $task;
        });
    }

    public function start(Task $task): Task
    {
        return $this->transition($task, Task::IN_PROGRESS, 'started');
    }

    public function submit(Task $task): Task
    {
        if (! $task->evidence()->exists()) {
            throw ValidationException::withMessages(['evidence' => 'Add at least one piece of evidence before submitting.']);
        }

        $task = $this->transition($task, Task::SUBMITTED, 'submitted', [], null, ['submitted_at' => now()]);
        $this->notifier->sendMany(
            $this->reviewers($task),
            'task_submitted',
            "Ready for review: {$task->title}",
            'Evidence was submitted. Accept it, or return the task with a reason.',
            "/tasks/{$task->id}",
            "task:{$task->id}:submitted:".$task->submitted_at->timestamp,
        );

        return $task;
    }

    public function accept(Task $task): Task
    {
        return $this->transition($task, Task::DONE, 'accepted', [], null, ['completed_at' => now(), 'reviewed_by' => $this->context->userId()]);
    }

    public function returnToOwner(Task $task, string $reason): Task
    {
        $task = $this->transition($task, Task::RETURNED, 'returned', ['reason' => $reason], $reason, ['reviewed_by' => $this->context->userId()]);
        if ($task->owner_id) {
            $this->notifier->send(
                $task->owner_id,
                'task_returned',
                "Returned: {$task->title}",
                "Reason: {$reason}",
                "/tasks/{$task->id}",
                "task:{$task->id}:returned:".now()->timestamp,
            );
        }

        return $task;
    }

    public function cancel(Task $task, string $reason): Task
    {
        return $this->transition($task, Task::CANCELLED, 'cancelled', ['reason' => $reason], $reason);
    }

    /** @param array<string, mixed> $details */
    public function event(Task $task, string $type, array $details = []): void
    {
        TaskEvent::create([
            'organization_id' => $task->organization_id,
            'task_id' => $task->id,
            'actor_id' => $this->context->userId(),
            'type' => $type,
            'details' => $details === [] ? null : $details,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $extra
     */
    private function transition(Task $task, string $status, string $event, array $details = [], ?string $note = null, array $extra = []): Task
    {
        return DB::transaction(function () use ($task, $status, $event, $details, $note, $extra) {
            $from = $task->status;
            $task->update(['status' => $status, 'status_note' => $note] + $extra);
            $this->event($task, $event, ['from' => $from, 'to' => $status] + $details);
            $this->audit->record("task.{$event}", $task, ['status' => $from], ['status' => $status] + $details);

            return $task;
        });
    }

    private function notifyAssigned(Task $task): void
    {
        $due = $task->due_date ? ' Due '.$task->due_date->format('M j, Y').($task->due_date_type === 'official' ? ' (official deadline).' : ' (internal target).') : '';
        $this->notifier->send(
            $task->owner_id,
            'task_assigned',
            "Assigned to you: {$task->title}",
            trim("Priority: {$task->priority}.{$due}"),
            "/tasks/{$task->id}",
            "task:{$task->id}:assigned:{$task->owner_id}:".now()->timestamp,
        );
    }

    /**
     * Compliance officers, plus the manager of the task's department,
     * except whoever submitted.
     *
     * @return list<int>
     */
    private function reviewers(Task $task): array
    {
        $ids = Membership::query()
            ->where('status', Membership::ACTIVE)
            ->where(function ($q) use ($task) {
                $q->where('role', Role::ComplianceOfficer->value);
                if ($task->department_id) {
                    $q->orWhere(fn ($m) => $m->where('role', Role::DepartmentManager->value)->where('department_id', $task->department_id));
                }
            })
            ->pluck('user_id')
            ->all();

        return array_values(array_diff($ids, [$this->context->userId(), $task->owner_id]));
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
