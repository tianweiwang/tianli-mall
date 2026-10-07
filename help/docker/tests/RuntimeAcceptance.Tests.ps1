[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$modulePath = Join-Path $PSScriptRoot "../RuntimeAcceptance.psm1"
Import-Module -Name $modulePath -Force

$script:passed = 0
$script:failed = 0

function Invoke-Test {
    param(
        [string]$Name,
        [scriptblock]$Body
    )

    try {
        & $Body
        $script:passed++
        "[PASS] $Name"
    } catch {
        $script:failed++
        "[FAIL] $Name`: $($_.Exception.Message)"
    }
}

function Assert-True {
    param(
        [bool]$Condition,
        [string]$Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Assert-False {
    param(
        [bool]$Condition,
        [string]$Message
    )

    if ($Condition) {
        throw $Message
    }
}

function Assert-Throws {
    param(
        [scriptblock]$Body,
        [string]$Pattern
    )

    try {
        & $Body
    } catch {
        if ($_.Exception.Message -notmatch $Pattern) {
            throw "Expected error matching '$Pattern', got '$($_.Exception.Message)'"
        }
        return
    }

    throw "Expected an exception matching '$Pattern'"
}

function New-TestSnapshot {
    param(
        [string]$SchemaHash = ("A" * 64),
        [string]$DataHash = ("B" * 64),
        [object[]]$Files = @(
            [pscustomobject]@{ relativePath = "attach/catalog/item-a.png"; bytes = 12; sha256 = ("C" * 64) },
            [pscustomobject]@{ relativePath = "attach/catalog/item-b.jpg"; bytes = 18; sha256 = ("D" * 64) }
        )
    )

    [pscustomobject]@{
        formatVersion = 1
        createdAtUtc = "2026-07-30T00:00:00.0000000Z"
        database = [pscustomobject]@{
            name = "crmeb"
            tableCount = 157
            schemaSha256 = $SchemaHash
            dataSha256 = $DataHash
            dumpClient = "mysqldump 8.0"
        }
        uploads = [pscustomobject]@{
            fileCount = $Files.Count
            totalBytes = [long](($Files | Measure-Object -Property bytes -Sum).Sum)
            files = $Files
        }
    }
}

function Write-TestSnapshot {
    param(
        [string]$Path,
        [object]$Snapshot
    )

    [IO.File]::WriteAllText(
        $Path,
        ($Snapshot | ConvertTo-Json -Depth 8),
        [Text.UTF8Encoding]::new($false)
    )
}

$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ("runtime-acceptance-tests-" + [guid]::NewGuid().ToString("N"))
$null = New-Item -ItemType Directory -Path $temporaryRoot

try {
    Invoke-Test "reads a valid version-one snapshot" {
        $path = Join-Path $temporaryRoot "valid.json"
        Write-TestSnapshot -Path $path -Snapshot (New-TestSnapshot)
        $snapshot = Read-AcceptanceSnapshot -LiteralPath $path
        Assert-True ($snapshot.database.tableCount -eq 157) "Expected table count 157"
        Assert-True ($snapshot.uploads.fileCount -eq 2) "Expected two upload entries"
    }

    Invoke-Test "rejects a snapshot with a missing database digest" {
        $path = Join-Path $temporaryRoot "missing-digest.json"
        $snapshot = New-TestSnapshot
        $snapshot.database.PSObject.Properties.Remove("dataSha256")
        Write-TestSnapshot -Path $path -Snapshot $snapshot
        Assert-Throws { Read-AcceptanceSnapshot -LiteralPath $path } "dataSha256"
    }

    Invoke-Test "rejects duplicate upload paths" {
        $path = Join-Path $temporaryRoot "duplicate-path.json"
        $files = @(
            [pscustomobject]@{ relativePath = "attach/catalog/item.png"; bytes = 12; sha256 = ("C" * 64) },
            [pscustomobject]@{ relativePath = "attach/catalog/item.png"; bytes = 12; sha256 = ("C" * 64) }
        )
        Write-TestSnapshot -Path $path -Snapshot (New-TestSnapshot -Files $files)
        Assert-Throws { Read-AcceptanceSnapshot -LiteralPath $path } "duplicate"
    }

    Invoke-Test "rejects upload path traversal" {
        $path = Join-Path $temporaryRoot "traversal.json"
        $files = @(
            [pscustomobject]@{ relativePath = "../outside.txt"; bytes = 12; sha256 = ("C" * 64) }
        )
        Write-TestSnapshot -Path $path -Snapshot (New-TestSnapshot -Files $files)
        Assert-Throws { Read-AcceptanceSnapshot -LiteralPath $path } "relativePath"
    }

    Invoke-Test "accepts identical database and upload snapshots" {
        $result = Compare-AcceptanceSnapshot -Expected (New-TestSnapshot) -Actual (New-TestSnapshot)
        Assert-True $result.Passed ($result.Differences -join "; ")
    }

    Invoke-Test "detects a changed schema digest" {
        $result = Compare-AcceptanceSnapshot -Expected (New-TestSnapshot) -Actual (New-TestSnapshot -SchemaHash ("E" * 64))
        Assert-False $result.Passed "Changed schema digest was accepted"
        Assert-True (($result.Differences -join "`n") -match "schema") "Expected schema difference"
    }

    Invoke-Test "detects a changed data digest" {
        $result = Compare-AcceptanceSnapshot -Expected (New-TestSnapshot) -Actual (New-TestSnapshot -DataHash ("F" * 64))
        Assert-False $result.Passed "Changed data digest was accepted"
        Assert-True (($result.Differences -join "`n") -match "data") "Expected data difference"
    }

    Invoke-Test "detects a missing upload" {
        $actualFiles = @((New-TestSnapshot).uploads.files | Select-Object -First 1)
        $result = Compare-AcceptanceSnapshot -Expected (New-TestSnapshot) -Actual (New-TestSnapshot -Files $actualFiles)
        Assert-False $result.Passed "Missing upload was accepted"
        Assert-True (($result.Differences -join "`n") -match "missing") "Expected missing upload difference"
    }

    Invoke-Test "detects an extra upload" {
        $actualFiles = @((New-TestSnapshot).uploads.files) + @(
            [pscustomobject]@{ relativePath = "attach/catalog/item-c.webp"; bytes = 9; sha256 = ("E" * 64) }
        )
        $result = Compare-AcceptanceSnapshot -Expected (New-TestSnapshot) -Actual (New-TestSnapshot -Files $actualFiles)
        Assert-False $result.Passed "Extra upload was accepted"
        Assert-True (($result.Differences -join "`n") -match "extra") "Expected extra upload difference"
    }

    Invoke-Test "detects changed upload bytes and digest" {
        $actualFiles = @(
            [pscustomobject]@{ relativePath = "attach/catalog/item-a.png"; bytes = 13; sha256 = ("F" * 64) },
            (New-TestSnapshot).uploads.files[1]
        )
        $result = Compare-AcceptanceSnapshot -Expected (New-TestSnapshot) -Actual (New-TestSnapshot -Files $actualFiles)
        Assert-False $result.Passed "Changed upload was accepted"
        Assert-True (($result.Differences -join "`n") -match "changed") "Expected changed upload difference"
    }

    $requiredExtensions = @("curl", "redis")
    $validFacts = [pscustomobject]@{
        phpVersion = "8.2.32"
        extensions = @("curl", "redis")
        opcacheLoaded = $true
        opcacheEnabled = $true
        jit = "disable"
        jitBufferSize = 0
    }

    Invoke-Test "accepts valid PHP 8.2 runtime facts" {
        $result = Test-PhpRuntimeFacts -Facts $validFacts -RequiredExtensions $requiredExtensions
        Assert-True $result.Passed ($result.Differences -join "; ")
    }

    Invoke-Test "rejects PHP 7.4 runtime facts" {
        $facts = $validFacts.PSObject.Copy()
        $facts.phpVersion = "7.4.33"
        $result = Test-PhpRuntimeFacts -Facts $facts -RequiredExtensions $requiredExtensions
        Assert-False $result.Passed "PHP 7.4 was accepted"
    }

    Invoke-Test "rejects a missing PHP extension" {
        $facts = $validFacts.PSObject.Copy()
        $facts.extensions = @("curl")
        $result = Test-PhpRuntimeFacts -Facts $facts -RequiredExtensions $requiredExtensions
        Assert-False $result.Passed "Missing Redis extension was accepted"
    }

    Invoke-Test "rejects enabled PHP JIT" {
        $facts = $validFacts.PSObject.Copy()
        $facts.jit = "tracing"
        $facts.jitBufferSize = 1048576
        $result = Test-PhpRuntimeFacts -Facts $facts -RequiredExtensions $requiredExtensions
        Assert-False $result.Passed "Enabled JIT was accepted"
    }

    $supervisorText = @"
php-fpm                         RUNNING   pid 11, uptime 1:02:03
queue                           RUNNING   pid 12, uptime 1:02:03
timer                           RUNNING   pid 13, uptime 1:02:03
workerman                       RUNNING   pid 14, uptime 1:02:03
"@

    Invoke-Test "parses running Supervisor processes" {
        $snapshot = ConvertFrom-SupervisorStatus -Text $supervisorText
        Assert-True ($snapshot["workerman"].Pid -eq 14) "Expected Workerman PID 14"
        Assert-True ($snapshot["php-fpm"].Status -eq "RUNNING") "Expected PHP-FPM RUNNING"
    }

    Invoke-Test "accepts stable Supervisor process identities" {
        $before = ConvertFrom-SupervisorStatus -Text $supervisorText
        $after = ConvertFrom-SupervisorStatus -Text $supervisorText
        $result = Compare-SupervisorSnapshots -Before $before -After $after -ContainerRestartCount 0
        Assert-True $result.Passed ($result.Differences -join "; ")
    }

    Invoke-Test "rejects a changed Supervisor PID" {
        $before = ConvertFrom-SupervisorStatus -Text $supervisorText
        $after = ConvertFrom-SupervisorStatus -Text ($supervisorText -replace "pid 14", "pid 99")
        $result = Compare-SupervisorSnapshots -Before $before -After $after -ContainerRestartCount 0
        Assert-False $result.Passed "Changed Workerman PID was accepted"
    }

    Invoke-Test "rejects a non-running Supervisor process" {
        $before = ConvertFrom-SupervisorStatus -Text $supervisorText
        $after = ConvertFrom-SupervisorStatus -Text ($supervisorText -replace "workerman\s+RUNNING\s+pid 14, uptime 1:02:03", "workerman                       BACKOFF   Exited too quickly")
        $result = Compare-SupervisorSnapshots -Before $before -After $after -ContainerRestartCount 0
        Assert-False $result.Passed "BACKOFF Workerman was accepted"
    }

    Invoke-Test "rejects a restarted PHP container" {
        $snapshot = ConvertFrom-SupervisorStatus -Text $supervisorText
        $result = Compare-SupervisorSnapshots -Before $snapshot -After $snapshot -ContainerRestartCount 1
        Assert-False $result.Passed "Container restart was accepted"
    }

    Invoke-Test "accepts clean runtime logs and the recorded GIF warning" {
        $violations = @(Find-RuntimeLogViolation -Lines @(
            "service ready",
            "Undefined array key 0 in /var/www/vendor/topthink/think-image/src/gif/Encoder.php on line 77"
        ))
        Assert-True ($violations.Count -eq 0) "Expected no log violations"
    }

    foreach ($case in @(
        @{ Name = "fatal errors"; Line = "PHP Fatal error: failed" },
        @{ Name = "type errors"; Line = "TypeError: invalid value" },
        @{ Name = "unexpected deprecations"; Line = "PHP Deprecated: old call" },
        @{ Name = "worker terminations"; Line = "process terminated with exit code 255" }
    )) {
        Invoke-Test "detects $($case.Name) in runtime logs" {
            $violations = @(Find-RuntimeLogViolation -Lines @($case.Line))
            Assert-True ($violations.Count -eq 1) "Expected one log violation"
        }
    }

    Invoke-Test "accepts exact route statuses" {
        $result = Test-RouteStatuses -Statuses @{ storefront = 200; notice = 101 } -Expected @{ storefront = 200; notice = 101 }
        Assert-True $result.Passed ($result.Differences -join "; ")
    }

    Invoke-Test "rejects a non-200 HTTP route" {
        $result = Test-RouteStatuses -Statuses @{ storefront = 500 } -Expected @{ storefront = 200 }
        Assert-False $result.Passed "HTTP 500 was accepted"
    }

    Invoke-Test "rejects a non-101 WebSocket route" {
        $result = Test-RouteStatuses -Statuses @{ notice = 404 } -Expected @{ notice = 101 }
        Assert-False $result.Passed "WebSocket HTTP 404 was accepted"
    }

    Invoke-Test "creates a normalized upload entry from a file" {
        $uploadRoot = Join-Path $temporaryRoot "uploads-entry"
        $uploadDirectory = Join-Path $uploadRoot "attach/catalog"
        $null = New-Item -ItemType Directory -Path $uploadDirectory
        $uploadPath = Join-Path $uploadDirectory "sample.txt"
        [IO.File]::WriteAllText($uploadPath, "sample-content", [Text.UTF8Encoding]::new($false))

        $entry = New-UploadSnapshotEntry -LiteralPath $uploadPath -RootPath $uploadRoot
        Assert-True ($entry.relativePath -eq "attach/catalog/sample.txt") "Expected normalized relative upload path"
        Assert-True ($entry.bytes -eq 14) "Expected exact upload byte count"
        Assert-True ($entry.sha256 -eq (Get-FileHash -LiteralPath $uploadPath -Algorithm SHA256).Hash) "Expected exact upload digest"
    }

    Invoke-Test "imports and sorts the upload hash CSV" {
        $csvPath = Join-Path $temporaryRoot "uploads.csv"
        [IO.File]::WriteAllLines($csvPath, @(
            '"RelativePath","Bytes","SHA256"',
            ('"attach/z-last.jpg","8","' + ("F" * 64) + '"'),
            ('"attach/a-first.png","4","' + ("E" * 64) + '"')
        ), [Text.UTF8Encoding]::new($false))

        $entries = @(Import-UploadHashCsv -LiteralPath $csvPath)
        Assert-True ($entries.Count -eq 2) "Expected two imported upload entries"
        Assert-True ($entries[0].relativePath -eq "attach/a-first.png") "Expected ordinal path sorting"
        Assert-True ($entries[1].bytes -eq 8) "Expected numeric byte values"
    }

    Invoke-Test "rejects upload CSV files with unsupported columns" {
        $csvPath = Join-Path $temporaryRoot "bad-uploads.csv"
        [IO.File]::WriteAllText($csvPath, '"Path","Length","Hash"', [Text.UTF8Encoding]::new($false))
        Assert-Throws { Import-UploadHashCsv -LiteralPath $csvPath } "RelativePath"
    }

    Invoke-Test "constructs a manifest from SQL digests and upload entries" {
        $schemaPath = Join-Path $temporaryRoot "schema.sql"
        $dataPath = Join-Path $temporaryRoot "data.sql"
        [IO.File]::WriteAllText($schemaPath, "CREATE TABLE sample (id int);`n", [Text.UTF8Encoding]::new($false))
        [IO.File]::WriteAllText($dataPath, "INSERT INTO sample VALUES (1);`n", [Text.UTF8Encoding]::new($false))
        $entries = @(
            [pscustomobject]@{ relativePath = "attach/b.jpg"; bytes = 8; sha256 = ("F" * 64) },
            [pscustomobject]@{ relativePath = "attach/a.png"; bytes = 4; sha256 = ("E" * 64) }
        )

        $manifest = New-AcceptanceSnapshotManifest `
            -DatabaseName "crmeb" `
            -TableCount 157 `
            -SchemaPath $schemaPath `
            -DataPath $dataPath `
            -DumpClient "mysqldump 8.0.45" `
            -UploadEntries $entries `
            -CreatedAtUtc ([DateTimeOffset]::Parse("2026-07-30T00:00:00Z"))

        Assert-True ($manifest.database.schemaSha256 -eq (Get-FileHash -LiteralPath $schemaPath -Algorithm SHA256).Hash) "Expected schema digest"
        Assert-True ($manifest.database.dataSha256 -eq (Get-FileHash -LiteralPath $dataPath -Algorithm SHA256).Hash) "Expected data digest"
        Assert-True ($manifest.uploads.fileCount -eq 2) "Expected upload file count"
        Assert-True ($manifest.uploads.totalBytes -eq 12) "Expected upload byte total"
        Assert-True ($manifest.uploads.files[0].relativePath -eq "attach/a.png") "Expected sorted manifest uploads"
    }

    Invoke-Test "detects a one-byte database fixture change" {
        $schemaPath = Join-Path $temporaryRoot "byte-schema.sql"
        $dataPath = Join-Path $temporaryRoot "byte-data.sql"
        [IO.File]::WriteAllText($schemaPath, "CREATE TABLE sample (id int);`n", [Text.UTF8Encoding]::new($false))
        [IO.File]::WriteAllText($dataPath, "INSERT INTO sample VALUES (1);`n", [Text.UTF8Encoding]::new($false))
        $expected = New-AcceptanceSnapshotManifest -DatabaseName "crmeb" -TableCount 1 -SchemaPath $schemaPath -DataPath $dataPath -DumpClient "mysqldump 8.0" -UploadEntries @()

        [IO.File]::WriteAllText($dataPath, "INSERT INTO sample VALUES (2);`n", [Text.UTF8Encoding]::new($false))
        $actual = New-AcceptanceSnapshotManifest -DatabaseName "crmeb" -TableCount 1 -SchemaPath $schemaPath -DataPath $dataPath -DumpClient "mysqldump 8.0" -UploadEntries @()
        $result = Compare-AcceptanceSnapshot -Expected $expected -Actual $actual
        Assert-False $result.Passed "One-byte database change was accepted"
        Assert-True (($result.Differences -join "`n") -match "dataSha256") "Expected data digest difference"
    }

    Invoke-Test "detects a one-byte upload fixture change" {
        $uploadRoot = Join-Path $temporaryRoot "byte-uploads"
        $null = New-Item -ItemType Directory -Path $uploadRoot
        $uploadPath = Join-Path $uploadRoot "sample.bin"
        [IO.File]::WriteAllBytes($uploadPath, [byte[]](1, 2, 3))
        $expectedEntry = New-UploadSnapshotEntry -LiteralPath $uploadPath -RootPath $uploadRoot

        [IO.File]::WriteAllBytes($uploadPath, [byte[]](1, 2, 4))
        $actualEntry = New-UploadSnapshotEntry -LiteralPath $uploadPath -RootPath $uploadRoot
        $result = Compare-AcceptanceSnapshot `
            -Expected (New-TestSnapshot -Files @($expectedEntry)) `
            -Actual (New-TestSnapshot -Files @($actualEntry))
        Assert-False $result.Passed "One-byte upload change was accepted"
        Assert-True (($result.Differences -join "`n") -match "upload changed") "Expected changed upload difference"
    }

    Invoke-Test "writes a valid manifest and refuses to overwrite it" {
        $outputDirectory = Join-Path $temporaryRoot "manifest-output"
        $schemaPath = Join-Path $temporaryRoot "write-schema.sql"
        $dataPath = Join-Path $temporaryRoot "write-data.sql"
        [IO.File]::WriteAllText($schemaPath, "schema", [Text.UTF8Encoding]::new($false))
        [IO.File]::WriteAllText($dataPath, "data", [Text.UTF8Encoding]::new($false))
        $manifest = New-AcceptanceSnapshotManifest `
            -DatabaseName "crmeb" `
            -TableCount 1 `
            -SchemaPath $schemaPath `
            -DataPath $dataPath `
            -DumpClient "mysqldump 8.0" `
            -UploadEntries @() `
            -CreatedAtUtc ([DateTimeOffset]::Parse("2026-07-30T00:00:00Z"))

        $manifestPath = Write-AcceptanceSnapshotManifest -Manifest $manifest -OutputDirectory $outputDirectory
        Assert-True (Test-Path -LiteralPath $manifestPath -PathType Leaf) "Expected a written manifest"
        $written = Read-AcceptanceSnapshot -LiteralPath $manifestPath
        Assert-True ($written.database.tableCount -eq 1) "Expected readable written manifest"
        Assert-Throws { Write-AcceptanceSnapshotManifest -Manifest $manifest -OutputDirectory $outputDirectory } "already exists"
        Assert-True (@(Get-ChildItem -LiteralPath $outputDirectory -Filter "*.tmp" -File).Count -eq 0) "Expected no temporary manifest file"
    }

    $verifierPath = Join-Path $PSScriptRoot "../verify-optimized.ps1"
    $verifierSource = Get-Content -LiteralPath $verifierPath -Raw

    Invoke-Test "requires an explicit snapshot manifest without hard-coded demo counts" {
        Assert-True ($verifierSource -match '(?s)\[Parameter\(Mandatory\)\]\s*\[string\]\$SnapshotManifest') "Expected mandatory SnapshotManifest parameter"
        Assert-False ($verifierSource -match '\$ExpectedProducts|\$ExpectedUsers|\$ExpectedUploads') "Expected hard-coded demo-count parameters to be removed"
        Assert-False ($verifierSource -match '\(\s*if\s*\(') "Expected conditional details to be assigned before function arguments"
        Assert-True ($verifierSource -match 'Compare-AcceptanceSnapshot') "Expected snapshot comparison"
    }

    Invoke-Test "checks facts inside the deployed PHP container" {
        Assert-True ($verifierSource -match 'Test-PhpRuntimeFacts') "Expected deployed PHP fact validation"
        Assert-True ($verifierSource -match 'jitBufferSize') "Expected JIT buffer validation"
        Assert-True ($verifierSource -match 'bcmath.+curl.+fileinfo.+gd') "Expected required extension list"
    }

    Invoke-Test "samples Supervisor twice and scans new runtime logs" {
        Assert-True ($verifierSource -match 'Compare-SupervisorSnapshots') "Expected Supervisor snapshot comparison"
        Assert-True ($verifierSource -match '(?s)\$initialProcessProblems\s*=\s*@\(\s*@\(.+?Where-Object.+?\}\s*\)') "Expected process problems to remain an array under strict mode"
        Assert-True ($verifierSource -match 'Start-Sleep\s+-Seconds\s+5') "Expected a bounded process sampling interval"
        Assert-True ($verifierSource -match '(?s)logs.+--since') "Expected logs bounded by acceptance start time"
        Assert-True ($verifierSource -match 'Find-RuntimeLogViolation') "Expected runtime log classification"
    }

    Invoke-Test "performs real upload I/O with guaranteed cleanup" {
        Assert-True ($verifierSource -match 'mktemp') "Expected a unique upload probe file"
        Assert-True ($verifierSource -match '(?s)--user.+www-data') "Expected upload I/O as www-data"
        Assert-True ($verifierSource -match '(?s)trap.+rm') "Expected shell cleanup trap"
    }

    Invoke-Test "requires representative HTTP routes and exact WebSocket upgrades" {
        foreach ($path in @('/', '/admin/', '/pages/index/index', '/api/index')) {
            Assert-True ($verifierSource.Contains($path)) "Expected route check for $path"
        }
        Assert-True ($verifierSource -match 'notice\s*=\s*101') "Expected notice WebSocket status 101"
        Assert-True ($verifierSource -match 'msg\s*=\s*101') "Expected msg WebSocket status 101"
        Assert-True ($verifierSource -match 'Test-RouteStatuses') "Expected exact route-status validation"
    }

    $php82VerifierSource = Get-Content -LiteralPath (Join-Path $PSScriptRoot "../verify-php82.ps1") -Raw
    Invoke-Test "normalizes Windows line endings before Docker shell execution" {
        Assert-True ($php82VerifierSource -match '(?s)\$normalizedArguments\s*=.+?Replace\("`r`n",\s*"`n"\)') "Expected CRLF normalization at the Docker boundary"
        Assert-True ($php82VerifierSource -match '& docker @normalizedArguments') "Expected Docker to receive normalized arguments"
    }
} finally {
    Remove-Item -LiteralPath $temporaryRoot -Recurse -Force -ErrorAction SilentlyContinue
}

"Tests passed: $script:passed; failed: $script:failed"
if ($script:failed) {
    exit 1
}
