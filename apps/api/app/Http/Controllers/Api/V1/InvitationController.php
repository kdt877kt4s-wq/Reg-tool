<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use App\Support\Role;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Invitations managed from inside an organization. */
class InvitationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('members.view');

        $invitations = Invitation::query()
            ->with('inviter:id,name')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $invitations->map(fn (Invitation $i) => $this->present($i))->values()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('members.manage');

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::enum(Role::class)],
            'department_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && ! Department::query()->whereKey($value)->exists()) {
                    $fail('That department does not exist.');
                }
            }],
        ]);
        $email = Str::lower($data['email']);

        $existingUserId = User::query()->where('email', $email)->value('id');
        if ($existingUserId && Membership::query()->where('user_id', $existingUserId)->where('status', Membership::ACTIVE)->exists()) {
            return response()->json(['message' => 'That person is already a member.', 'code' => 'already_member'], 422);
        }

        $token = Str::random(48);
        $organization = $this->tenant()->organization();

        $invitation = DB::transaction(function () use ($email, $data, $token, $audit) {
            // A new invitation replaces any earlier pending one for the same email.
            Invitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = Invitation::create([
                'email' => $email,
                'role' => $data['role'],
                'department_id' => $data['department_id'] ?? null,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $this->tenant()->userId(),
                'expires_at' => now()->addDays(Invitation::TTL_DAYS),
            ]);

            $audit->record('invitation.created', $invitation, null, ['email' => $email, 'role' => $invitation->role->value]);

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new OrganizationInvitation(
            organizationName: $organization->name,
            inviterName: $request->user()->name,
            roleLabel: $invitation->role->label(),
            url: rtrim((string) config('ruleradar.frontend_url'), '/').'/invite/'.$token,
            expiresAt: $invitation->expires_at,
        ));

        return response()->json(['data' => $this->present($invitation->load('inviter:id,name'))], 201);
    }

    public function destroy(int $invitationId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('members.manage');

        $invitation = Invitation::query()->findOrFail($invitationId);
        if ($invitation->isPending()) {
            $invitation->update(['revoked_at' => now()]);
            $audit->record('invitation.revoked', $invitation, null, ['email' => $invitation->email]);
        }

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /** @return array<string, mixed> */
    private function present(Invitation $i): array
    {
        return [
            'id' => $i->id,
            'email' => $i->email,
            'role' => $i->role->value,
            'role_label' => $i->role->label(),
            'department_id' => $i->department_id,
            'invited_by' => $i->inviter?->name,
            'expires_at' => $i->expires_at->toIso8601String(),
        ];
    }
}
