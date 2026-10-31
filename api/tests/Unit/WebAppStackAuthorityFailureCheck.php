<?php

require $argv[1];

use App\Integrations\WebAppStack\AuthorityFailure;

function check(bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "Authority diagnostic check failed.\n");
        exit(1);
    }
}

$secret = 'private-code-state-token-header-value';
$unknown = AuthorityFailure::context($secret, 999, ['body' => $secret]);
check($unknown === ['operation' => 'unknown', 'status' => 0, 'category' => 'unclassified_response']);
check(!str_contains(json_encode($unknown), $secret));
check(AuthorityFailure::context('redeem', 401, 'Delivery login is invalid, expired or already used.')['category'] === 'invalid_login');
check(AuthorityFailure::context('redeem', 401, 'App account session is no longer active or could not be verified.')['category'] === 'app_principal');
check(AuthorityFailure::context('validate', 401, 'Registered service credential required.')['category'] === 'service_credential');
check(array_keys(AuthorityFailure::context('redeem', 500, $secret)) === ['operation', 'status', 'category']);
check(!str_contains(json_encode(AuthorityFailure::context('redeem', 500, $secret)), $secret));
fwrite(STDOUT, "Authority diagnostic checks passed.\n");
