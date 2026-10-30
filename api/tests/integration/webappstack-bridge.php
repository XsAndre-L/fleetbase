<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Integrations\WebAppStack\BridgeSessions;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check(config('app.env') === 'testing' && getenv('WEBAPPSTACK_BRIDGE_TEST') === 'true', 'Use only the explicitly isolated bridge fixture.');
$scope = [config('webappstack.app_id'), config('webappstack.environment_id'), config('webappstack.service_item_id')];
check(DB::connection()->getDatabaseName() === 'was_fleetbase_' . substr(hash('sha256', implode(':', $scope)), 0, 24), 'Wrong fixture database.');
check(DB::table('webappstack_sessions')->count() === 0 && DB::table('users')->count() === 0, 'Fixture must start empty.');
$identity = [
    'appId' => $scope[0], 'environmentId' => $scope[1], 'serviceItemId' => $scope[2],
    'organizationKey' => implode(':', $scope), 'subjectId' => (string) Str::uuid(),
    'principalKind' => 'APP_ACCOUNT', 'role' => 'OWNER', 'displayName' => 'Isolated owner fixture',
    'sessionId' => (string) Str::uuid(), 'expiresIn' => 28800,
];
$revoked = false;
$challenge = null;
$code = null;
$consumed = false;
$redeemCalls = 0;
Http::fake(function ($request) use (&$identity, &$revoked, &$challenge, &$code, &$consumed, &$redeemCalls) {
    if (!str_starts_with($request->url(), config('webappstack.platform_origin') . '/api/app-services/')) {
        return Http::response([], 503);
    }
    if ($revoked) {
        return Http::response([], 401);
    }
    if (str_ends_with($request->url(), '/redeem')) {
        $redeemCalls++;
        $actualChallenge = rtrim(strtr(base64_encode(hash('sha256', $request['verifier'] ?? '', true)), '+/', '-_'), '=');
        if ($consumed || $request['code'] !== $code || $actualChallenge !== $challenge) {
            return Http::response([], 401);
        }
        $consumed = true;
    }
    return Http::response($identity);
});

$cookies = [];
function requestBridge(string $method, string $path, array $body = [], ?string $origin = null): Symfony\Component\HttpFoundation\Response
{
    global $app, $cookies;
    Auth::forgetGuards();
    $request = Request::create(config('app.url') . $path, $method, [], $cookies, [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_ORIGIN' => $origin ?? config('webappstack.console_origin'), 'HTTP_X_WEBAPPSTACK_AUTH' => '1',
    ], json_encode($body));
    $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $http->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $http->terminate($request, $response);
    return $response;
}

function startFlow(): array
{
    global $challenge, $code, $consumed, $identity;
    $response = requestBridge('POST', '/integrations/webappstack/start');
    check($response->getStatusCode() === 200, 'Cannot start browser-bound login.');
    $payload = json_decode($response->getContent(), true);
    parse_str(parse_url($payload['redirectUri'], PHP_URL_QUERY), $transaction);
    check($transaction['serviceItemId'] === config('webappstack.service_item_id'), 'Handoff lost service item identity.');
    $challenge = $transaction['challenge'];
    $code = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $consumed = false;
    $identity['sessionId'] = (string) Str::uuid();
    return ['code' => $code, 'state' => $transaction['state'], 'transaction' => $transaction['transaction']];
}

$flow = startFlow();
$savedCookies = $cookies;
$cookies = [];
check(requestBridge('POST', '/integrations/webappstack/complete', $flow)->getStatusCode() === 401, 'Unsolicited callback accepted.');
$cookies = $savedCookies;
check(requestBridge('POST', '/integrations/webappstack/complete', $flow, 'https://foreign.invalid')->getStatusCode() === 403, 'Cross-origin callback accepted.');
check(requestBridge('POST', '/integrations/webappstack/complete', array_merge($flow, ['state' => str_repeat('x', 43)]))->getStatusCode() === 401, 'Wrong state accepted.');
check($redeemCalls === 0, 'Invalid browser transaction reached platform redemption.');
$response = requestBridge('POST', '/integrations/webappstack/complete', $flow);
check($response->getStatusCode() === 200, 'Owner login failed: ' . $response->getStatusCode());
check(json_decode($response->getContent(), true)['state'] === 'AUTHENTICATED', 'Owner session missing.');
check(requestBridge('POST', '/integrations/webappstack/complete', $flow)->getStatusCode() === 401, 'Browser transaction replay accepted.');
$bridge = $app->make(BridgeSessions::class);
$row = DB::table('webappstack_sessions')->where('cookie_hash', hash('sha256', $cookies[$bridge->cookieName()]))->first();
$rawToken = Crypt::decryptString($row->token_ciphertext);
$user = User::findOrFail($row->user_uuid);
check(!$user->isAdmin() && $user->password === null, 'Provisioned owner acquired global type or local password.');
$response = requestBridge('GET', '/int/v1/auth/session');
check($response->getStatusCode() === 200 && !str_contains($response->getContent(), $rawToken), 'Native token leaked or core session failed.');
check(json_decode($response->getContent(), true)['token'] === 'webappstack-cookie', 'Safe marker missing.');
$foreign = Company::create(['name' => 'Other App isolated fixture', 'currency' => 'ZAR']);
check(requestBridge('GET', '/int/v1/companies/find/' . $foreign->public_id)->getStatusCode() === 403, 'Foreign App company exposed.');
check(requestBridge('GET', '/int/v1/companies/' . $user->company_uuid)->getStatusCode() === 200, 'Own company inaccessible.');
check(requestBridge('POST', '/int/v1/socket/token')->getStatusCode() === 403, 'Socket token minted.');
check(requestBridge('POST', '/int/v1/auth/switch-organization', ['organization' => $foreign->uuid])->getStatusCode() === 403, 'Organization switched.');

$identity['role'] = 'MANAGER';
$flow = startFlow();
check(requestBridge('POST', '/integrations/webappstack/complete', $flow)->getStatusCode() === 200, 'Read-only staff login failed.');
$membership = CompanyUser::where('user_uuid', $user->uuid)->firstOrFail();
check(!$membership->hasRole('Administrator'), 'Staff retained Administrator role.');
check(Company::findOrFail($user->company_uuid)->owner_uuid === null, 'Downgraded owner reference retained.');
check(requestBridge('POST', '/int/v1/fleet-ops/orders', ['order' => []])->getStatusCode() === 403, 'Read-only staff wrote an order.');
check(requestBridge('GET', '/int/v1/companies/' . $user->company_uuid)->getStatusCode() === 200, 'Read-only staff cannot load its workspace.');
$revoked = true;
check(requestBridge('GET', '/int/v1/companies/' . $user->company_uuid)->getStatusCode() === 401, 'Existing session survived revoked authority.');
$revoked = false;
$identity['role'] = 'OWNER';

TwoFactorAuth::configureTwoFaSettings(['enabled' => true, 'enforced' => true, 'method' => 'email']);
$activeBefore = DB::table('webappstack_sessions')->where('status', 'ACTIVE')->count();
$flow = startFlow();
check(requestBridge('POST', '/integrations/webappstack/complete', $flow)->getStatusCode() === 403, 'Required unenrolled MFA was bypassed.');
check(DB::table('webappstack_sessions')->where('status', 'ACTIVE')->count() === $activeBefore, 'MFA block created a full session.');
TwoFactorAuth::configureTwoFaSettings(['enabled' => false, 'enforced' => false, 'method' => 'email']);
$enrollment = TwoFactorAuth::beginAuthenticatorEnrollment($user);
$totp = new PragmaRX\Google2FA\Google2FA();
$recovery = TwoFactorAuth::confirmAuthenticatorEnrollment($user, $totp->getCurrentOtp($enrollment['secret']));
$flow = startFlow();
$response = requestBridge('POST', '/integrations/webappstack/complete', $flow);
check($response->getStatusCode() === 200 && json_decode($response->getContent(), true)['state'] === 'MFA_REQUIRED', 'Enabled MFA did not challenge.');
check(requestBridge('POST', '/integrations/webappstack/session')->getStatusCode() === 401, 'Pending MFA reached the workspace.');
$challengeResponse = requestBridge('POST', '/integrations/webappstack/mfa/challenge');
check($challengeResponse->getStatusCode() === 200, 'Authenticator challenge failed.');
$clientToken = json_decode($challengeResponse->getContent(), true)['clientToken'];
$response = requestBridge('POST', '/integrations/webappstack/mfa/verify', ['code' => $recovery[0], 'clientToken' => $clientToken]);
check($response->getStatusCode() === 200, 'Valid recovery factor was not accepted.');
check(requestBridge('POST', '/integrations/webappstack/session')->getStatusCode() === 200, 'Verified factor did not establish session.');
$revoked = true;
check(requestBridge('POST', '/integrations/webappstack/session')->getStatusCode() === 401, 'Verified existing MFA session survived revocation.');

echo "PASS: isolated MySQL browser-bound handoff, core console auth, safe token marker, company isolation, staff downgrade, revocation and MFA.\n";
echo "Platform authority is an isolated HTTP facade fixture; real platform/browser integration remains a separate acceptance gate.\n";
