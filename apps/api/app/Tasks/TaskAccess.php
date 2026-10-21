<?php

namespace App\Tasks;

use App\Models\Membership;
use App\Models\Task;
use App\Support\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and do what with tasks, from the current membership:
 *   - compliance officers and administrators: everything,
 *   - auditors: see everything, change nothing,
 *   - department managers: manage their department's tasks; see those
 *     and their own,
 *   - employees: see and work on tasks assigned to them.
 * Nobody accepts their own submission.
 */
final class TaskAccess
{
    public function __construct(private TenantContext $context) {}

    private function membership(): Membership
    {
        return $this->context->membership();
    }

    private function role(): ?Role
    {
        return $this->context->role();
    }

    private function userId(): int
    {
        return (int) $this->context->userId();
    }

    public function seesAll(): bool
    {
        return in_array($this->role(), [Role::OrgAdmin, Role::ComplianceOfficer, Role::Auditor], true);
    }

    /** Limit a task query to what the current person may see. */
    public function scope(Builder $query): Builder
    {
        if ($this->seesAll()) {
            return $query;
        }
        $userId = $this->userId();
        $department = $this->membership()->department_id;

        return $query->where(function (Builder $q) use ($userId, $department) {
            $q->where('owner_id', $userId);
            if ($this->role() === Role::DepartmentManager && $department !== null) {
                $q->orWhere('department_id', $department);
            }
        });
    }

    public function canSee(Task $task): bool
    {
        return $this->seesAll()
            || $task->owner_id === $this->userId()
            || ($this->role() === Role::DepartmentManager && $task->department_id !== null && $task->department_id === $this->membership()->department_id);
    }

    /** Create tasks (managers only within their own department). */
    public function canCreate(?int $departmentId): bool
    {
        return match ($this->role()) {
            Role::OrgAdmin, Role::ComplianceOfficer => true,
            Role::DepartmentManager => $departmentId !== null && $departmentId === $this->membership()->department_id,
            default => false,
        };
    }

    /** Assign, edit, cancel, review. */
    public function canManage(Task $task): bool
    {
        return match ($this->role()) {
            Role::OrgAdmin, Role::ComplianceOfficer => true,
            Role::DepartmentManager => $task->department_id !== null && $task->department_id === $this->membership()->department_id,
            default => false,
        };
    }

    /** Start, add evidence, submit. */
    public function canWork(Task $task): bool
    {
        return $this->role() !== Role::Auditor && ($task->owner_id === $this->userId() || $this->canManage($task));
    }

    public function canReview(Task $task): bool
    {
        return $this->canManage($task) && $task->owner_id !== $this->userId() && $this->submitterIsNotMe($task);
    }

    private function submitterIsNotMe(Task $task): bool
    {
        $submitter = $task->events()->where('type', 'submitted')->latest('id')->value('actor_id');

        return $submitter === null || $submitter !== $this->userId();
    }

    /** @return list<string> */
    public function allowedActions(Task $task): array
    {
        $actions = [];
        $active = in_array($task->status, Task::ACTIVE, true);
        if ($this->canManage($task) && $active) {
            array_push($actions, 'edit', 'cancel');
        }
        if ($this->canWork($task) && in_array($task->status, [Task::OPEN, Task::RETURNED], true)) {
            $actions[] = 'start';
        }
        if ($this->canWork($task) && in_array($task->status, [Task::OPEN, Task::IN_PROGRESS, Task::RETURNED], true)) {
            array_push($actions, 'add_evidence', 'submit');
        }
        if ($task->status === Task::SUBMITTED && $this->canReview($task)) {
            array_push($actions, 'accept', 'return');
        }

        return $actions;
    }
}
