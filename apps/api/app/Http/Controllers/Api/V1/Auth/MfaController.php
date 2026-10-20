<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Security\RecoveryCodes;
use App\Security\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor enrollment for the signed-in user (account level, not tenant).
 */
class MfaController extends Controller
{
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasMfa()) {
            return response()->json(['message' => 'Two-factor login is already on.', 'code' => 'mfa_already_enabled'], 409);
        }

        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $user->email),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        /** @var User $user */
        $user = $request->user();
        if ($user->hasMfa()) {
            return response()->json(['message' => 'Two-factor login is already on.', 'code' => 'mfa_already_enabled'], 409);
        }
        if (! $user->mfa_secret) {
            throw ValidationException::withMessages(['code' => 'Start two-factor setup first.']);
        }

        $step = Totp::verify($user->mfa_secret, $data['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        $codes = RecoveryCodes::generate();
        $user->forceFill([
            'mfa_confirmed_at' => now(),
            'mfa_last_used_step' => $step,
            'mfa_recovery_codes' => $codes['hashes'],
        ])->save();

        Log::channel('stack')->notice('security.mfa_enabled', ['user_id' => $user->id]);

        return response()->json(['recovery_codes' => $codes['plain']]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $this->verifiedUser($request, $data['code']);

        $codes = RecoveryCodes::generate();
        $user->forceFill(['mfa_recovery_codes' => $codes['hashes']])->save();

        return response()->json(['recovery_codes' => $codes['plain']]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $requiredByRole = $user->memberships()
            ->where('status', Membership::ACTIVE)
            ->get()
            ->contains(fn (Membership $m) => $m->role->requiresMfa());
        if ($requiredByRole) {
            return response()->json([
                'message' => 'Your role requires two-factor login, so it cannot be turned off.',
                'code' => 'mfa_required_by_role',
            ], 403);
        }

        $this->verifiedUser($request, $data['code']);
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_last_used_step' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        Log::channel('stack')->notice('security.mfa_disabled', ['user_id' => $user->id]);

        return response()->json(['message' => 'Two-factor login is off.']);
    }

    private function verifiedUser(Request $request, string $code): User
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasMfa()) {
            abort(response()->json(['message' => 'Two-factor login is not on.', 'code' => 'mfa_not_enabled'], 409));
        }

        $step = Totp::verify((string) $user->mfa_secret, $code, lastUsedStep: $user->mfa_last_used_step);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        $user->forceFill(['mfa_last_used_step' => $step])->save();

        return $user;
    }
}
