Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function New-DecisionResult {
    param([Collections.Generic.List[string]]$Differences)

    [pscustomobject]@{
        Passed = $Differences.Count -eq 0
        Differences = [string[]]$Differences.ToArray()
    }
}

function Get-RequiredPropertyValue {
    param(
        [object]$InputObject,
        [string]$Name,
        [string]$Context
    )

    if ($null -eq $InputObject) {
        throw "$Context is missing"
    }

    $property = $InputObject.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value -or (
        $property.Value -is [string] -and [string]::IsNullOrWhiteSpace($property.Value)
    )) {
        throw "$Context.$Name is required"
    }

    $property.Value
}

function ConvertTo-NormalizedUploadPath {
    param([string]$Path)

    if ([string]::IsNullOrWhiteSpace($Path)) {
        throw "uploads.files.relativePath is required"
    }

    $normalized = $Path.Replace('\', '/').Trim()
    if ($normalized.StartsWith('/') -or $normalized -match '^[A-Za-z]:' -or $normalized.Contains([char]0)) {
        throw "uploads.files.relativePath must stay below the upload root: $Path"
    }

    $segments = @($normalized.Split('/') | Where-Object { $_ -ne '' })
    if (-not $segments.Count -or @($segments | Where-Object { $_ -in @('.', '..') }).Count) {
        throw "uploads.files.relativePath must not traverse directories: $Path"
    }

    $segments -join '/'
}

function Assert-Sha256 {
    param(
        [string]$Value,
        [string]$Context
    )

    if ($Value -notmatch '^[0-9A-Fa-f]{64}$') {
        throw "$Context must be a 64-character SHA256"
    }

    $Value.ToUpperInvariant()
}

function Read-AcceptanceSnapshot {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$LiteralPath)

    if (-not (Test-Path -LiteralPath $LiteralPath -PathType Leaf)) {
        throw "Snapshot manifest not found: $LiteralPath"
    }

    try {
        $snapshot = Get-Content -LiteralPath $LiteralPath -Raw | ConvertFrom-Json
    } catch {
        throw "Snapshot manifest is not valid JSON: $($_.Exception.Message)"
    }

    $formatVersion = [int](Get-RequiredPropertyValue -InputObject $snapshot -Name 'formatVersion' -Context 'snapshot')
    if ($formatVersion -ne 1) {
        throw "Unsupported snapshot formatVersion: $formatVersion"
    }

    $createdAtUtc = [string](Get-RequiredPropertyValue -InputObject $snapshot -Name 'createdAtUtc' -Context 'snapshot')
    $parsedTimestamp = [DateTimeOffset]::MinValue
    if (-not [DateTimeOffset]::TryParse($createdAtUtc, [ref]$parsedTimestamp)) {
        throw "snapshot.createdAtUtc is not a valid timestamp"
    }

    $database = Get-RequiredPropertyValue -InputObject $snapshot -Name 'database' -Context 'snapshot'
    $databaseName = [string](Get-RequiredPropertyValue -InputObject $database -Name 'name' -Context 'snapshot.database')
    $tableCount = [long](Get-RequiredPropertyValue -InputObject $database -Name 'tableCount' -Context 'snapshot.database')
    if ($tableCount -lt 0) {
        throw "snapshot.database.tableCount must not be negative"
    }

    $schemaSha256 = Assert-Sha256 -Value ([string](Get-RequiredPropertyValue -InputObject $database -Name 'schemaSha256' -Context 'snapshot.database')) -Context 'snapshot.database.schemaSha256'
    $dataSha256 = Assert-Sha256 -Value ([string](Get-RequiredPropertyValue -InputObject $database -Name 'dataSha256' -Context 'snapshot.database')) -Context 'snapshot.database.dataSha256'
    $dumpClient = [string](Get-RequiredPropertyValue -InputObject $database -Name 'dumpClient' -Context 'snapshot.database')

    $uploads = Get-RequiredPropertyValue -InputObject $snapshot -Name 'uploads' -Context 'snapshot'
    $declaredFileCount = [long](Get-RequiredPropertyValue -InputObject $uploads -Name 'fileCount' -Context 'snapshot.uploads')
    $declaredTotalBytes = [long](Get-RequiredPropertyValue -InputObject $uploads -Name 'totalBytes' -Context 'snapshot.uploads')
    $rawFiles = @(Get-RequiredPropertyValue -InputObject $uploads -Name 'files' -Context 'snapshot.uploads')

    $seen = [Collections.Generic.Dictionary[string, object]]::new([StringComparer]::Ordinal)
    $normalizedFiles = [Collections.Generic.List[object]]::new()
    foreach ($file in $rawFiles) {
        $relativePath = ConvertTo-NormalizedUploadPath -Path ([string](Get-RequiredPropertyValue -InputObject $file -Name 'relativePath' -Context 'snapshot.uploads.files'))
        if ($seen.ContainsKey($relativePath)) {
            throw "Snapshot contains duplicate upload relativePath: $relativePath"
        }

        $bytes = [long](Get-RequiredPropertyValue -InputObject $file -Name 'bytes' -Context "snapshot.uploads.files[$relativePath]")
        if ($bytes -lt 0) {
            throw "Snapshot upload bytes must not be negative: $relativePath"
        }

        $sha256 = Assert-Sha256 -Value ([string](Get-RequiredPropertyValue -InputObject $file -Name 'sha256' -Context "snapshot.uploads.files[$relativePath]")) -Context "snapshot.uploads.files[$relativePath].sha256"
        $entry = [pscustomobject]@{
            relativePath = $relativePath
            bytes = $bytes
            sha256 = $sha256
        }
        $seen.Add($relativePath, $entry)
        $normalizedFiles.Add($entry)
    }

    $sortedFiles = @($normalizedFiles | Sort-Object -Property relativePath -CaseSensitive)
    $actualTotalBytes = if ($sortedFiles.Count) {
        [long](($sortedFiles | Measure-Object -Property bytes -Sum).Sum)
    } else {
        0L
    }
    if ($declaredFileCount -ne $sortedFiles.Count) {
        throw "snapshot.uploads.fileCount does not match files: declared=$declaredFileCount actual=$($sortedFiles.Count)"
    }
    if ($declaredTotalBytes -ne $actualTotalBytes) {
        throw "snapshot.uploads.totalBytes does not match files: declared=$declaredTotalBytes actual=$actualTotalBytes"
    }

    [pscustomobject]@{
        formatVersion = 1
        createdAtUtc = $parsedTimestamp.UtcDateTime.ToString('O')
        database = [pscustomobject]@{
            name = $databaseName
            tableCount = $tableCount
            schemaSha256 = $schemaSha256
            dataSha256 = $dataSha256
            dumpClient = $dumpClient
        }
        uploads = [pscustomobject]@{
            fileCount = $sortedFiles.Count
            totalBytes = $actualTotalBytes
            files = $sortedFiles
        }
    }
}

function ConvertTo-UploadDictionary {
    param([object[]]$Files)

    $dictionary = [Collections.Generic.Dictionary[string, object]]::new([StringComparer]::Ordinal)
    foreach ($file in @($Files)) {
        $path = ConvertTo-NormalizedUploadPath -Path ([string]$file.relativePath)
        if ($dictionary.ContainsKey($path)) {
            throw "Snapshot contains duplicate upload relativePath: $path"
        }
        $dictionary.Add($path, $file)
    }
    $dictionary
}

function Compare-AcceptanceSnapshot {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][object]$Expected,
        [Parameter(Mandatory)][object]$Actual
    )

    $differences = [Collections.Generic.List[string]]::new()
    foreach ($property in @('name', 'tableCount', 'schemaSha256', 'dataSha256')) {
        $expectedValue = [string]$Expected.database.$property
        $actualValue = [string]$Actual.database.$property
        if (-not [string]::Equals($expectedValue, $actualValue, [StringComparison]::OrdinalIgnoreCase)) {
            $differences.Add("database $property changed: expected=$expectedValue actual=$actualValue")
        }
    }

    $expectedFiles = ConvertTo-UploadDictionary -Files @($Expected.uploads.files)
    $actualFiles = ConvertTo-UploadDictionary -Files @($Actual.uploads.files)

    foreach ($path in $expectedFiles.Keys) {
        if (-not $actualFiles.ContainsKey($path)) {
            $differences.Add("upload missing: $path")
            continue
        }

        $expectedFile = $expectedFiles[$path]
        $actualFile = $actualFiles[$path]
        if ([long]$expectedFile.bytes -ne [long]$actualFile.bytes -or -not [string]::Equals(
            [string]$expectedFile.sha256,
            [string]$actualFile.sha256,
            [StringComparison]::OrdinalIgnoreCase
        )) {
            $differences.Add("upload changed: $path")
        }
    }

    foreach ($path in $actualFiles.Keys) {
        if (-not $expectedFiles.ContainsKey($path)) {
            $differences.Add("upload extra: $path")
        }
    }

    New-DecisionResult -Differences $differences
}

function New-UploadSnapshotEntry {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string]$LiteralPath,
        [Parameter(Mandatory)][string]$RootPath
    )

    $filePath = (Resolve-Path -LiteralPath $LiteralPath -ErrorAction Stop).Path
    $rootFullPath = (Resolve-Path -LiteralPath $RootPath -ErrorAction Stop).Path
    if (-not (Test-Path -LiteralPath $filePath -PathType Leaf)) {
        throw "Upload snapshot path is not a file: $LiteralPath"
    }

    $relativePath = ConvertTo-NormalizedUploadPath -Path ([IO.Path]::GetRelativePath($rootFullPath, $filePath))
    $file = Get-Item -LiteralPath $filePath
    [pscustomobject]@{
        relativePath = $relativePath
        bytes = [long]$file.Length
        sha256 = (Get-FileHash -LiteralPath $filePath -Algorithm SHA256).Hash.ToUpperInvariant()
    }
}

function Import-UploadHashCsv {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$LiteralPath)

    if (-not (Test-Path -LiteralPath $LiteralPath -PathType Leaf)) {
        throw "Upload hash CSV not found: $LiteralPath"
    }

    $headerLine = Get-Content -LiteralPath $LiteralPath -TotalCount 1
    $headers = @($headerLine -split ',' | ForEach-Object { $_.Trim().Trim('"') })
    foreach ($required in @('RelativePath', 'Bytes', 'SHA256')) {
        if ($required -notin $headers) {
            throw "Upload hash CSV must contain RelativePath, Bytes, and SHA256 columns; missing $required"
        }
    }

    $seen = [Collections.Generic.Dictionary[string, object]]::new([StringComparer]::Ordinal)
    $entries = [Collections.Generic.List[object]]::new()
    foreach ($row in @(Import-Csv -LiteralPath $LiteralPath)) {
        $relativePath = ConvertTo-NormalizedUploadPath -Path ([string]$row.RelativePath)
        if ($seen.ContainsKey($relativePath)) {
            throw "Upload hash CSV contains duplicate RelativePath: $relativePath"
        }

        $bytes = 0L
        if (-not [long]::TryParse([string]$row.Bytes, [ref]$bytes) -or $bytes -lt 0) {
            throw "Upload hash CSV Bytes is invalid for $relativePath"
        }
        $sha256 = Assert-Sha256 -Value ([string]$row.SHA256) -Context "Upload hash CSV SHA256 for $relativePath"

        $entry = [pscustomobject]@{
            relativePath = $relativePath
            bytes = $bytes
            sha256 = $sha256
        }
        $seen.Add($relativePath, $entry)
        $entries.Add($entry)
    }

    @($entries | Sort-Object -Property relativePath -CaseSensitive)
}

function New-AcceptanceSnapshotManifest {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string]$DatabaseName,
        [Parameter(Mandatory)][ValidateRange(0, [long]::MaxValue)][long]$TableCount,
        [Parameter(Mandatory)][string]$SchemaPath,
        [Parameter(Mandatory)][string]$DataPath,
        [Parameter(Mandatory)][string]$DumpClient,
        [Parameter(Mandatory)][AllowEmptyCollection()][object[]]$UploadEntries,
        [DateTimeOffset]$CreatedAtUtc = [DateTimeOffset]::UtcNow
    )

    if (-not (Test-Path -LiteralPath $SchemaPath -PathType Leaf)) {
        throw "Schema dump not found: $SchemaPath"
    }
    if (-not (Test-Path -LiteralPath $DataPath -PathType Leaf)) {
        throw "Data dump not found: $DataPath"
    }

    $uploadsByPath = ConvertTo-UploadDictionary -Files @($UploadEntries)
    $normalizedEntries = @($uploadsByPath.Values | ForEach-Object {
        [pscustomobject]@{
            relativePath = ConvertTo-NormalizedUploadPath -Path ([string]$_.relativePath)
            bytes = [long]$_.bytes
            sha256 = Assert-Sha256 -Value ([string]$_.sha256) -Context "Upload SHA256 for $($_.relativePath)"
        }
    } | Sort-Object -Property relativePath -CaseSensitive)
    $totalBytes = if ($normalizedEntries.Count) {
        [long](($normalizedEntries | Measure-Object -Property bytes -Sum).Sum)
    } else {
        0L
    }

    [pscustomobject]@{
        formatVersion = 1
        createdAtUtc = $CreatedAtUtc.UtcDateTime.ToString('O')
        database = [pscustomobject]@{
            name = $DatabaseName
            tableCount = $TableCount
            schemaSha256 = (Get-FileHash -LiteralPath $SchemaPath -Algorithm SHA256).Hash.ToUpperInvariant()
            dataSha256 = (Get-FileHash -LiteralPath $DataPath -Algorithm SHA256).Hash.ToUpperInvariant()
            dumpClient = $DumpClient
        }
        uploads = [pscustomobject]@{
            fileCount = $normalizedEntries.Count
            totalBytes = $totalBytes
            files = $normalizedEntries
        }
    }
}

function Write-AcceptanceSnapshotManifest {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][object]$Manifest,
        [Parameter(Mandatory)][string]$OutputDirectory
    )

    if (-not (Test-Path -LiteralPath $OutputDirectory)) {
        $null = New-Item -ItemType Directory -Path $OutputDirectory
    }
    $resolvedOutputDirectory = (Resolve-Path -LiteralPath $OutputDirectory).Path
    $manifestPath = Join-Path $resolvedOutputDirectory 'acceptance-snapshot.json'
    if (Test-Path -LiteralPath $manifestPath) {
        throw "Acceptance snapshot already exists: $manifestPath"
    }

    $temporaryPath = Join-Path $resolvedOutputDirectory (".acceptance-snapshot." + [guid]::NewGuid().ToString('N') + '.tmp')
    try {
        [IO.File]::WriteAllText(
            $temporaryPath,
            ($Manifest | ConvertTo-Json -Depth 8),
            [Text.UTF8Encoding]::new($false)
        )
        Move-Item -LiteralPath $temporaryPath -Destination $manifestPath
    } finally {
        if (Test-Path -LiteralPath $temporaryPath) {
            Remove-Item -LiteralPath $temporaryPath -Force
        }
    }

    $manifestPath
}

function Test-PhpRuntimeFacts {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][object]$Facts,
        [Parameter(Mandatory)][string[]]$RequiredExtensions
    )

    $differences = [Collections.Generic.List[string]]::new()
    if ([string]$Facts.phpVersion -notmatch '^8\.2(?:\.|$)') {
        $differences.Add("PHP version must be 8.2.x, got $($Facts.phpVersion)")
    }

    $loaded = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    foreach ($extension in @($Facts.extensions)) {
        $null = $loaded.Add([string]$extension)
    }
    foreach ($required in $RequiredExtensions) {
        if (-not $loaded.Contains($required)) {
            $differences.Add("PHP extension missing: $required")
        }
    }

    if (-not [bool]$Facts.opcacheLoaded) {
        $differences.Add("Zend OPcache is not loaded")
    }
    if (-not [bool]$Facts.opcacheEnabled) {
        $differences.Add("OPcache is not enabled")
    }

    $jit = ([string]$Facts.jit).Trim().ToLowerInvariant()
    if ($jit -notin @('', '0', 'off', 'disable', 'disabled') -or [long]$Facts.jitBufferSize -ne 0) {
        $differences.Add("PHP JIT must be disabled")
    }

    New-DecisionResult -Differences $differences
}

function ConvertFrom-SupervisorStatus {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$Text)

    $processes = [Collections.Generic.Dictionary[string, object]]::new([StringComparer]::Ordinal)
    foreach ($line in @($Text -split "`r?`n" | Where-Object { $_.Trim() })) {
        if ($line -notmatch '^(?<name>\S+)\s+(?<status>[A-Z]+)(?:\s+pid\s+(?<pid>\d+),\s+uptime\s+(?<uptime>.+))?') {
            throw "Unrecognized Supervisor status line: $line"
        }

        $processes[$Matches.name] = [pscustomobject]@{
            Name = $Matches.name
            Status = $Matches.status
            Pid = if ($Matches.ContainsKey('pid') -and $Matches['pid']) { [int]$Matches['pid'] } else { 0 }
            Uptime = if ($Matches.ContainsKey('uptime') -and $Matches['uptime']) { $Matches['uptime'].Trim() } else { '' }
        }
    }

    $processes
}

function Compare-SupervisorSnapshots {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][Collections.IDictionary]$Before,
        [Parameter(Mandatory)][Collections.IDictionary]$After,
        [Parameter(Mandatory)][int]$ContainerRestartCount
    )

    $differences = [Collections.Generic.List[string]]::new()
    foreach ($name in @('php-fpm', 'queue', 'timer', 'workerman')) {
        if (-not $Before.ContainsKey($name) -or -not $After.ContainsKey($name)) {
            $differences.Add("Supervisor process missing: $name")
            continue
        }

        $beforeProcess = $Before[$name]
        $afterProcess = $After[$name]
        if ($beforeProcess.Status -ne 'RUNNING' -or $afterProcess.Status -ne 'RUNNING') {
            $differences.Add("Supervisor process is not stable RUNNING: $name before=$($beforeProcess.Status) after=$($afterProcess.Status)")
            continue
        }
        if ([int]$beforeProcess.Pid -le 0 -or [int]$beforeProcess.Pid -ne [int]$afterProcess.Pid) {
            $differences.Add("Supervisor process PID changed: $name before=$($beforeProcess.Pid) after=$($afterProcess.Pid)")
        }
    }

    if ($ContainerRestartCount -ne 0) {
        $differences.Add("PHP container restart count must be zero, got $ContainerRestartCount")
    }

    New-DecisionResult -Differences $differences
}

function Find-RuntimeLogViolation {
    [CmdletBinding()]
    param([Parameter(Mandatory)][AllowEmptyCollection()][string[]]$Lines)

    $violations = [Collections.Generic.List[string]]::new()
    foreach ($line in $Lines) {
        if ($line -match '(?i)Undefined array key 0.*think-image.*gif[\\/]Encoder\.php.*line 77') {
            continue
        }
        if ($line -match '(?i)(Fatal error|TypeError:|\bDeprecated:|process terminated|exited too quickly|child process.+exit|worker.+exit)') {
            $violations.Add($line)
        }
    }

    $violations.ToArray()
}

function Test-RouteStatuses {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][Collections.IDictionary]$Statuses,
        [Parameter(Mandatory)][Collections.IDictionary]$Expected
    )

    $differences = [Collections.Generic.List[string]]::new()
    foreach ($name in $Expected.Keys) {
        if (-not $Statuses.Contains($name)) {
            $differences.Add("route status missing: $name")
            continue
        }
        if ([int]$Statuses[$name] -ne [int]$Expected[$name]) {
            $differences.Add("route status mismatch: $name expected=$($Expected[$name]) actual=$($Statuses[$name])")
        }
    }

    New-DecisionResult -Differences $differences
}

Export-ModuleMember -Function @(
    'Read-AcceptanceSnapshot',
    'Compare-AcceptanceSnapshot',
    'New-UploadSnapshotEntry',
    'Import-UploadHashCsv',
    'New-AcceptanceSnapshotManifest',
    'Write-AcceptanceSnapshotManifest',
    'Test-PhpRuntimeFacts',
    'ConvertFrom-SupervisorStatus',
    'Compare-SupervisorSnapshots',
    'Find-RuntimeLogViolation',
    'Test-RouteStatuses'
)
