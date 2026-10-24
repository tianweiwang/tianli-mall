$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-UniBuildInputs {
    param([string]$PrepRoot, [string]$ExternalStylusPlugin)
    $manifestPath = Join-Path $PrepRoot 'source-manifest.json'
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    if ($manifest.source_sha -cne 'bfe311cbb727af093fe73fb4ce22ead58e8f3345' -or $manifest.source_repo -cne 'H:/天俪商城/worktrees/product-typed-media-normal' -or $manifest.tracked_count -ne 559 -or $manifest.source_identity.identity_sha256 -cne '6316a2b7729663dd397de75a832a221b54a690e6964ce44264b0cb266ea821bd') { throw 'Wrong current source binding.' }
    $sourceRoot = [IO.Path]::GetFullPath((Join-Path $PrepRoot 'source'))
    foreach ($entry in $manifest.entries) {
        if (-not $entry.path.StartsWith('template/uni-app/', [StringComparison]::Ordinal) -or
            $entry.path.Contains('..')) { throw 'Unexpected snapshot input path.' }
        $copy = Join-Path $sourceRoot $entry.path
        $repo = Join-Path $manifest.source_repo $entry.path
        foreach ($path in @($copy, $repo)) {
            if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $entry.sha256) {
                throw "Snapshot input has changed: $($entry.path)"
            }
        }
    }
    $plugins = 'E:/HBuilderX/plugins'
    foreach ($name in @('uniapp-cli', 'about', 'compile-dart-sass', 'uni_helpers')) {
        if (-not (Test-Path -LiteralPath (Join-Path $plugins $name) -PathType Container)) {
            throw "Installed compiler directory is missing: $name"
        }
    }
    $stylusPlugin = [IO.Path]::GetFullPath($ExternalStylusPlugin)
    $stylusPackage = Join-Path $stylusPlugin 'node_modules/stylus/package.json'
    if (-not (Test-Path -LiteralPath $stylusPackage -PathType Leaf)) {
        throw 'External compile-stylus dependency is not prepared; no compiler or container has been started.'
    }
    $pkg = Get-Content -LiteralPath $stylusPackage -Raw | ConvertFrom-Json
    if ($pkg.name -cne 'stylus' -or $pkg.version -notmatch '^\d+\.\d+\.\d+$' -or
        [Version]$pkg.version -lt [Version]'0.52.4') {
        throw 'External Stylus does not satisfy the installed loader peer constraint >=0.52.4.'
    }
    foreach ($rel in @('lib/visitor/evaluator.js', 'lib/nodes/index.js', 'lib/utils.js')) {
        if (-not (Test-Path -LiteralPath (Join-Path $stylusPlugin "node_modules/stylus/$rel"))) {
            throw 'External Stylus is missing an internal module used by the installed loader.'
        }
    }
    $bindingPath = Join-Path $PrepRoot 'worktree-binding.json'
    $binding = Get-Content -LiteralPath $bindingPath -Raw | ConvertFrom-Json
    if ($binding.base_head -cne $manifest.source_sha -or $binding.source_repo -cne $manifest.source_repo -or
        @($binding.files).Count -ne 27 -or $binding.source_identity.identity_sha256 -cne $manifest.source_identity.identity_sha256) { throw 'Wrong controlled worktree binding.' }
    foreach ($entry in $binding.files) {
        if ($entry.path.Contains('..') -or [IO.Path]::IsPathRooted($entry.path)) { throw 'Unexpected controlled path.' }
        $path = Join-Path $manifest.source_repo $entry.path
        if ((Get-Item -LiteralPath $path).Length -ne $entry.bytes -or
            (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $entry.sha256) { throw 'Controlled worktree final bytes changed.' }
    }
    $fixedStylus = Get-Content -LiteralPath (Join-Path $PrepRoot 'stylus-inputs.json') -Raw | ConvertFrom-Json
    if ($fixedStylus.root -cne $stylusPlugin.Replace('\','/') -or $fixedStylus.installed_packages -ne 28 -or @($fixedStylus.files).Count -ne 30) { throw 'Wrong fixed Stylus input set.' }
    foreach ($entry in $fixedStylus.files) {
        if ($entry.path.Contains('..') -or [IO.Path]::IsPathRooted($entry.path)) { throw 'Unexpected fixed Stylus path.' }
        $path = Join-Path $stylusPlugin $entry.path
        if ((Get-Item -LiteralPath $path).Length -ne $entry.bytes -or
            (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $entry.sha256) { throw 'Fixed Stylus input changed.' }
        if ($entry.kind -ceq 'installed_package') {
            $installed = Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
            if ($installed.version -cne $entry.version) { throw 'Locked installed Stylus dependency version changed.' }
        }
    }
    return [pscustomobject]@{
        Source = Join-Path $sourceRoot 'template/uni-app'
        Plugins = $plugins
        StylusPlugin = $stylusPlugin
        StylusVersion = $pkg.version
        ManifestSha256 = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
        SourceSha = $manifest.source_sha
        SourceIdentity = $manifest.source_identity
        WorktreeBinding = $binding
    }
}


function Invoke-UniNative {
    param([string]$Label, [string[]]$Arguments, [string]$Executable = 'C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe', [switch]$AllowNativeFailure, [switch]$AllowStderr)
    $script:UniNativeIndex++
    $stem = '{0:d3}-{1}' -f $script:UniNativeIndex, $Label
    $argumentPath = Join-Path $script:UniEvidence ($stem + '.arguments.json')
    [string[]]$argv = @($Executable) + $Arguments
    [IO.File]::WriteAllText($argumentPath, (ConvertTo-Json -InputObject $argv -Compress), [Text.UTF8Encoding]::new($false))
    $text = & 'C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe' -B -X utf8 (Join-Path $PSScriptRoot 'native.py') $argumentPath
    if ($LASTEXITCODE -ne 0) { throw 'Native receipt helper did not complete.' }
    $script:LastUniNative = $text | ConvertFrom-Json
    $script:LastUniNativeExit = $script:LastUniNative.native_exit
    $script:UniNativeRecords.Add($script:LastUniNative)
    if ($script:LastUniNative.pid -le 0 -or $script:LastUniNative.native_exit -ne $script:LastUniNative.wait_returncode -or
        (-not $AllowNativeFailure -and $script:LastUniNativeExit -ne 0) -or
        (-not $AllowStderr -and $script:LastUniNative.stderr.bytes -ne 0)) { throw 'Actual native PID/exit/streams qualification failed.' }
    foreach ($channel in @('stdout','stderr')) {
        $stream = $script:LastUniNative.$channel
        if ((Get-Item -LiteralPath $stream.path).Length -ne $stream.bytes -or
            (Get-FileHash -LiteralPath $stream.path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $stream.sha256) { throw 'Complete native stream mismatch.' }
    }
    return [IO.File]::ReadAllText($script:LastUniNative.stdout.path)
}
