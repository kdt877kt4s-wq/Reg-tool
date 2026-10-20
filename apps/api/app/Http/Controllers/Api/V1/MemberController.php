<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Membership;
use App\Support\Role;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('members.view');

        $members = Membership::query()
            ->with(['user:id,name,email,mfa_confirmed_at', 'department:id,name'])
            ->where('status', '!=', Membership::REMOVED)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $members->map(fn (Membership $m) => $this->present($m))->values()]);
    }

    public function update(Request $request, int $membershipId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('members.manage');

        $data = $request->validate([
            'role' => ['sometimes', Rule::enum(Role::class)],
            'department_id' => ['sometimes', 'nullable', 'integer', $this->departmentExists()],
            'status' => ['sometimes', Rule::in([Membership::ACTIVE, Membership::SUSPENDED])],
        ]);

        $membership = DB::transaction(function () use ($membershipId, $data, $audit) {
            $membership = Membership::query()->lockForUpdate()->findOrFail($membershipId);
            abort_if($membership->status === Membership::REMOVED, 404);

            $before = $this->auditFields($membership);
            $membership->fill($data);

            if ($this->wouldLeaveNoAdmin($membership)) {
                abort(response()->json([
                    'message' => 'Every organization needs at least one active administrator.',
                    'code' => 'last_admin',
                ], 422));
            }

            $membership->save();
            $audit->record('member.updated', $membership, $before, $this->auditFields($membership));

            return $membership;
        });

        return response()->json(['data' => $this->present($membership->load(['user:id,name,email,mfa_confirmed_at', 'department:id,name']))]);
    }

    public function destroy(int $membershipId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('members.manage');

        DB::transaction(function () use ($membershipId, $audit) {
            $membership = Membership::query()->lockForUpdate()->findOrFail($membershipId);
            abort_if($membership->status === Membership::REMOVED, 404);

            $before = $this->auditFields($membership);
            $membership->status = Membership::REMOVED;

            if ($this->wouldLeaveNoAdmin($membership)) {
                abort(response()->json([
                    'message' => 'Every organization needs at least one active administrator.',
                    'code' => 'last_admin',
                ], 422));
            }

            $membership->save();
            $audit->record('member.removed', $membership, $before, $this->auditFields($membership));
        });

        return response()->json(['message' => 'Member removed.']);
    }

    private function wouldLeaveNoAdmin(Membership $changed): bool
    {
        $stillAdmin = $changed->role === Role::OrgAdmin && $changed->status === Membership::ACTIVE;
        if ($stillAdmin) {
            return false;
        }

        return ! Membership::query()
            ->where('id', '!=', $changed->id)
            ->where('role', Role::OrgAdmin->value)
            ->where('status', Membership::ACTIVE)
            ->exists();
    }

    /** A department in the current organization (the tenant scope applies). */
    private function departmentExists(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && ! Department::query()->whereKey($value)->exists()) {
                $fail('That department does not exist.');
            }
        };
    }

    /** @return array<string, mixed> */
    private function auditFields(Membership $m): array
    {
        return [
            'user_id' => $m->user_id,
            'role' => $m->role->value,
            'department_id' => $m->department_id,
            'status' => $m->status,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Membership $m): array
    {
        return [
            'id' => $m->id,
            'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email, 'mfa_enabled' => $m->user->mfa_confirmed_at !== null],
            'role' => $m->role->value,
            'role_label' => $m->role->label(),
            'department' => $m->department ? ['id' => $m->department->id, 'name' => $m->department->name] : null,
            'status' => $m->status,
        ];
    }
}
