[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$modulePath = Join-Path $PSScriptRoot '../LicenseAlignment.psm1'
Import-Module -Name $modulePath -Force -DisableNameChecking

$script:passed = 0
$script:failed = 0

function Invoke-Test {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][scriptblock]$Body
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

function Assert-Throws {
    param(
        [Parameter(Mandatory)][scriptblock]$Body,
        [Parameter(Mandatory)][string]$Pattern
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

$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ('license-alignment-tests-' + [guid]::NewGuid().ToString('N'))
$null = New-Item -ItemType Directory -Path $temporaryRoot
$legacyHeader = 'Licensed CRMEB' + '并不是自由软件，未经许可不能去掉CRMEB相关版权'

try {
    Invoke-Test 'enumerates project-owned tracked files without dependencies or generated output' {
        $tracked = @(Get-ProjectOwnedTrackedFiles -RepositoryRoot $repositoryRoot)
        Assert-True ($tracked -contains 'crmeb/app/common.php') 'known project-owned source was not enumerated'
        Assert-True ($tracked -notcontains 'crmeb/vendor/example.php') 'vendor file entered project-owned scope'
        Assert-True ($tracked -notcontains 'docs/superpowers/specs/history.md') 'historical specification entered runtime scope'
        Assert-True (-not @($tracked | Where-Object { $_ -like 'crmeb/public/admin/*' }).Count) 'admin build entered project-owned scope'
        Assert-True (-not @($tracked | Where-Object { $_ -like 'crmeb/public/static/*' }).Count) 'H5 build entered project-owned scope'
        Assert-True (-not @($tracked | Where-Object { $_ -like 'crmeb/public/statics/*' }).Count) 'mini-program build entered project-owned scope'
    }

    Invoke-Test 'preserves UTF-8 BOM and CRLF around one exact replacement' {
        $fixture = Join-Path $temporaryRoot 'bom-crlf.php'
        $input = "line1`r`n$legacyHeader`r`nline3`r`n"
        $encoding = [Text.UTF8Encoding]::new($true)
        [IO.File]::WriteAllBytes($fixture, [byte[]]($encoding.GetPreamble() + $encoding.GetBytes($input)))

        $result = Replace-RestrictiveLicenseHeader -LiteralPath $fixture
        $bytes = [IO.File]::ReadAllBytes($fixture)
        $text = [Text.Encoding]::UTF8.GetString($bytes)

        Assert-True ($result.MatchCount -eq 1 -and $result.Changed) 'single match was not replaced'
        Assert-True ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) 'UTF-8 BOM changed'
        Assert-True ($text.Contains("line1`r`nLicensed under the Apache License, Version 2.0`r`nline3")) 'content or CRLF changed unexpectedly'
    }

    Invoke-Test 'check-only mode reports a match without changing bytes' {
        $fixture = Join-Path $temporaryRoot 'check-only.php'
        [IO.File]::WriteAllText(
            $fixture,
            "before`n$legacyHeader`nafter`n",
            [Text.UTF8Encoding]::new($false)
        )
        $before = [Convert]::ToHexString([IO.File]::ReadAllBytes($fixture))
        $result = Replace-RestrictiveLicenseHeader -LiteralPath $fixture -CheckOnly
        $after = [Convert]::ToHexString([IO.File]::ReadAllBytes($fixture))

        Assert-True ($result.MatchCount -eq 1 -and -not $result.Changed) 'check-only result was incorrect'
        Assert-True ($before -ceq $after) 'check-only mode changed the file'
    }

    Invoke-Test 'refuses an ambiguous file with multiple restrictive headers' {
        $fixture = Join-Path $temporaryRoot 'multiple.php'
        [IO.File]::WriteAllText($fixture, "$legacyHeader`n$legacyHeader`n", [Text.UTF8Encoding]::new($false))
        Assert-Throws { Replace-RestrictiveLicenseHeader -LiteralPath $fixture } 'exactly one'
    }

    Invoke-Test 'does not count the tracked verification harness as a restrictive source header' {
        $harnessMatches = @(Get-RestrictiveLicenseHeaderMatches -RepositoryRoot $repositoryRoot |
            Where-Object { $_.RelativePath -like 'help/license-alignment/*' })
        Assert-True ($harnessMatches.Count -eq 0) 'verification harness entered the restrictive-header inventory'
    }

    Invoke-Test 'leaves a file with no restrictive header unchanged' {
        $fixture = Join-Path $temporaryRoot 'unchanged.php'
        [IO.File]::WriteAllText($fixture, "ordinary content`n", [Text.UTF8Encoding]::new($false))
        $before = [Convert]::ToHexString([IO.File]::ReadAllBytes($fixture))
        $result = Replace-RestrictiveLicenseHeader -LiteralPath $fixture -CheckOnly
        $after = [Convert]::ToHexString([IO.File]::ReadAllBytes($fixture))

        Assert-True ($result.MatchCount -eq 0 -and -not $result.Changed) 'zero-match result was incorrect'
        Assert-True ($before -ceq $after) 'zero-match check changed the file'
    }

    Invoke-Test 'accepts an empty tracked file as a zero-match file' {
        $fixture = Join-Path $temporaryRoot 'empty.txt'
        [IO.File]::WriteAllBytes($fixture, [byte[]]@())
        $result = Replace-RestrictiveLicenseHeader -LiteralPath $fixture -CheckOnly
        Assert-True ($result.MatchCount -eq 0 -and -not $result.Changed) 'empty file did not return a zero-match result'
    }
} finally {
    Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
}

"Tests: $($script:passed) passed, $($script:failed) failed"
if ($script:failed -gt 0) {
    exit 1
}
