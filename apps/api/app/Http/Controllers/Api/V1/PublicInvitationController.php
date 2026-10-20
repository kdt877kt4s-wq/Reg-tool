<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The invitee's side: look up and accept an invitation by its emailed
 * token. No tenant exists in the request yet, so lookups run as system
 * work and are limited to the single invitation the token identifies.
 * Accepting never signs the person in; they sign in normally afterwards.
 */
class PublicInvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);

        return response()->json([
            'organization_name' => $this->tenant()->bypass(fn () => Organization::query()->whereKey($invitation->organization_id)->value('name')),
            'email' => $invitation->email,
            'role_label' => $invitation->role->label(),
            'account_exists' => User::query()->where('email', $invitation->email)->exists(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function accept(Request $request, string $token, AuditLogger $audit): JsonResponse
    {
        $invitation = $this->find($token);
        $user = User::query()->where('email', $invitation->email)->first();

        if ($user) {
            $data = $request->validate(['password' => ['required', 'string']]);
            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
            }
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
        }

        $this->tenant()->bypass(fn () => DB::transaction(function () use ($invitation, $user, $data, $audit) {
            $user ??= User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);

            $membership = Membership::query()->withoutGlobalScope('organization')->updateOrCreate(
                ['organization_id' => $invitation->organization_id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'department_id' => $invitation->department_id, 'status' => Membership::ACTIVE],
            );

            Invitation::query()->withoutGlobalScope('organization')->whereKey($invitation->id)->update(['accepted_at' => now()]);

            $audit->record('invitation.accepted', $membership, null, [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
            ], $invitation->organization_id, $user->id);
        }));

        return response()->json(['message' => 'You have joined the organization. Sign in to continue.']);
    }

    private function find(string $token): Invitation
    {
        $invitation = $this->tenant()->bypass(fn () => Invitation::query()
            ->withoutGlobalScope('organization')
            ->where('token_hash', Invitation::hashToken($token))
            ->first());

        if (! $invitation || ! $invitation->isPending()) {
            abort(response()->json([
                'message' => 'This invitation is no longer valid. Ask your administrator for a new one.',
                'code' => 'invitation_invalid',
            ], 404));
        }

        return $invitation;
    }
}
