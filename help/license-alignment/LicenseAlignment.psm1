Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:RestrictiveLicenseText = 'Licensed CRMEB' + '并不是自由软件，未经许可不能去掉CRMEB相关版权'
$script:ApacheLicenseHeaderText = 'Licensed under the Apache License, Version 2.0'
$script:ExcludedProjectPrefixes = @(
    'crmeb/vendor/',
    'template/admin/node_modules/',
    'template/admin/dist/',
    'crmeb/public/admin/',
    'crmeb/public/static/',
    'crmeb/public/statics/',
    '.codegraph/',
    '.codex/',
    '.worktrees/',
    'docs/superpowers/specs/',
    'docs/superpowers/plans/',
    'openspec/'
)

function Resolve-RepositoryRoot {
    param([Parameter(Mandatory)][string]$RepositoryRoot)

    $resolved = (Resolve-Path -LiteralPath $RepositoryRoot -ErrorAction Stop).Path
    if (-not (Test-Path -LiteralPath (Join-Path $resolved '.git'))) {
        throw "Repository root does not contain .git: $resolved"
    }
    $resolved
}

function Invoke-GitFileList {
    param([Parameter(Mandatory)][string]$RepositoryRoot)

    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = 'git'
    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.CreateNoWindow = $true
    $startInfo.ArgumentList.Add('-C')
    $startInfo.ArgumentList.Add($RepositoryRoot)
    $startInfo.ArgumentList.Add('ls-files')
    $startInfo.ArgumentList.Add('-z')

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    if (-not $process.Start()) {
        throw 'Unable to start git ls-files'
    }

    $output = $process.StandardOutput.ReadToEnd()
    $errorOutput = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) {
        throw "git ls-files failed: $errorOutput"
    }

    [string[]]$output.Split([char]0, [StringSplitOptions]::RemoveEmptyEntries)
}

function Test-ExcludedProjectPath {
    param([Parameter(Mandatory)][string]$RelativePath)

    foreach ($prefix in $script:ExcludedProjectPrefixes) {
        if ($RelativePath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
            return $true
        }
    }
    return $false
}

function Get-ProjectOwnedTrackedFiles {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$RepositoryRoot)

    $root = Resolve-RepositoryRoot -RepositoryRoot $RepositoryRoot
    foreach ($path in Invoke-GitFileList -RepositoryRoot $root) {
        $normalized = $path.Replace('\', '/')
        if (-not (Test-ExcludedProjectPath -RelativePath $normalized)) {
            $normalized
        }
    }
}

function Get-ByteSequenceOffsets {
    param(
        [Parameter(Mandatory)][AllowEmptyCollection()][byte[]]$Bytes,
        [Parameter(Mandatory)][byte[]]$Pattern
    )

    if ($Pattern.Length -eq 0 -or $Bytes.Length -lt $Pattern.Length) {
        return [int[]]@()
    }

    $offsets = [Collections.Generic.List[int]]::new()
    $lastStart = $Bytes.Length - $Pattern.Length
    for ($offset = 0; $offset -le $lastStart; $offset++) {
        if ($Bytes[$offset] -ne $Pattern[0]) {
            continue
        }

        $matches = $true
        for ($index = 1; $index -lt $Pattern.Length; $index++) {
            if ($Bytes[$offset + $index] -ne $Pattern[$index]) {
                $matches = $false
                break
            }
        }
        if ($matches) {
            $offsets.Add($offset)
            $offset += $Pattern.Length - 1
        }
    }

    [int[]]$offsets.ToArray()
}

function Get-RestrictiveLicenseHeaderMatches {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$RepositoryRoot)

    $root = Resolve-RepositoryRoot -RepositoryRoot $RepositoryRoot
    $oldBytes = [Text.Encoding]::UTF8.GetBytes($script:RestrictiveLicenseText)
    foreach ($relativePath in Get-ProjectOwnedTrackedFiles -RepositoryRoot $root) {
        $literalPath = Join-Path $root ($relativePath.Replace('/', [IO.Path]::DirectorySeparatorChar))
        if (-not (Test-Path -LiteralPath $literalPath -PathType Leaf)) {
            continue
        }

        $bytes = [IO.File]::ReadAllBytes($literalPath)
        $offsets = @(Get-ByteSequenceOffsets -Bytes $bytes -Pattern $oldBytes)
        if ($offsets.Count -gt 0) {
            [pscustomobject]@{
                RelativePath = $relativePath
                LiteralPath = $literalPath
                MatchCount = $offsets.Count
                Length = $bytes.Length
            }
        }
    }
}

function Replace-RestrictiveLicenseHeader {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string]$LiteralPath,
        [switch]$CheckOnly
    )

    $resolved = (Resolve-Path -LiteralPath $LiteralPath -ErrorAction Stop).Path
    $bytes = [IO.File]::ReadAllBytes($resolved)
    $oldBytes = [Text.Encoding]::UTF8.GetBytes($script:RestrictiveLicenseText)
    $newBytes = [Text.Encoding]::UTF8.GetBytes($script:ApacheLicenseHeaderText)
    $offsets = @(Get-ByteSequenceOffsets -Bytes $bytes -Pattern $oldBytes)

    if ($offsets.Count -eq 0) {
        return [pscustomobject]@{
            LiteralPath = $resolved
            MatchCount = 0
            Changed = $false
        }
    }
    if ($offsets.Count -ne 1) {
        throw "Expected exactly one restrictive license header in $resolved, found $($offsets.Count)"
    }
    if ($CheckOnly) {
        return [pscustomobject]@{
            LiteralPath = $resolved
            MatchCount = 1
            Changed = $false
        }
    }

    $offset = $offsets[0]
    $stream = [IO.MemoryStream]::new($bytes.Length - $oldBytes.Length + $newBytes.Length)
    try {
        $stream.Write($bytes, 0, $offset)
        $stream.Write($newBytes, 0, $newBytes.Length)
        $tailOffset = $offset + $oldBytes.Length
        $stream.Write($bytes, $tailOffset, $bytes.Length - $tailOffset)
        [IO.File]::WriteAllBytes($resolved, $stream.ToArray())
    } finally {
        $stream.Dispose()
    }

    [pscustomobject]@{
        LiteralPath = $resolved
        MatchCount = 1
        Changed = $true
    }
}

Export-ModuleMember -Function @(
    'Get-ProjectOwnedTrackedFiles',
    'Get-RestrictiveLicenseHeaderMatches',
    'Replace-RestrictiveLicenseHeader'
)
