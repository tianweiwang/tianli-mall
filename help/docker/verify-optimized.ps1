[CmdletBinding()]
param(
    [string]$ComposeFile = "help/docker/docker-compose.optimized.yml",
    [string]$EnvFile = "help/docker/.env.runtime",
    [int]$ExpectedProducts = 4,
    [int]$ExpectedUsers = 2,
    [int]$ExpectedUploads = 174
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "../..")).Path
$composePath = if ([IO.Path]::IsPathRooted($ComposeFile)) {
    $ComposeFile
} else {
    Join-Path $repoRoot $ComposeFile
}
$envPath = if ([IO.Path]::IsPathRooted($EnvFile)) {
    $EnvFile
} else {
    Join-Path $repoRoot $EnvFile
}

$checks = [Collections.Generic.List[object]]::new()

function Add-Check {
    param(
        [string]$Name,
        [bool]$Passed,
        [string]$Detail
    )

    $script:checks.Add([pscustomobject]@{
        Name = $Name
        Passed = $Passed
        Detail = $Detail
    })
}

function Invoke-Docker {
    param([string[]]$Arguments)

    $output = & docker @Arguments 2>&1
    [pscustomobject]@{
        ExitCode = $LASTEXITCODE
        Output = ($output -join "`n").Trim()
    }
}

function Get-HttpMeasurement {
    param(
        [string]$Url,
        [int]$TimeoutSeconds = 25,
        [string[]]$Headers = @()
    )

    $arguments = @("-sS", "-o", "NUL", "--max-time", $TimeoutSeconds)
    foreach ($header in $Headers) {
        $arguments += @("-H", $header)
    }
    $arguments += @("-w", "%{http_code}|%{time_total}", $Url)
    $raw = (& curl.exe @arguments 2>$null | Select-Object -Last 1)
    if (-not $raw -or $raw -notmatch "^(\d{3})\|([0-9.]+)$") {
        return [pscustomobject]@{ Status = 0; Seconds = [double]::PositiveInfinity }
    }

    [pscustomobject]@{
        Status = [int]$Matches[1]
        Seconds = [double]::Parse($Matches[2], [Globalization.CultureInfo]::InvariantCulture)
    }
}

if (-not (Test-Path -LiteralPath $composePath)) {
    throw "Compose file not found: $composePath"
}

$composeArguments = @("compose")
if (Test-Path -LiteralPath $envPath) {
    $composeArguments += @("--env-file", $envPath)
}
$composeArguments += @("-f", $composePath)

$servicesResult = Invoke-Docker -Arguments ($composeArguments + @("ps", "--services", "--status", "running"))
$runningServices = @($servicesResult.Output -split "`r?`n" | Where-Object { $_ })
$expectedServices = @("mysql", "redis", "phpfpm", "nginx")
$missingServices = @($expectedServices | Where-Object { $_ -notin $runningServices })
$servicesDetail = if ($missingServices.Count) { "missing: $($missingServices -join ', ')" } else { "running: $($runningServices -join ', ')" }
Add-Check "required services are running" ($servicesResult.ExitCode -eq 0 -and $missingServices.Count -eq 0) $servicesDetail

$containerNames = @("crmeb_mysql", "crmeb_redis", "crmeb_php", "crmeb_nginx")
$healthByContainer = @{}
$healthDeadline = [DateTime]::UtcNow.AddSeconds(60)
do {
    $hasStartingContainer = $false
    foreach ($container in $containerNames) {
        $healthResult = Invoke-Docker -Arguments @("inspect", "--format", "{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}", $container)
        $healthByContainer[$container] = if ($healthResult.ExitCode -eq 0) { $healthResult.Output } else { "inspect-error" }
        if ($healthByContainer[$container] -eq "starting") {
            $hasStartingContainer = $true
        }
    }

    if ($hasStartingContainer -and [DateTime]::UtcNow -lt $healthDeadline) {
        Start-Sleep -Seconds 2
    }
} while ($hasStartingContainer -and [DateTime]::UtcNow -lt $healthDeadline)

$unhealthy = [Collections.Generic.List[string]]::new()
foreach ($container in $containerNames) {
    if ($healthByContainer[$container] -ne "healthy") {
        $unhealthy.Add("$container=$($healthByContainer[$container])")
    }
}
$healthDetail = if ($unhealthy.Count) { $unhealthy -join "; " } else { "mysql, redis, php and nginx are healthy" }
Add-Check "all containers report healthy" ($unhealthy.Count -eq 0) $healthDetail

$redisLogsResult = Invoke-Docker -Arguments @("logs", "crmeb_redis")
$redisPermissionError = $redisLogsResult.Output -match "(?i)permission denied"
Add-Check "Redis runtime paths are writable" (
    $redisLogsResult.ExitCode -eq 0 -and -not $redisPermissionError
) "Redis startup log has no permission errors"

$redisLogLevelResult = Invoke-Docker -Arguments @(
    "exec", "crmeb_redis", "sh", "-lc",
    'test "$(redis-cli -a "$REDIS_PASSWORD" --no-auth-warning --raw CONFIG GET loglevel | tail -n 1)" = notice'
)
Add-Check "Redis verbose logging is disabled" ($redisLogLevelResult.ExitCode -eq 0) "loglevel=notice"

$supervisorResult = Invoke-Docker -Arguments @("exec", "crmeb_php", "supervisorctl", "status")
$missingProcesses = [Collections.Generic.List[string]]::new()
foreach ($process in @("php-fpm", "queue", "timer", "workerman")) {
    if ($supervisorResult.Output -notmatch "(?m)^$([regex]::Escape($process))\s+RUNNING\b") {
        $missingProcesses.Add($process)
    }
}
$supervisorDetail = if ($missingProcesses.Count) { "not running: $($missingProcesses -join ', ')" } else { "php-fpm, queue, timer and workerman are running" }
Add-Check "all supervised processes are running" ($supervisorResult.ExitCode -eq 0 -and $missingProcesses.Count -eq 0) $supervisorDetail

$opcacheResult = Invoke-Docker -Arguments @(
    "exec", "crmeb_php", "php", "-r",
    'exit(extension_loaded("Zend OPcache") && (bool) ini_get("opcache.enable") ? 0 : 1);'
)
Add-Check "OPcache is enabled" ($opcacheResult.ExitCode -eq 0) "php-fpm runtime must load Zend OPcache"

$debugResult = Invoke-Docker -Arguments @(
    "exec", "crmeb_php", "php", "-r",
    '$config=parse_ini_file("/var/www/.env", true, INI_SCANNER_TYPED); $app=getenv("APP_DEBUG"); $db=$config["DATABASE"]["DEBUG"] ?? true; exit(($app === "false" || $app === "0") && !$db ? 0 : 1);'
)
Add-Check "application and database debug are disabled" ($debugResult.ExitCode -eq 0) "APP_DEBUG and DATABASE.DEBUG must both be false"

$mountResult = Invoke-Docker -Arguments @("inspect", "--format", "{{json .Mounts}}", "crmeb_php")
$sourceBindMounts = @()
if ($mountResult.ExitCode -eq 0 -and $mountResult.Output) {
    $mounts = @($mountResult.Output | ConvertFrom-Json)
    $sourceBindMounts = @($mounts | Where-Object {
        $_.Type -eq "bind" -and ($_.Destination -eq "/var/www" -or $_.Destination -like "/var/www/vendor*")
    })
}

$nginxMountResult = Invoke-Docker -Arguments @("inspect", "--format", "{{json .Mounts}}", "crmeb_nginx")
$nginxSourceBindMounts = @()
if ($nginxMountResult.ExitCode -eq 0 -and $nginxMountResult.Output) {
    $nginxMounts = @($nginxMountResult.Output | ConvertFrom-Json)
    $nginxSourceBindMounts = @($nginxMounts | Where-Object {
        $_.Type -eq "bind" -and
        ($_.Destination -eq "/var/www/public" -or $_.Destination -like "/var/www/public/*") -and
        $_.Destination -notlike "/var/www/public/uploads*"
    })
}

$sourceBindCount = $sourceBindMounts.Count + $nginxSourceBindMounts.Count
$mountDetail = if ($sourceBindCount) {
    "host source bind found in PHP or Nginx"
} else {
    "PHP and Nginx code are image-backed"
}
Add-Check "application source is image-backed" (
    $mountResult.ExitCode -eq 0 -and
    $nginxMountResult.ExitCode -eq 0 -and
    $sourceBindCount -eq 0
) $mountDetail

$publishedInternalPorts = [Collections.Generic.List[string]]::new()
foreach ($container in @("crmeb_mysql", "crmeb_redis", "crmeb_php")) {
    $portsResult = Invoke-Docker -Arguments @("inspect", "--format", "{{json .HostConfig.PortBindings}}", $container)
    if ($portsResult.ExitCode -eq 0 -and $portsResult.Output -match '"HostPort":"\d+"') {
        $publishedInternalPorts.Add($container)
    }
}
$portsDetail = if ($publishedInternalPorts.Count) { "published: $($publishedInternalPorts -join ', ')" } else { "mysql, redis and php are internal-only" }
Add-Check "internal services have no published host ports" ($publishedInternalPorts.Count -eq 0) $portsDetail

$listenerResult = Invoke-Docker -Arguments @(
    "exec", "crmeb_php", "php", "-d", "open_basedir=", "/usr/local/bin/runtime-healthcheck.php"
)
$listenerDetail = if ($listenerResult.ExitCode) { "missing listeners: $($listenerResult.Output)" } else { "ports 9000, 40001, 40002 and 40003" }
Add-Check "PHP and Workerman listeners are reachable" ($listenerResult.ExitCode -eq 0) $listenerDetail

$uploadWritableResult = Invoke-Docker -Arguments @(
    "exec", "--user", "www-data", "crmeb_php", "sh", "-lc",
    "test -w /var/www/public/uploads"
)
Add-Check "uploads are writable by the PHP runtime user" ($uploadWritableResult.ExitCode -eq 0) "www-data can write the mounted uploads directory"

$installerLockResult = Invoke-Docker -Arguments @(
    "exec", "crmeb_php", "test", "-f", "/var/www/public/install.lock"
)
Add-Check "installer lock is present in the PHP image" ($installerLockResult.ExitCode -eq 0) "public/install.lock exists"

$null = Get-HttpMeasurement -Url "http://127.0.0.1:8011/"
$measurements = 1..3 | ForEach-Object { Get-HttpMeasurement -Url "http://127.0.0.1:8011/" }
$slowMeasurements = @($measurements | Where-Object { $_.Status -ne 200 -or $_.Seconds -ge 2 })
$timings = ($measurements | ForEach-Object { "HTTP $($_.Status) in $([Math]::Round($_.Seconds, 3))s" }) -join "; "
Add-Check "three warm storefront requests finish under two seconds" ($slowMeasurements.Count -eq 0) $timings

$installerMeasurement = Get-HttpMeasurement -Url "http://127.0.0.1:8011/install/index.php"
Add-Check "web installer is blocked" ($installerMeasurement.Status -eq 404) "HTTP $($installerMeasurement.Status)"

$webSocketHeaders = @(
    "Connection: Upgrade",
    "Upgrade: websocket",
    "Sec-WebSocket-Version: 13",
    "Sec-WebSocket-Key: SGVsbG9Xb3JsZDEyMzQ1Ng=="
)
foreach ($path in @("notice", "msg")) {
    $measurement = Get-HttpMeasurement -Url "http://127.0.0.1:8011/$path" -TimeoutSeconds 3 -Headers $webSocketHeaders
    Add-Check "$path proxy reaches Workerman" ($measurement.Status -ne 0 -and $measurement.Status -ne 502) "HTTP $($measurement.Status)"
}

$dataResult = Invoke-Docker -Arguments @(
    "exec", "crmeb_mysql", "sh", "-lc",
    'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" crmeb -e "SELECT COUNT(*) FROM eb_store_product; SELECT COUNT(*) FROM eb_user;"'
)
$counts = @($dataResult.Output -split "`r?`n" | Where-Object { $_ -match "^\d+$" } | ForEach-Object { [int]$_ })
$actualProducts = if ($counts.Count -ge 1) { $counts[0] } else { -1 }
$actualUsers = if ($counts.Count -ge 2) { $counts[1] } else { -1 }
Add-Check "demo product count is preserved" ($actualProducts -eq $ExpectedProducts) "expected=$ExpectedProducts actual=$actualProducts"
Add-Check "demo user count is preserved" ($actualUsers -eq $ExpectedUsers) "expected=$ExpectedUsers actual=$actualUsers"

$uploadPath = Join-Path $repoRoot "crmeb/public/uploads"
$actualUploads = @(Get-ChildItem -LiteralPath $uploadPath -Recurse -File -ErrorAction Stop).Count
Add-Check "upload file count is preserved" ($actualUploads -eq $ExpectedUploads) "expected=$ExpectedUploads actual=$actualUploads"

$checks | ForEach-Object {
    $marker = if ($_.Passed) { "PASS" } else { "FAIL" }
    "[$marker] $($_.Name): $($_.Detail)"
}

$failedChecks = @($checks | Where-Object { -not $_.Passed })
if ($failedChecks.Count) {
    "Verification failed: $($failedChecks.Count) check(s)."
    exit 1
}

"Verification passed: $($checks.Count) checks."
