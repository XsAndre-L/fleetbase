<?php

namespace App\Integrations\WebAppStack;

use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class BridgeController
{
    public function __construct(protected PlatformClient $platform, protected BridgeSessions $sessions, protected WorkspaceProvisioner $workspaces)
    {
    }

    public function ready(Request $request)
    {
        $nonce = $request->query('nonce');
        abort_unless(is_string($nonce) && preg_match('/^[a-f0-9]{64}$/', $nonce), 400);
        foreach (['users', 'companies', 'company_users', 'webappstack_workspaces', 'webappstack_identities', 'webappstack_sessions', 'webappstack_login_transactions', 'webappstack_audit'] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'Delivery extension migrations are pending.');
        }
        abort_unless(config('queue.default') === 'redis' && config('cache.default') === 'redis', 503,
            'Delivery readiness requires a separate Redis queue worker and shared Redis cache.');
        Queue::connection()->size();
        $scope = $this->platform->scope();
        $scopeKey = hash('sha256', implode(':', array_values($scope)));
        if (Cache::add('was.queue.probe.' . $scopeKey, true, 10)) {
            ReadinessProbe::dispatch($scopeKey);
        }
        abort_unless(Cache::get('was.queue.ready.' . $scopeKey) === config('webappstack.fork_revision'), 503, 'Delivery queue worker is not ready.');
        return response()->json(array_merge($scope, [
            'ready' => true, 'engine' => 'fleetbase', 'extensionVersion' => '1',
            'forkRevision' => config('webappstack.fork_revision'), 'migrationsReady' => true,
            'authorizationEnforced' => true, 'queueReady' => true, 'socketsEnabled' => false,
            'nonce' => $nonce, 'proof' => hash_hmac('sha256', implode(':', [$nonce, ...array_values($scope), '1',
                config('webappstack.fork_revision'), 'true', 'true', 'true', 'false']), config('webappstack.instance_credential')),
        ]))->header('Cache-Control', 'no-store');
    }

    public function settings()
    {
        return response()->json(['enabled' => true, 'managerOrigin' => config('webappstack.manager_origin'),
            'realtimeAvailable' => false])->header('Cache-Control', 'no-store');
    }

    public function start(Request $request)
    {
        $this->sessions->browserGuard($request);
        $transaction = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $cookie = bin2hex(random_bytes(32));
        DB::table('webappstack_login_transactions')->insert([
            'id' => $transaction, 'cookie_hash' => hash('sha256', $cookie), 'state' => $state,
            'challenge' => $challenge, 'verifier_ciphertext' => Crypt::encryptString($verifier), 'expires_at' => now()->addMinutes(5),
        ]);
        $redirect = config('webappstack.manager_origin') . '/integrations/fleetbase/authorize?' . http_build_query([
            'serviceItemId' => config('webappstack.service_item_id'),
            'state' => $state, 'challenge' => $challenge, 'transaction' => $transaction,
        ], '', '&', PHP_QUERY_RFC3986);
        return response()->json(['redirectUri' => $redirect])->withCookie($this->sessions->cookie($cookie, 300, 'flow'));
    }

    public function complete(Request $request)
    {
        $this->sessions->browserGuard($request);
        $input = $request->validate(['code' => 'required|string|size:64', 'state' => 'required|string|between:32,128',
            'transaction' => 'required|string|between:32,128']);
        $cookie = $request->cookie($this->sessions->cookieName('flow'));
        abort_unless(is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie), 401, 'Start a delivery login in this browser first.');
        $flow = DB::transaction(function () use ($input, $cookie) {
            $flow = DB::table('webappstack_login_transactions')->where('id', $input['transaction'])->lockForUpdate()->first();
            abort_unless($flow && !$flow->consumed_at && strtotime($flow->expires_at) > time()
                && hash_equals($flow->cookie_hash, hash('sha256', $cookie)) && hash_equals($flow->state, $input['state']), 401);
            DB::table('webappstack_login_transactions')->where('id', $flow->id)->update(['consumed_at' => now()]);
            return $flow;
        });
        $identity = $this->platform->request('redeem', array_merge($input, ['verifier' => Crypt::decryptString($flow->verifier_ciphertext)]));
        $user = $this->workspaces->provision($identity);
        $result = DB::transaction(fn () => $this->sessions->create($identity, $user));
        return response()->json(['state' => $result['state'], 'realtimeAvailable' => false])
            ->withCookie($this->sessions->cookie($result['credential'], $result['expiresIn']))
            ->withCookie($this->sessions->cookie('', -3600, 'flow'))->header('Cache-Control', 'no-store');
    }

    public function session(Request $request)
    {
        $this->sessions->browserGuard($request);
        $session = $this->sessions->read($request);
        return response()->json(['token' => 'webappstack-cookie', 'user' => $session->user_uuid,
            'type' => 'user', 'verified' => $session->user->isVerified(), 'realtimeAvailable' => false])
            ->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request)
    {
        $this->sessions->browserGuard($request);
        $credential = $request->cookie($this->sessions->cookieName());
        if (is_string($credential)) {
            $session = DB::table('webappstack_sessions')->where('cookie_hash', hash('sha256', $credential))->first();
            if ($session) {
                $this->sessions->revoke($session);
            }
        }
        return response()->json(['ok' => true])->withCookie($this->sessions->cookie('', -3600));
    }

    public function mfaChallenge(Request $request)
    {
        $this->sessions->browserGuard($request);
        $session = $this->sessions->read($request, false);
        abort_unless($session->status === 'MFA_REQUIRED', 409);
        abort_unless(TwoFactorAuth::getTwoFaSettingsForUser($session->user)->getValue('method', 'email') === 'authenticator_app',
            409, 'A verified delivery MFA channel must be configured before continuing.');
        $token = Crypt::decryptString($session->two_fa_ciphertext);
        $clientToken = TwoFactorAuth::getClientSessionTokenFromTwoFaSession($token, $session->user->getIdentity());
        $method = TwoFactorAuth::getChallengeMethod($session->user->getIdentity(), $clientToken);
        abort_unless($method === 'authenticator_app', 409, 'A verified delivery MFA channel must be configured before continuing.');
        return response()->json(['clientToken' => $clientToken, 'method' => $method]);
    }

    public function mfaVerify(Request $request)
    {
        $this->sessions->browserGuard($request);
        $input = $request->validate(['code' => 'required|string|max:128', 'clientToken' => 'required|string|max:4096']);
        $session = $this->sessions->read($request, false);
        abort_unless($session->status === 'MFA_REQUIRED', 409);
        $token = TwoFactorAuth::verifyCode($input['code'], Crypt::decryptString($session->two_fa_ciphertext), $input['clientToken']);
        try {
            $this->sessions->read($request, false);
        } catch (\Throwable $error) {
            PersonalAccessToken::findToken($token)?->delete();
            throw $error;
        }
        $this->sessions->establishAfterMfa($session, $token);
        return response()->json(['state' => 'AUTHENTICATED'])->withCookie($this->sessions->cookie(
            $request->cookie($this->sessions->cookieName()), $session->identity['expiresIn']));
    }
}
