<?php

namespace App\Integrations\WebAppStack;

final class AuthorityFailure
{
    public static function context(string $operation, int $status, mixed $message): array
    {
        $category = match ($message) {
            'Delivery login is invalid, expired or already used.' => 'invalid_login',
            'Invalid login transaction.', 'Invalid service identity.' => 'invalid_input',
            'Registered service credential required.', 'Registered server credential required.' => 'service_credential',
            'Delivery workspace setup is required.' => 'setup_required',
            'App account session is no longer active or could not be verified.' => 'app_principal',
            'Delivery access has been revoked.', 'Delivery session is no longer active.' => 'revoked_session',
            'Current federated identity proof is required.', 'Current App staff membership is required.' => 'platform_principal',
            default => 'unclassified_response',
        };
        return [
            'operation' => in_array($operation, ['redeem', 'validate'], true) ? $operation : 'unknown',
            'status' => $status >= 100 && $status <= 599 ? $status : 0,
            'category' => $category,
        ];
    }
}
