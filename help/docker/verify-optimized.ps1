[CmdletBinding()]
param(
    [string]$ComposeFile = "help/docker/docker-compose.optimized.yml",
    [string]$EnvFile = "help/docker/.env.runtime",
    [Parameter(Mandatory)]
    [string]$SnapshotManifest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "../..")).Path
Import-Module (Join-Path $PSScriptRoot "RuntimeAcceptance.psm1") -Force

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
$snapshotPath = if ([IO.Path]::IsPathRooted($SnapshotManifest)) {
    $SnapshotManifest
} else {
    Join-Path $repoRoot $SnapshotManifest
}

$expectedSnapshot = Read-AcceptanceSnapshot -LiteralPath $snapshotPath
$acceptanceStartedAtUtc = [DateTimeOffset]::UtcNow
$checks = [Collections.Generic.List[object]]::new()
$temporaryRoot = [IO.Path]::GetTempPath()
$currentSnapshotDirectory = Join-Path $temporaryRoot ("crmeb-runtime-acceptance-" + [guid]::NewGuid().ToString("N"))

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

try {
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

    $runtimeLogOffsetResult = Invoke-Docker -Arguments @(
        "exec", "crmeb_php", "sh", "-lc",
        'for file in /var/www/runtime/log/*.log /var/www/runtime/timer.log /var/www/runtime/workerman.log; do if [ -f "$file" ]; then printf "%s|%s\n" "$file" "$(stat -c %s "$file")"; fi; done'
    )
    $runtimeLogOffsets = @{}
    if ($runtimeLogOffsetResult.ExitCode -eq 0) {
        foreach ($line in @($runtimeLogOffsetResult.Output -split "`r?`n" | Where-Object { $_ })) {
            if ($line -match '^(?<path>.+)\|(?<bytes>\d+)$') {
                $runtimeLogOffsets[$Matches.path] = [long]$Matches.bytes
            }
        }
    }
    Add-Check "runtime log cursors are recorded" ($runtimeLogOffsetResult.ExitCode -eq 0) "recorded $($runtimeLogOffsets.Count) runtime log offsets"

    $supervisorBeforeResult = Invoke-Docker -Arguments @("exec", "crmeb_php", "supervisorctl", "status")
    $supervisorBefore = $null
    try {
        if ($supervisorBeforeResult.ExitCode -eq 0) {
            $supervisorBefore = ConvertFrom-SupervisorStatus -Text $supervisorBeforeResult.Output
        }
    } catch {
        Add-Check "initial Supervisor status is parseable" $false $_.Exception.Message
    }
    if ($null -ne $supervisorBefore) {
        $initialProcessProblems = @(
            @('php-fpm', 'queue', 'timer', 'workerman') | Where-Object {
                -not $supervisorBefore.ContainsKey($_) -or $supervisorBefore[$_].Status -ne 'RUNNING'
            }
        )
        $initialProcessDetail = if ($initialProcessProblems.Count) {
            "not running: $($initialProcessProblems -join ', ')"
        } else {
            "php-fpm, queue, timer and workerman are running"
        }
        Add-Check "all supervised processes initially run" ($initialProcessProblems.Count -eq 0) $initialProcessDetail
    } elseif ($supervisorBeforeResult.ExitCode -ne 0) {
        Add-Check "initial Supervisor status is available" $false $supervisorBeforeResult.Output
    }

    $runtimeFactsCode = @'
$facts = [
    'phpVersion' => PHP_VERSION,
    'extensions' => get_loaded_extensions(),
    'opcacheLoaded' => extension_loaded('Zend OPcache'),
    'opcacheEnabled' => (bool) ini_get('opcache.enable'),
    'jit' => (string) ini_get('opcache.jit'),
    'jitBufferSize' => (int) ini_get('opcache.jit_buffer_size'),
];
echo json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
'@
    $runtimeFactsResult = Invoke-Docker -Arguments @("exec", "crmeb_php", "php", "-r", $runtimeFactsCode)
    $runtimeFactsDecision = $null
    try {
        if ($runtimeFactsResult.ExitCode -eq 0) {
            $runtimeFacts = $runtimeFactsResult.Output | ConvertFrom-Json
            $requiredExtensions = @('bcmath', 'curl', 'fileinfo', 'gd', 'iconv', 'mbstring', 'mysqli', 'pdo_mysql', 'pcntl', 'redis', 'simplexml', 'sockets', 'sodium', 'xml', 'zip')
            $runtimeFactsDecision = Test-PhpRuntimeFacts -Facts $runtimeFacts -RequiredExtensions $requiredExtensions
        }
    } catch {
        Add-Check "deployed PHP runtime facts are parseable" $false $_.Exception.Message
    }
    if ($null -ne $runtimeFactsDecision) {
        $runtimeFactsDetail = if ($runtimeFactsDecision.Passed) { "PHP 8.2, required extensions, OPcache enabled, JIT disabled" } else { $runtimeFactsDecision.Differences -join "; " }
        Add-Check "deployed PHP runtime matches the PHP 8.2 contract" $runtimeFactsDecision.Passed $runtimeFactsDetail
    } elseif ($runtimeFactsResult.ExitCode -ne 0) {
        Add-Check "deployed PHP runtime facts are available" $false $runtimeFactsResult.Output
    }

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
    $mountDetail = if ($sourceBindCount) { "host source bind found in PHP or Nginx" } else { "PHP and Nginx code are image-backed" }
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

    $uploadProbeValue = [guid]::NewGuid().ToString("N")
    $uploadProbeCommand = @'
set -eu
probe=$(mktemp /var/www/public/uploads/.acceptance-XXXXXX)
trap 'rm -f "$probe"' EXIT
printf '%s' "$1" > "$probe"
actual=$(cat "$probe")
[ "$actual" = "$1" ]
rm -f "$probe"
trap - EXIT
'@
    $uploadIoResult = Invoke-Docker -Arguments @(
        "exec", "--user", "www-data", "crmeb_php", "sh", "-lc",
        $uploadProbeCommand, "acceptance-upload", $uploadProbeValue
    )
    $uploadIoDetail = if ($uploadIoResult.ExitCode -eq 0) {
        "probe content matched and the probe file was removed"
    } else {
        $uploadIoResult.Output
    }
    Add-Check "uploads support create, read and delete as www-data" ($uploadIoResult.ExitCode -eq 0) $uploadIoDetail

    $installerLockResult = Invoke-Docker -Arguments @(
        "exec", "crmeb_php", "test", "-f", "/var/www/public/install.lock"
    )
    Add-Check "installer lock is present in the PHP image" ($installerLockResult.ExitCode -eq 0) "public/install.lock exists"

    $null = Get-HttpMeasurement -Url "http://127.0.0.1:8011/"
    $measurements = 1..3 | ForEach-Object { Get-HttpMeasurement -Url "http://127.0.0.1:8011/" }
    $slowMeasurements = @($measurements | Where-Object { $_.Status -ne 200 -or $_.Seconds -ge 2 })
    $timings = ($measurements | ForEach-Object { "HTTP $($_.Status) in $([Math]::Round($_.Seconds, 3))s" }) -join "; "
    Add-Check "three warm storefront requests finish under two seconds" ($slowMeasurements.Count -eq 0) $timings

    $routeMeasurements = @{
        storefront = Get-HttpMeasurement -Url "http://127.0.0.1:8011/"
        admin = Get-HttpMeasurement -Url "http://127.0.0.1:8011/admin/"
        spa = Get-HttpMeasurement -Url "http://127.0.0.1:8011/pages/index/index"
        api = Get-HttpMeasurement -Url "http://127.0.0.1:8011/api/index"
    }

    $installerMeasurement = Get-HttpMeasurement -Url "http://127.0.0.1:8011/install/index.php"
    Add-Check "web installer is blocked" ($installerMeasurement.Status -eq 404) "HTTP $($installerMeasurement.Status)"

    $webSocketHeaders = @(
        "Connection: Upgrade",
        "Upgrade: websocket",
        "Sec-WebSocket-Version: 13",
        "Sec-WebSocket-Key: SGVsbG9Xb3JsZDEyMzQ1Ng=="
    )
    foreach ($path in @("notice", "msg")) {
        $routeMeasurements[$path] = Get-HttpMeasurement -Url "http://127.0.0.1:8011/$path" -TimeoutSeconds 3 -Headers $webSocketHeaders
    }

    $actualRouteStatuses = @{}
    foreach ($name in $routeMeasurements.Keys) {
        $actualRouteStatuses[$name] = $routeMeasurements[$name].Status
    }
    $expectedRouteStatuses = @{
        storefront = 200
        admin = 200
        spa = 200
        api = 200
        notice = 101
        msg = 101
    }
    $routeDecision = Test-RouteStatuses -Statuses $actualRouteStatuses -Expected $expectedRouteStatuses
    $routeDetail = if ($routeDecision.Passed) {
        ($actualRouteStatuses.Keys | Sort-Object | ForEach-Object { "$_=$($actualRouteStatuses[$_])" }) -join ", "
    } else {
        $routeDecision.Differences -join "; "
    }
    Add-Check "HTTP and WebSocket routes return exact statuses" $routeDecision.Passed $routeDetail

    try {
        $captureScriptPath = Join-Path $PSScriptRoot "capture-acceptance-snapshot.ps1"
        $null = & $captureScriptPath -Mode Capture -OutputDirectory $currentSnapshotDirectory
        $actualSnapshot = Read-AcceptanceSnapshot -LiteralPath (Join-Path $currentSnapshotDirectory "acceptance-snapshot.json")
        $snapshotDecision = Compare-AcceptanceSnapshot -Expected $expectedSnapshot -Actual $actualSnapshot
        $snapshotDetail = if ($snapshotDecision.Passed) {
            "database schema/data and $($actualSnapshot.uploads.fileCount) uploads match the pre-cutover snapshot"
        } else {
            $snapshotDecision.Differences -join "; "
        }
        Add-Check "acceptance data matches the explicit snapshot" $snapshotDecision.Passed $snapshotDetail
    } catch {
        Add-Check "acceptance data matches the explicit snapshot" $false $_.Exception.Message
    }

    Start-Sleep -Seconds 5
    $supervisorAfterResult = Invoke-Docker -Arguments @("exec", "crmeb_php", "supervisorctl", "status")
    $restartCountResult = Invoke-Docker -Arguments @("inspect", "--format", "{{.RestartCount}}", "crmeb_php")
    if ($null -ne $supervisorBefore -and $supervisorAfterResult.ExitCode -eq 0 -and $restartCountResult.ExitCode -eq 0) {
        try {
            $supervisorAfter = ConvertFrom-SupervisorStatus -Text $supervisorAfterResult.Output
            $processDecision = Compare-SupervisorSnapshots `
                -Before $supervisorBefore `
                -After $supervisorAfter `
                -ContainerRestartCount ([int]$restartCountResult.Output)
            $processDetail = if ($processDecision.Passed) { "four Supervisor PIDs stayed stable; container restart count=0" } else { $processDecision.Differences -join "; " }
            Add-Check "supervised processes stay stable" $processDecision.Passed $processDetail
        } catch {
            Add-Check "supervised processes stay stable" $false $_.Exception.Message
        }
    } else {
        Add-Check "supervised processes stay stable" $false "Supervisor or container restart state was unavailable"
    }

    $logLines = [Collections.Generic.List[string]]::new()
    $logCollectionErrors = [Collections.Generic.List[string]]::new()
    $sinceValue = $acceptanceStartedAtUtc.UtcDateTime.ToString("O")
    foreach ($container in @("crmeb_php", "crmeb_nginx")) {
        $containerLogs = Invoke-Docker -Arguments @("logs", "--since", $sinceValue, $container)
        if ($containerLogs.ExitCode -ne 0) {
            $logCollectionErrors.Add("$container logs unavailable")
            continue
        }
        foreach ($line in @($containerLogs.Output -split "`r?`n" | Where-Object { $_ })) {
            $logLines.Add($line)
        }
    }

    foreach ($path in $runtimeLogOffsets.Keys) {
        $offset = [long]$runtimeLogOffsets[$path]
        $newLogCommand = @'
file=$1
offset=$2
[ -f "$file" ] || exit 0
size=$(stat -c %s "$file")
if [ "$size" -lt "$offset" ]; then offset=0; fi
if [ "$size" -gt "$offset" ]; then tail -c "+$((offset + 1))" "$file"; fi
'@
        $newLogResult = Invoke-Docker -Arguments @(
            "exec", "crmeb_php", "sh", "-lc", $newLogCommand,
            "acceptance-log", $path, [string]$offset
        )
        if ($newLogResult.ExitCode -ne 0) {
            $logCollectionErrors.Add("runtime log unavailable: $path")
            continue
        }
        foreach ($line in @($newLogResult.Output -split "`r?`n" | Where-Object { $_ })) {
            $logLines.Add($line)
        }
    }

    $logViolations = @(Find-RuntimeLogViolation -Lines $logLines.ToArray())
    $logsPassed = $logCollectionErrors.Count -eq 0 -and $logViolations.Count -eq 0
    $logsDetail = if ($logCollectionErrors.Count) {
        $logCollectionErrors -join "; "
    } elseif ($logViolations.Count) {
        ($logViolations | Select-Object -First 5) -join "; "
    } else {
        "no new fatal, type, deprecation, termination or worker-exit diagnostics"
    }
    Add-Check "acceptance workflow logs are clean" $logsPassed $logsDetail

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
} finally {
    if (Test-Path -LiteralPath $currentSnapshotDirectory) {
        $resolvedCurrentSnapshotDirectory = (Resolve-Path -LiteralPath $currentSnapshotDirectory).Path
        if (-not $resolvedCurrentSnapshotDirectory.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to clean unexpected snapshot directory: $resolvedCurrentSnapshotDirectory"
        }
        Remove-Item -LiteralPath $resolvedCurrentSnapshotDirectory -Recurse -Force
    }
}
