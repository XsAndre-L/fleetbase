<?php

namespace App\Integrations\WebAppStack;

use Fleetbase\Models\User;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

class BridgeSessions
{
    public function __construct(protected PlatformClient $platform)
    {
    }

    public function cookieName(string $suffix = 'session'): string
    {
        return 'fleetbase.was.' . config('webappstack.service_item_id') . '.' . $suffix;
    }

    public function cookie(string $credential, int $seconds, string $suffix = 'session'): Cookie
    {
        return Cookie::create($this->cookieName($suffix), $credential, time() + $seconds, '/', null,
            str_starts_with(config('webappstack.console_origin'), 'https://'), true, false, Cookie::SAMESITE_LAX);
    }

    public function browserGuard(Request $request): void
    {
        abort_unless($request->header('Origin') === config('webappstack.console_origin')
            && $request->header('X-WebAppStack-Auth') === '1', 403, 'Start this action from the configured delivery console.');
    }

    public function read(Request $request, bool $requireFull = true): object
    {
        $credential = $request->cookie($this->cookieName());
        abort_unless(is_string($credential) && preg_match('/^[a-f0-9]{64}$/', $credential), 401);
        $session = DB::table('webappstack_sessions')->where('cookie_hash', hash('sha256', $credential))->first();
        abort_unless($session && !$session->revoked_at && strtotime($session->expires_at) > time()
            && (!$requireFull || $session->status === 'ACTIVE'), 401, 'Delivery session expired.');
        try {
            $identity = $this->platform->request('validate', ['sessionId' => $session->platform_session_id]);
            $mapping = DB::table('webappstack_identities')->where('identity_key', $session->identity_key)->first();
            abort_unless($mapping && $mapping->user_uuid === $session->user_uuid
                && $mapping->subject_id === $identity['subjectId'] && $mapping->principal_kind === $identity['principalKind']
                && $mapping->access_role === $identity['role'], 401);
            $workspace = DB::table('webappstack_workspaces')->where('scope_key', $mapping->scope_key)->first();
            abort_unless($workspace && $workspace->organization_key === $identity['organizationKey'], 401);
            $user = User::find($session->user_uuid);
            abort_unless($user && !$user->isAdmin() && $user->status === 'active'
                && $user->company_uuid === $workspace->company_uuid, 401);
            $session->identity = $identity;
            $session->company_uuid = $workspace->company_uuid;
            $session->user = $user;
            return $session;
        } catch (\Throwable $error) {
            $this->revoke($session);
            abort(401, 'Delivery access is unavailable or revoked.');
        }
    }

    public function create(array $identity, User $user): array
    {
        $current = $this->platform->request('validate', ['sessionId' => $identity['sessionId']]);
        foreach (['subjectId', 'principalKind', 'organizationKey', 'role'] as $key) {
            abort_unless($current[$key] === $identity[$key], 401, 'Delivery authority changed during login.');
        }
        abort_if(TwoFactorAuth::shouldEnforce($user), 403, 'Required two-factor enrollment must be completed before delivery access.');
        $credential = bin2hex(random_bytes(32));
        $pending = TwoFactorAuth::isEnabled($user);
        $token = $pending ? null : $user->createToken('webappstack:' . $identity['sessionId']);
        $twoFa = $pending ? TwoFactorAuth::start($user) : null;
        DB::table('webappstack_sessions')->insert([
            'cookie_hash' => hash('sha256', $credential), 'platform_session_id' => $identity['sessionId'],
            'identity_key' => hash('sha256', hash('sha256', $identity['organizationKey']) . ':' . $identity['principalKind'] . ':' . $identity['subjectId']),
            'user_uuid' => $user->uuid, 'status' => $pending ? 'MFA_REQUIRED' : 'ACTIVE',
            'token_ciphertext' => $token ? Crypt::encryptString($token->plainTextToken) : null,
            'personal_token_id' => $token?->accessToken->id,
            'two_fa_ciphertext' => $twoFa ? Crypt::encryptString($twoFa) : null,
            'expires_at' => now()->addSeconds(min($identity['expiresIn'], $pending ? 600 : 28800)),
        ]);
        return ['credential' => $credential, 'state' => $pending ? 'MFA_REQUIRED' : 'AUTHENTICATED',
            'expiresIn' => $pending ? 600 : $identity['expiresIn']];
    }

    public function revoke(object $session): void
    {
        DB::table('webappstack_sessions')->where('cookie_hash', $session->cookie_hash)->update(['revoked_at' => now()]);
        if ($session->personal_token_id) {
            PersonalAccessToken::where('id', $session->personal_token_id)->delete();
        }
    }

    public function establishAfterMfa(object $session, string $token): void
    {
        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || $accessToken->tokenable_id !== $session->user_uuid) {
            $accessToken?->delete();
            abort(401);
        }
        try {
            $identity = $this->platform->request('validate', ['sessionId' => $session->platform_session_id]);
            foreach (['subjectId', 'principalKind', 'organizationKey', 'role'] as $key) {
                abort_unless($identity[$key] === $session->identity[$key], 401);
            }
            $changed = DB::table('webappstack_sessions')->where('cookie_hash', $session->cookie_hash)
                ->where('status', 'MFA_REQUIRED')->whereNull('revoked_at')->where('expires_at', '>', now())->update([
                    'status' => 'ACTIVE', 'token_ciphertext' => Crypt::encryptString($token),
                    'personal_token_id' => $accessToken->id, 'two_fa_ciphertext' => null,
                    'expires_at' => now()->addSeconds(min($identity['expiresIn'], 28800)),
                ]);
            abort_unless($changed === 1, 401, 'Delivery session was revoked during verification.');
        } catch (\Throwable $error) {
            $accessToken->delete();
            throw $error;
        }
    }
}
