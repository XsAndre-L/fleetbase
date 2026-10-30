<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareWebAppStack extends Command
{
    protected $signature = 'webappstack:prepare';
    protected $description = 'Prepare the explicitly scoped App-linked delivery database without creating a default administrator.';

    public function handle(): int
    {
        $scope = implode(':', [config('webappstack.app_id'), config('webappstack.environment_id'), config('webappstack.service_item_id')]);
        $expected = 'was_fleetbase_' . substr(hash('sha256', $scope), 0, 24);
        if (!config('webappstack.enabled') || DB::connection()->getDatabaseName() !== $expected) {
            $this->error('A matching isolated App-linked delivery database is required.');
            return self::FAILURE;
        }
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if (!DB::table('roles')->where('name', 'Administrator')->whereNull('company_uuid')->exists()) {
            if ($this->call('fleetbase:create-permissions') !== self::SUCCESS) {
                return self::FAILURE;
            }
        }
        $this->info('App-linked delivery schema and permissions ready. No default administrator was created.');
        return self::SUCCESS;
    }
}
