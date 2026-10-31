<?php

namespace App\Integrations\WebAppStack;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class PlatformClient
{
    public function scope(): array
    {
        return [
            'appId' => config('webappstack.app_id'),
            'environmentId' => config('webappstack.environment_id'),
            'serviceItemId' => config('webappstack.service_item_id'),
        ];
    }

    public function request(string $operation, array $body): array
    {
        abort_unless(in_array($operation, ['redeem', 'validate'], true), 503);
        try {
            $response = Http::withToken(config('webappstack.instance_credential'))
                ->acceptJson()->asJson()->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->post(rtrim(config('webappstack.platform_origin'), '/') . '/api/app-services/' . $operation, array_merge($body, $this->scope()));
        } catch (ConnectionException $error) {
            Log::warning('webappstack.authority_connection_failed', [
                'operation' => $operation,
                'category' => 'connection_or_timeout',
                'connectionClass' => ConnectionException::class,
            ]);
            throw $error;
        }
        if (!$response->successful()) {
            Log::warning('webappstack.authority_rejected', AuthorityFailure::context(
                $operation, $response->status(), $response->json('message')
            ));
            abort(401, 'Delivery access is unavailable or revoked.');
        }
        $identity = $response->json();
        abort_unless(is_array($identity), 401);
        foreach ($this->scope() as $key => $value) {
            abort_unless(($identity[$key] ?? null) === $value, 401, 'Delivery scope mismatch.');
        }
        $expectedOrganization = implode(':', array_values($this->scope()));
        abort_unless(($identity['organizationKey'] ?? null) === $expectedOrganization
            && in_array($identity['role'] ?? null, ['OWNER', 'MANAGER'], true)
            && in_array($identity['principalKind'] ?? null, ['APP_ACCOUNT', 'PLATFORM_USER'], true)
            && is_string($identity['sessionId'] ?? null) && \Illuminate\Support\Str::isUuid($identity['sessionId'])
            && is_string($identity['subjectId'] ?? null) && \Illuminate\Support\Str::isUuid($identity['subjectId'])
            && is_int($identity['expiresIn'] ?? null) && $identity['expiresIn'] > 0 && $identity['expiresIn'] <= 28800,
            401, 'Delivery identity mismatch.');
        return $identity;
    }
}
