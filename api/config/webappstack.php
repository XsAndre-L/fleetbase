<?php

return [
    'enabled' => env('WEBAPPSTACK_ENABLED', false),
    'platform_origin' => env('WEBAPPSTACK_PLATFORM_API_ORIGIN'),
    'app_id' => env('WEBAPPSTACK_APP_ID'),
    'environment_id' => env('WEBAPPSTACK_ENVIRONMENT_ID'),
    'service_item_id' => env('WEBAPPSTACK_SERVICE_ITEM_ID'),
    'instance_credential' => env('WEBAPPSTACK_INSTANCE_CREDENTIAL'),
    'console_origin' => env('WEBAPPSTACK_CONSOLE_ORIGIN'),
    'manager_origin' => env('WEBAPPSTACK_MANAGER_ORIGIN'),
    'workspace_name' => env('WEBAPPSTACK_WORKSPACE_NAME', 'App delivery workspace'),
    'fork_revision' => env('WEBAPPSTACK_FORK_REVISION', 'unverified'),
];
