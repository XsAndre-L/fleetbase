<?php

namespace App\Integrations\WebAppStack;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\JsonResponse;

class EnforceBridge
{
    public function __construct(protected BridgeSessions $sessions)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!config('webappstack.enabled') || $request->isMethod('OPTIONS')
            || $request->is('health', 'integrations/webappstack/*')
            || ($request->isMethod('GET') && $request->is('int/v1/settings/branding'))) {
            return $next($request);
        }
        abort_if($request->is('*/socket/*', 'socket/*', 'oauth/*', '.well-known/*', 'int/v1/admin*',
            'int/v1/installer*', 'int/v1/onboard*', 'v1/*', 'fleet-ops/v1/*')
            || preg_match('#(?:api-key|api-credential|webhook-endpoint|oauth-server|platform-api|/settings)(?:/|$)#', $request->path()),
            403, 'This surface is unavailable in the App-linked delivery instance.');
        if (!$request->isMethodSafe()) {
            $this->sessions->browserGuard($request);
        }
        $session = $this->sessions->read($request);
        abort_unless($session->identity['role'] === 'OWNER' || $request->isMethodSafe(), 403,
            'Delivery staff access is read-only. Operational changes use the authorized App Manager.');
        $path = $request->path();
        if ($request->is('int/v1/companies', 'int/v1/companies/*')) {
            $allowedCompanyPaths = [
                'int/v1/companies/' . $session->company_uuid,
                'int/v1/companies/' . $session->user->company->public_id,
                'int/v1/companies/find/' . $session->user->company->public_id,
            ];
            abort_unless(in_array($path, $allowedCompanyPaths, true), 403, 'Cross-workspace access is denied.');
        }
        abort_if(preg_match('#(?:switch-organization|join-organization|create-organization|change-password|change-email|impersonat)#', $path),
            403, 'Identity and workspace access are managed by WebAppStack.');
        if (!$request->isMethodSafe()) {
            $operational = preg_match('#^(?:int/v1/fleet-ops|fleet-ops/int/v1|fleet-ops/v1|int/v1/files)(?:/|$)#', $path);
            abort_unless($operational, 403, 'This action is managed by WebAppStack.');
            if ($session->identity['role'] !== 'OWNER') {
                abort_if(preg_match('#(?:/settings|/config|/integrations|/credentials|/service-rates|/service-areas)(?:/|$)#', $path),
                    403, 'Delivery owner access is required.');
            }
        }
        $this->assertCompanyInputs($request->all(), [$session->company_uuid, $session->user->company->public_id]);
        $token = Crypt::decryptString($session->token_ciphertext);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $request->headers->remove('Access-Console-Sandbox');
        $request->headers->remove('Access-Console-Sandbox-Key');
        $request->attributes->set('webappstack_session', $session);
        $response = $next($request);
        if ($response instanceof JsonResponse) {
            $response->setData($this->hideServerToken($response->getData(true), $token));
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->remove('ETag');
        return $response;
    }

    private function assertCompanyInputs(array $input, array $allowed): void
    {
        foreach ($input as $key => $value) {
            if (in_array($key, ['company', 'company_uuid', 'organization', 'organization_uuid'], true) && $value !== null) {
                abort_unless(is_string($value) && in_array($value, $allowed, true), 403, 'Cross-workspace access is denied.');
            }
            if (is_array($value)) {
                $this->assertCompanyInputs($value, $allowed);
            }
        }
    }

    private function hideServerToken(mixed $value, string $token): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($entry) => $this->hideServerToken($entry, $token), $value);
        }
        return $value === $token ? 'webappstack-cookie' : $value;
    }
}
