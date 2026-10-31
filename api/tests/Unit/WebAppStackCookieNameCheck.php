<?php

$configuration = ['webappstack.app_id' => 'app-one', 'webappstack.environment_id' => 'env-one', 'webappstack.service_item_id' => 'service-one'];
function config(string $key): mixed { global $configuration; return $configuration[$key] ?? null; }
require $argv[1];
function check(bool $condition): void { if (!$condition) { fwrite(STDERR, "Scoped cookie check failed.\n"); exit(1); } }
$bridge = (new ReflectionClass(App\Integrations\WebAppStack\BridgeSessions::class))->newInstanceWithoutConstructor();
$flow = $bridge->cookieName('flow');
$session = $bridge->cookieName();
check((bool) preg_match('/^[A-Za-z0-9_]+$/', $flow));
check((bool) preg_match('/^[A-Za-z0-9_]+$/', $session));
check($flow !== $session);
parse_str($flow . '=fixture', $parsed);
check(($parsed[$flow] ?? null) === 'fixture');
check($flow !== 'fleetbase_was_' . hash('sha256', implode(':', array_values($configuration))) . '_flow');
$original = $configuration;
foreach (array_keys($original) as $key) {
    $configuration = $original;
    $configuration[$key] .= '-different';
    check($bridge->cookieName('flow') !== $flow);
    check($bridge->cookieName() !== $session);
}
$configuration = $original;
check($bridge->cookieName('flow') === $flow);
fwrite(STDOUT, "PHP-safe full-scope cookie checks passed.\n");
