param(
    [Parameter(Mandatory)][ValidateSet('Start', 'Stop', 'Status')][string]$Action,
    [Parameter(Mandatory)][string]$EnvFile
)

$ErrorActionPreference = 'Stop'
$fleetbaseRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$privateEnvironment = (Resolve-Path -LiteralPath $EnvFile).Path
$settings = @{}
foreach ($line in Get-Content -LiteralPath $privateEnvironment) {
    if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') {
        $settings[$Matches[1]] = $Matches[2]
    }
}
foreach ($key in @('WEBAPPSTACK_APP_ID', 'WEBAPPSTACK_ENVIRONMENT_ID', 'WEBAPPSTACK_SERVICE_ITEM_ID')) {
    $parsedScopeId = [Guid]::Empty
    if (-not [Guid]::TryParse($settings[$key], [ref]$parsedScopeId)) { throw "Invalid $key." }
}
$serviceId = $settings['WEBAPPSTACK_SERVICE_ITEM_ID']
$scope = @($settings['WEBAPPSTACK_APP_ID'], $settings['WEBAPPSTACK_ENVIRONMENT_ID'], $serviceId) -join ':'
$scopeHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($scope))).ToLowerInvariant()
if ($settings['DB_DATABASE'] -ne "was_fleetbase_$($scopeHash.Substring(0, 24))") { throw 'Database does not match this isolated service scope.' }
$project = 'was-fleetbase-' + $serviceId.Replace('-', '').Substring(0, 20)
$compose = @('compose', '--project-name', $project, '--env-file', $privateEnvironment, '-f', (Join-Path $fleetbaseRoot 'compose.webappstack.yml'))

function Invoke-OwnedCompose([string[]]$Arguments) {
    & docker @compose @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'The owned delivery Compose operation failed. Private environment values were not printed.' }
}

if ($Action -eq 'Stop') {
    Invoke-OwnedCompose @('stop')
    Write-Output "Stopped $project; database/documents/volumes retained."
    exit
}
if ($Action -eq 'Status') {
    Invoke-OwnedCompose @('ps', '--all')
    exit
}

$revision = $settings['WEBAPPSTACK_FORK_REVISION']
if ($revision -notmatch '^[a-f0-9]{40}$') { throw 'A committed fork revision is required.' }
foreach ($key in @('WEBAPPSTACK_API_IMAGE', 'WEBAPPSTACK_CONSOLE_IMAGE')) {
    $image = $settings[$key]
    if ($image -notmatch '^[a-z0-9][a-z0-9._/:@-]*$') { throw 'Invalid immutable delivery image reference.' }
    $imageRevision = & docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' $image
    if ($LASTEXITCODE -ne 0 -or $imageRevision -ne $revision) { throw 'Delivery image does not match the selected fork revision.' }
}
foreach ($key in @('WEBAPPSTACK_INSTANCE_CREDENTIAL', 'DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'REDIS_PASSWORD')) {
    if ($settings[$key].Length -lt 32) { throw "A generated non-default $key is required." }
}
$consoleConfiguration = Get-Content -LiteralPath $settings['WEBAPPSTACK_CONSOLE_CONFIG'] -Raw | ConvertFrom-Json -AsHashtable
if ($consoleConfiguration['API_HOST'] -ne $settings['WEBAPPSTACK_API_ORIGIN'] -or
    $consoleConfiguration['API_NAMESPACE'] -ne 'int/v1' -or
    @($consoleConfiguration.Keys | Where-Object { $_ -notin @('API_HOST', 'API_NAMESPACE') }).Count -gt 0) {
    throw 'Console configuration must contain only its exact public API binding.'
}
Invoke-OwnedCompose @('config', '--quiet')
Invoke-OwnedCompose @('up', '-d', '--wait', '--wait-timeout', '600', 'database', 'cache')
Invoke-OwnedCompose @('run', '--rm', '--no-deps', 'application', 'php', 'artisan', 'webappstack:prepare')
Invoke-OwnedCompose @('up', '-d', '--wait', '--wait-timeout', '240', 'application', 'queue', 'scheduler', 'console')

$proofReady = $false
for ($attempt = 0; $attempt -lt 24; $attempt++) {
    $nonce = [Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant()
    try {
        $ready = Invoke-RestMethod -Uri ($settings['WEBAPPSTACK_API_ORIGIN'] + '/integrations/webappstack/ready?nonce=' + $nonce) -TimeoutSec 5
        $payload = $nonce + ':' + $scope + ':1:' + $revision + ':true:true:true:false'
        $hmac = [System.Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($settings['WEBAPPSTACK_INSTANCE_CREDENTIAL']))
        try { $expected = $hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($payload)) } finally { $hmac.Dispose() }
        $actual = [Convert]::FromHexString($ready.proof)
        $proofReady = ($ready.ready -eq $true -and $ready.nonce -eq $nonce -and $ready.appId -eq $settings['WEBAPPSTACK_APP_ID'] -and
            $ready.environmentId -eq $settings['WEBAPPSTACK_ENVIRONMENT_ID'] -and $ready.serviceItemId -eq $serviceId -and
            $ready.engine -eq 'fleetbase' -and $ready.extensionVersion -eq '1' -and $ready.forkRevision -eq $revision -and
            $ready.migrationsReady -eq $true -and $ready.authorizationEnforced -eq $true -and $ready.queueReady -eq $true -and $ready.socketsEnabled -eq $false -and
            [System.Security.Cryptography.CryptographicOperations]::FixedTimeEquals($expected, $actual))
        if ($proofReady) { break }
    } catch { }
    Start-Sleep -Seconds 2
}
if (-not $proofReady) { throw 'Delivery identity, migrations and same-revision queue readiness were not established.' }
$containerIds = & docker @compose ps -q
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect owned delivery containers.' }
foreach ($containerId in $containerIds) {
    $policy = & docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' $containerId
    if ($policy -ne 'no') { throw 'An owned container has an automatic restart policy.' }
}
$stateRoot = Join-Path $fleetbaseRoot ('build/.state/webappstack/' + $serviceId)
[System.IO.Directory]::CreateDirectory($stateRoot) | Out-Null
@{
    project = $project; appId = $settings['WEBAPPSTACK_APP_ID']; environmentId = $settings['WEBAPPSTACK_ENVIRONMENT_ID']
    serviceItemId = $serviceId; forkRevision = $revision; containers = @($containerIds)
    apiOrigin = $settings['WEBAPPSTACK_API_ORIGIN']; consoleOrigin = $settings['WEBAPPSTACK_CONSOLE_ORIGIN']
    apiImage = $settings['WEBAPPSTACK_API_IMAGE']; consoleImage = $settings['WEBAPPSTACK_CONSOLE_IMAGE']
    environmentFile = $privateEnvironment; startedAt = [DateTime]::UtcNow.ToString('o'); realtimeAvailable = $false
} | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $stateRoot 'receipt.json')
Write-Output "Ready: $project at $($settings['WEBAPPSTACK_CONSOLE_ORIGIN']). Identity and queue proof verified; owner login still requires its explicit platform grant."
