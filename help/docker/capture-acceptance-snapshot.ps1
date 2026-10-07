[CmdletBinding()]
param(
    [ValidateSet("Capture", "Import")]
    [string]$Mode = "Capture",
    [Parameter(Mandatory)]
    [string]$OutputDirectory,
    [string]$DatabaseName = "crmeb",
    [string]$MySqlContainer = "crmeb_mysql",
    [string]$UploadRoot,
    [string]$SchemaPath,
    [string]$DataPath,
    [string]$UploadManifestCsv,
    [long]$TableCount = -1,
    [string]$DumpClient
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "../..")).Path
Import-Module (Join-Path $PSScriptRoot "RuntimeAcceptance.psm1") -Force

function Resolve-InputFile {
    param(
        [string]$Path,
        [string]$Name
    )

    if ([string]::IsNullOrWhiteSpace($Path)) {
        throw "$Name is required in Import mode"
    }
    $candidate = if ([IO.Path]::IsPathRooted($Path)) { $Path } else { Join-Path $repoRoot $Path }
    if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) {
        throw "$Name not found: $candidate"
    }
    (Resolve-Path -LiteralPath $candidate).Path
}

function Invoke-Docker {
    param([string[]]$Arguments)

    $output = & docker @Arguments 2>&1
    [pscustomobject]@{
        ExitCode = $LASTEXITCODE
        Output = ($output -join "`n").Trim()
    }
}

if ($DatabaseName -notmatch '^[A-Za-z0-9_]+$') {
    throw "DatabaseName contains unsupported characters: $DatabaseName"
}

$resolvedOutputDirectory = if ([IO.Path]::IsPathRooted($OutputDirectory)) {
    [IO.Path]::GetFullPath($OutputDirectory)
} else {
    [IO.Path]::GetFullPath((Join-Path $repoRoot $OutputDirectory))
}

if ($Mode -eq "Import") {
    $resolvedSchemaPath = Resolve-InputFile -Path $SchemaPath -Name "SchemaPath"
    $resolvedDataPath = Resolve-InputFile -Path $DataPath -Name "DataPath"
    $resolvedUploadManifestCsv = Resolve-InputFile -Path $UploadManifestCsv -Name "UploadManifestCsv"

    if ($TableCount -lt 0) {
        $TableCount = @(Select-String -LiteralPath $resolvedSchemaPath -Pattern '^CREATE TABLE ').Count
    }
    if ([string]::IsNullOrWhiteSpace($DumpClient)) {
        $DumpClient = "mysqldump 8.0 imported canonical dump"
    }

    $uploadEntries = @(Import-UploadHashCsv -LiteralPath $resolvedUploadManifestCsv)
    $manifest = New-AcceptanceSnapshotManifest `
        -DatabaseName $DatabaseName `
        -TableCount $TableCount `
        -SchemaPath $resolvedSchemaPath `
        -DataPath $resolvedDataPath `
        -DumpClient $DumpClient `
        -UploadEntries $uploadEntries

    $manifestPath = Write-AcceptanceSnapshotManifest -Manifest $manifest -OutputDirectory $resolvedOutputDirectory
    "Snapshot imported: $manifestPath"
    "Database tables: $($manifest.database.tableCount)"
    "Upload files: $($manifest.uploads.fileCount)"
    "Upload bytes: $($manifest.uploads.totalBytes)"
    exit 0
}

if (-not (Test-Path -LiteralPath $resolvedOutputDirectory)) {
    $null = New-Item -ItemType Directory -Path $resolvedOutputDirectory
}
$resolvedOutputDirectory = (Resolve-Path -LiteralPath $resolvedOutputDirectory).Path

if ([string]::IsNullOrWhiteSpace($UploadRoot)) {
    $UploadRoot = Join-Path $repoRoot "crmeb/public/uploads"
} elseif (-not [IO.Path]::IsPathRooted($UploadRoot)) {
    $UploadRoot = Join-Path $repoRoot $UploadRoot
}
$resolvedUploadRoot = (Resolve-Path -LiteralPath $UploadRoot -ErrorAction Stop).Path

$manifestTarget = Join-Path $resolvedOutputDirectory "acceptance-snapshot.json"
$schemaTarget = Join-Path $resolvedOutputDirectory "crmeb-schema.sql"
$dataTarget = Join-Path $resolvedOutputDirectory "crmeb-data.sql"
foreach ($target in @($manifestTarget, $schemaTarget, $dataTarget)) {
    if (Test-Path -LiteralPath $target) {
        throw "Snapshot output already exists: $target"
    }
}

$identifier = [guid]::NewGuid().ToString("N")
$stagingDirectory = Join-Path $resolvedOutputDirectory ".snapshot-staging-$identifier"
$null = New-Item -ItemType Directory -Path $stagingDirectory
$stagedSchemaPath = Join-Path $stagingDirectory "crmeb-schema.sql"
$stagedDataPath = Join-Path $stagingDirectory "crmeb-data.sql"
$remoteSchemaPath = "/tmp/acceptance-schema-$identifier.sql"
$remoteDataPath = "/tmp/acceptance-data-$identifier.sql"
$publishedTargets = [Collections.Generic.List[string]]::new()

try {
    $schemaCommand = 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --skip-comments --compact --skip-dump-date --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --no-data "$1" > "$2"'
    $schemaResult = Invoke-Docker -Arguments @(
        "exec", $MySqlContainer, "sh", "-lc", $schemaCommand,
        "acceptance-snapshot", $DatabaseName, $remoteSchemaPath
    )
    if ($schemaResult.ExitCode -ne 0) {
        throw "Schema export failed: $($schemaResult.Output)"
    }

    $dataCommand = 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --skip-comments --compact --skip-dump-date --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --no-create-info --hex-blob --order-by-primary "$1" > "$2"'
    $dataResult = Invoke-Docker -Arguments @(
        "exec", $MySqlContainer, "sh", "-lc", $dataCommand,
        "acceptance-snapshot", $DatabaseName, $remoteDataPath
    )
    if ($dataResult.ExitCode -ne 0) {
        throw "Data export failed: $($dataResult.Output)"
    }

    $schemaCopy = Invoke-Docker -Arguments @("cp", "${MySqlContainer}:$remoteSchemaPath", $stagedSchemaPath)
    if ($schemaCopy.ExitCode -ne 0) {
        throw "Schema copy failed: $($schemaCopy.Output)"
    }
    $dataCopy = Invoke-Docker -Arguments @("cp", "${MySqlContainer}:$remoteDataPath", $stagedDataPath)
    if ($dataCopy.ExitCode -ne 0) {
        throw "Data copy failed: $($dataCopy.Output)"
    }

    $tablesResult = Invoke-Docker -Arguments @(
        "exec", $MySqlContainer, "sh", "-lc",
        'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" "$1" -e "SHOW TABLES"',
        "acceptance-snapshot", $DatabaseName
    )
    if ($tablesResult.ExitCode -ne 0) {
        throw "Table inventory failed: $($tablesResult.Output)"
    }
    $capturedTableCount = @($tablesResult.Output -split "`r?`n" | Where-Object { $_ -and $_ -notmatch '^mysql:' }).Count

    $versionResult = Invoke-Docker -Arguments @("exec", $MySqlContainer, "mysqldump", "--version")
    if ($versionResult.ExitCode -ne 0) {
        throw "mysqldump version check failed: $($versionResult.Output)"
    }

    $uploadEntries = [Collections.Generic.List[object]]::new()
    foreach ($file in Get-ChildItem -LiteralPath $resolvedUploadRoot -Recurse -File | Sort-Object -Property FullName) {
        $uploadEntries.Add((New-UploadSnapshotEntry -LiteralPath $file.FullName -RootPath $resolvedUploadRoot))
    }

    $manifest = New-AcceptanceSnapshotManifest `
        -DatabaseName $DatabaseName `
        -TableCount $capturedTableCount `
        -SchemaPath $stagedSchemaPath `
        -DataPath $stagedDataPath `
        -DumpClient $versionResult.Output `
        -UploadEntries $uploadEntries.ToArray()
    $stagedManifestPath = Write-AcceptanceSnapshotManifest -Manifest $manifest -OutputDirectory $stagingDirectory

    Move-Item -LiteralPath $stagedSchemaPath -Destination $schemaTarget
    $publishedTargets.Add($schemaTarget)
    Move-Item -LiteralPath $stagedDataPath -Destination $dataTarget
    $publishedTargets.Add($dataTarget)
    Move-Item -LiteralPath $stagedManifestPath -Destination $manifestTarget
    $publishedTargets.Add($manifestTarget)

    "Snapshot captured: $manifestTarget"
    "Database tables: $capturedTableCount"
    "Upload files: $($manifest.uploads.fileCount)"
    "Upload bytes: $($manifest.uploads.totalBytes)"
} catch {
    foreach ($target in $publishedTargets) {
        if (Test-Path -LiteralPath $target) {
            Remove-Item -LiteralPath $target -Force
        }
    }
    throw
} finally {
    $null = Invoke-Docker -Arguments @("exec", $MySqlContainer, "rm", "-f", $remoteSchemaPath, $remoteDataPath)
    if (Test-Path -LiteralPath $stagingDirectory) {
        $resolvedStagingDirectory = (Resolve-Path -LiteralPath $stagingDirectory).Path
        if (-not $resolvedStagingDirectory.StartsWith($resolvedOutputDirectory, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to clean unexpected staging directory: $resolvedStagingDirectory"
        }
        Remove-Item -LiteralPath $resolvedStagingDirectory -Recurse -Force
    }
}
