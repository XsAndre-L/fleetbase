<?php

namespace App\Integrations\WebAppStack;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ReadinessProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $scopeKey)
    {
    }

    public function handle(): void
    {
        Cache::put('was.queue.ready.' . $this->scopeKey, config('webappstack.fork_revision'), 60);
    }
}
