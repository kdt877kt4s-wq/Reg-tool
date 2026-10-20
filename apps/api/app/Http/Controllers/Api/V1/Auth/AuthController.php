<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\ProvisionOrganization;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Security\RecoveryCodes;
use App\Security\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const CHALLENGE_TTL_SECONDS = 300;

    public function register(Request $request, ProvisionOrganization $provision): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'organization_name' => ['required', 'string', 'min:2', 'max:160'],
        ]);

        $user = DB::transaction(function () use ($data, $provision) {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
            ]);
            $provision->handle($user, $data['organization_name']);

            return $user;
        });

        Log::channel('stack')->info('security.registered', ['user_id' => $user->id]);

        return response()->json($this->issueToken($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            Log::channel('stack')->notice('security.login_failed', ['ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        if ($user->hasMfa()) {
            $challenge = Str::random(64);
            Cache::put($this->challengeKey($challenge), $user->id, self::CHALLENGE_TTL_SECONDS);

            return response()->json(['mfa_required' => true, 'challenge' => $challenge]);
        }

        return response()->json($this->issueToken($user));
    }

    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:10'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
        ]);

        $userId = Cache::get($this->challengeKey($data['challenge']));
        $user = $userId ? User::find($userId) : null;

        if (! $user || ! $user->hasMfa()) {
            throw ValidationException::withMessages(['challenge' => 'This sign-in attempt expired. Sign in again.']);
        }

        if (! empty($data['code'])) {
            $step = Totp::verify((string) $user->mfa_secret, $data['code'], lastUsedStep: $user->mfa_last_used_step);
            if ($step === null) {
                throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
            }
            $user->forceFill(['mfa_last_used_step' => $step])->save();
        } else {
            $remaining = RecoveryCodes::consume($user->mfa_recovery_codes ?? [], (string) $data['recovery_code']);
            if ($remaining === null) {
                throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not valid or was already used.']);
            }
            $user->forceFill(['mfa_recovery_codes' => $remaining])->save();
            Log::channel('stack')->notice('security.recovery_code_used', ['user_id' => $user->id, 'remaining' => count($remaining)]);
        }

        Cache::forget($this->challengeKey($data['challenge']));

        return response()->json($this->issueToken($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberships = $user->memberships()
            ->with('organization:id,name')
            ->where('status', Membership::ACTIVE)
            ->orderBy('id')
            ->get();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mfa_enabled' => $user->hasMfa(),
                'recovery_codes_remaining' => $user->hasMfa() ? count($user->mfa_recovery_codes ?? []) : null,
            ],
            'memberships' => $memberships->map(fn (Membership $m) => [
                'organization_id' => $m->organization_id,
                'organization_name' => $m->organization->name,
                'role' => $m->role->value,
                'role_label' => $m->role->label(),
            ])->values(),
            'mfa_enrollment_required' => ! $user->hasMfa()
                && $memberships->contains(fn (Membership $m) => $m->role->requiresMfa()),
        ]);
    }

    /** @return array{token: string, expires_at: string} */
    private function issueToken(User $user): array
    {
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken('web', ['*'], $expiresAt);
        $user->forceFill(['last_login_at' => now()])->save();
        Log::channel('stack')->info('security.login', ['user_id' => $user->id]);

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt->toIso8601String()];
    }

    private function challengeKey(string $challenge): string
    {
        return 'mfa:challenge:'.hash('sha256', $challenge);
    }
}
