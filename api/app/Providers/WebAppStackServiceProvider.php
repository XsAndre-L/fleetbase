<?php

namespace App\Providers;

use App\Integrations\WebAppStack\BridgeController;
use App\Integrations\WebAppStack\EnforceBridge;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class WebAppStackServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!config('webappstack.enabled')) {
            return;
        }
        foreach (['app_id', 'environment_id', 'service_item_id'] as $key) {
            if (!Str::isUuid(config('webappstack.' . $key))) {
                throw new \RuntimeException('App-linked delivery scope is not configured.');
            }
        }
        foreach (['platform_origin', 'console_origin', 'manager_origin'] as $key) {
            $value = config('webappstack.' . $key);
            $url = is_string($value) ? parse_url($value) : false;
            if (!$url || !in_array($url['scheme'] ?? null, ['http', 'https'], true) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment']) || isset($url['path'])
                || (($url['scheme'] ?? null) === 'http' && !in_array($url['host'] ?? null, ['localhost', '127.0.0.1', 'host.docker.internal', '[::1]'], true))) {
                throw new \RuntimeException('App-linked delivery origins must be canonical trusted origins.');
            }
        }
        if (strlen(config('webappstack.instance_credential', '')) < 32 || !config('app.key')) {
            throw new \RuntimeException('App-linked delivery requires generated instance and encryption secrets.');
        }
        if (!preg_match('/^[a-f0-9]{40}$/', config('webappstack.fork_revision', ''))) {
            throw new \RuntimeException('App-linked delivery requires an exact committed fork revision.');
        }
        $apiOrigin = parse_url(config('app.url'));
        $consoleOrigin = parse_url(config('webappstack.console_origin'));
        if (!$apiOrigin || ($apiOrigin['host'] ?? null) !== ($consoleOrigin['host'] ?? null)
            || ($apiOrigin['scheme'] ?? null) !== ($consoleOrigin['scheme'] ?? null)) {
            throw new \RuntimeException('This App-linked adapter requires API and console on the same site and scheme.');
        }
        config(['cors.allowed_origins' => [config('webappstack.console_origin')], 'cors.allowed_origins_patterns' => [],
            'cors.supports_credentials' => true, 'responsecache.enabled' => false]);
        $this->app->make(Kernel::class)->pushMiddleware(EnforceBridge::class);
        Route::prefix('integrations/webappstack')->middleware(['api', 'throttle:120,1'])->group(function () {
            Route::get('ready', [BridgeController::class, 'ready']);
            Route::get('settings', [BridgeController::class, 'settings']);
            Route::post('start', [BridgeController::class, 'start']);
            Route::post('complete', [BridgeController::class, 'complete']);
            Route::post('session', [BridgeController::class, 'session']);
            Route::post('logout', [BridgeController::class, 'logout']);
            Route::post('mfa/challenge', [BridgeController::class, 'mfaChallenge']);
            Route::post('mfa/verify', [BridgeController::class, 'mfaVerify'])->middleware('throttle:10,1');
        });
    }
}
