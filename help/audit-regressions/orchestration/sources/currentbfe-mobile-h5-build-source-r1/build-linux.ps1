param(
    [Parameter(Mandatory=$true)][ValidatePattern('^[0-9]{14}[a-f0-9]{8}$')][string]$RunId,
    [string]$ExternalStylusPlugin = 'H:/天俪商城/artifacts/ms1-task7/uni-h5-build-prep/dependency-plugins/compile-stylus'
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'build-common.ps1')
$runBase = 'H:/天俪商城/artifacts/currentbfe-mobile-h5-build-runs'
$runRoot = Join-Path $runBase $RunId
if (Test-Path -LiteralPath $runRoot) { throw 'A build Run cannot be resumed or overwritten.' }
[void](New-Item -ItemType Directory -Path $runRoot)
$output = Join-Path $runRoot 'output'
$evidence = Join-Path $runRoot 'evidence'
$runtimeInput = Join-Path $runRoot 'input'
foreach ($folder in @($output,$evidence,$runtimeInput)) { [void](New-Item -ItemType Directory -Path $folder) }
$script:UniEvidence = $evidence
$script:UniNativeIndex = 0
$script:UniNativeRecords = [Collections.Generic.List[object]]::new()
$inputs = Get-UniBuildInputs -PrepRoot $PSScriptRoot -ExternalStylusPlugin $ExternalStylusPlugin
$gitBase = @('-c','safe.directory=H:/天俪商城/worktrees/product-typed-media-normal','-C','H:/天俪商城/worktrees/product-typed-media-normal')
if ((Invoke-UniNative -Label 'source-head' -Executable 'git' -Arguments ($gitBase + @('rev-parse','HEAD'))).Trim() -cne 'bfe311cbb727af093fe73fb4ce22ead58e8f3345') { throw 'Actual source HEAD differs.' }
if ((Invoke-UniNative -Label 'source-controlled-worktree' -Executable 'git' -Arguments ($gitBase + @('status','--porcelain=v1','--untracked-files=all'))) -cne $inputs.WorktreeBinding.expected_status_porcelain_v1) { throw 'Actual controlled source state differs.' }
if ((Invoke-UniNative -Label 'source-branch' -Executable 'git' -Arguments ($gitBase + @('rev-parse','--abbrev-ref','HEAD'))).Trim() -cne $inputs.WorktreeBinding.branch) { throw 'Actual controlled source branch differs.' }
$actualTracked = (Invoke-UniNative -Label 'source-paths' -Executable 'git' -Arguments ($gitBase + @('ls-files','-z','--','template/uni-app'))).TrimEnd([char]0).Split([char]0)
$copyManifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'source-manifest.json') -Raw | ConvertFrom-Json
if (($actualTracked -join "`n") -cne (($copyManifest.entries | ForEach-Object {$_.path}) -join "`n")) { throw 'Complete tracked source namespace differs.' }
foreach ($entry in $copyManifest.entries) {
    $relative = $entry.path.Substring('template/uni-app/'.Length)
    $target = Join-Path $runtimeInput $relative
    [void][IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target))
    [IO.File]::WriteAllBytes($target,[IO.File]::ReadAllBytes((Join-Path (Join-Path $PSScriptRoot 'source') $entry.path)))
    if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant() -cne $entry.sha256) { throw 'Runtime source copy differs.' }
}

$adapter = Join-Path $PSScriptRoot 'explicit-pass-preload.cjs'
$adapterHash = (Get-FileHash -LiteralPath $adapter -Algorithm SHA256).Hash.ToLowerInvariant()
$sourceManifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'source-manifest.json') -Raw | ConvertFrom-Json
if (@(Get-ChildItem -LiteralPath $inputs.Source -Force -File -Recurse).Count -ne $sourceManifest.tracked_count) {
    throw 'The independent source copy has unexpected additional input files.'
}
$optimizeReceiptPath = Join-Path $PSScriptRoot 'optimize-overlay-manifest.json'
$optimizeReceipt = Get-Content -LiteralPath $optimizeReceiptPath -Raw | ConvertFrom-Json
$optimizeOverlay = Join-Path $PSScriptRoot 'compiler-overlays/vue-cli-plugin-uni-optimize'
foreach ($entry in $optimizeReceipt.entries) {
    foreach ($root in @($optimizeReceipt.source_package, $optimizeOverlay)) {
        if ((Get-FileHash -LiteralPath (Join-Path $root $entry.path) -Algorithm SHA256).Hash.ToLowerInvariant() -cne $entry.sha256) {
            throw 'Optimize package source/overlay hash changed.'
        }
    }
}
if (-not (Test-Path -LiteralPath (Join-Path $optimizeOverlay '.tmp') -PathType Container) -or
    @(Get-ChildItem -LiteralPath (Join-Path $optimizeOverlay '.tmp') -Force).Count -ne 0) {
    throw 'The external optimize cache mountpoint must exist and remain empty.'
}
$image = 'sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293'
$imageId = Invoke-UniNative -Label 'image-inspect' -Arguments @('image','inspect','--format','{{.Id}}',$image)
if ($LASTEXITCODE -ne 0 -or $imageId.Trim() -cne $image) { throw 'Pinned local Node image is unavailable.' }
$stamp = $RunId
$dockerArguments = @('create', '--pull=never', '--name', "tianli-mobile-product-image-h5-$stamp",
    '--label','tianli.audit.purecheck=ms4-mobile-product-image-h5-build','--label',"tianli.audit.build=$RunId",
    '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
    '--tmpfs', '/tmp:rw,nosuid,nodev,size=1073741824',
    '--tmpfs', '/.automator:rw,nosuid,nodev,size=1048576',
    '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/.cache:rw,nosuid,nodev,size=268435456',
    '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize/.tmp:rw,nosuid,nodev,size=16777216',
    '--workdir', '/hbx/plugins/uniapp-cli',
    '--env', 'NODE_ENV=production', '--env', 'UNI_PLATFORM=h5', '--env', 'UNI_MINIMIZE=true',
    '--env', 'CHOKIDAR_USEPOLLING=true', '--env', 'CHOKIDAR_INTERVAL=250',
    '--env', 'UNI_INPUT_DIR=/input', '--env', 'UNI_OUTPUT_DIR=/output',
    '--env', 'VUE_CLI_CONTEXT=/hbx/plugins/uniapp-cli', '--env', 'UNI_HBUILDERX_PLUGINS=/hbx/plugins',
    '--env', 'HOME=/tmp', '--env', 'TMPDIR=/tmp',
    '--mount', "type=bind,source=$runtimeInput,target=/input",
    '--mount', "type=bind,source=$output,target=/output",
    '--mount', "type=bind,source=$evidence,target=/evidence",
    '--mount', "type=bind,source=$adapter,target=/tool/explicit-pass-preload.cjs,readonly")
# The parent /hbx/plugins is container-only. Do not create a missing child in the installed RO directory.
foreach ($name in @('uniapp-cli', 'about', 'compile-dart-sass', 'uni_helpers')) {
    $dockerArguments += @('--mount', "type=bind,source=$($inputs.Plugins)/$name,target=/hbx/plugins/$name,readonly")
}
# Mount the full external plugin so Stylus dependencies beside the package remain resolvable.
$dockerArguments += @('--mount', "type=bind,source=$($inputs.StylusPlugin),target=/hbx/plugins/compile-stylus,readonly",
    '--mount', "type=bind,source=$optimizeOverlay,target=/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize,readonly",
    $image, 'node', '--require', '/tool/explicit-pass-preload.cjs', '/hbx/plugins/uniapp-cli/bin/uniapp-cli.js')
$containerId = (Invoke-UniNative -Label 'container-create' -Arguments $dockerArguments).Trim()
if ($LASTEXITCODE -ne 0 -or $containerId -notmatch '^[a-f0-9]{64}$') { throw 'Build container creation failed.' }
try {
    [void](Invoke-UniNative -Label 'compiler-attach' -Arguments @('start','--attach',$containerId) -AllowNativeFailure -AllowStderr)
    $attachExit = $script:LastUniNativeExit
    $compilerNative = $script:LastUniNative
    $compilerNative | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $evidence 'compiler-native.json') -Encoding utf8
    $stateText = Invoke-UniNative -Label 'container-terminal' -Arguments @('inspect','--format','{{json .State}}',$containerId)
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the owned build container.' }
    $state = $stateText | ConvertFrom-Json
    [void](Get-UniBuildInputs -PrepRoot $PSScriptRoot -ExternalStylusPlugin $ExternalStylusPlugin)
    if ((Get-FileHash -LiteralPath $adapter -Algorithm SHA256).Hash.ToLowerInvariant() -cne $adapterHash -or
        @(Get-ChildItem -LiteralPath $inputs.Source -Force -File -Recurse).Count -ne $sourceManifest.tracked_count) {
        throw 'Adapter or full source inputs changed during build.'
    }
    $result = [ordered]@{
        status = 'BUILD_FAILED'
        source_sha = $inputs.SourceSha; source_manifest_sha256 = $inputs.ManifestSha256
        source_base_head = $inputs.SourceSha; source_identity = $inputs.SourceIdentity
        contains_uncommitted_changes = $true; exact_controlled_worktree_files = 27
        image_id = $image; container_id = $containerId; stylus_version = $inputs.StylusVersion
        watch_configuration = [ordered]@{ chokidar_usepolling = $true; chokidar_interval_ms = 250 }
        adapter_sha256 = $adapterHash
        adapter_observation_valid = $false; dist_matches_after_emit = $false
        optimize_overlay_manifest_sha256 = (Get-FileHash -LiteralPath $optimizeReceiptPath -Algorithm SHA256).Hash.ToLowerInvariant()
        docker_attach_exit = $attachExit; container_running = $state.Running; compiler_exit = $state.ExitCode
        output = $output; runtime_browser_verified = $false
    }
    $result | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $evidence 'build-result.json') -Encoding utf8
    if ($state.Running) { throw 'The owned compiler container remains running; do not report completion.' }
    $files = @(Get-ChildItem -LiteralPath $output -Force -File -Recurse | ForEach-Object {
        [pscustomobject]@{path=[IO.Path]::GetRelativePath($output,$_.FullName).Replace('\','/');bytes=$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
    } | Sort-Object path)
    $files | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $evidence 'dist-manifest.json') -Encoding utf8
    [string[]]$paths = @($files | ForEach-Object { $_.path })
    [Array]::Sort($paths, [StringComparer]::Ordinal)
    $byPath = [Collections.Generic.Dictionary[string,object]]::new([StringComparer]::Ordinal)
    foreach ($file in $files) { $byPath[$file.path] = $file }
    $signature = [Text.StringBuilder]::new(); $totalBytes = [long]0
    foreach ($path in $paths) {
        $file = $byPath[$path]; $totalBytes += [long]$file.bytes
        [void]$signature.Append($path).Append([char]0).Append(([long]$file.bytes).ToString([Globalization.CultureInfo]::InvariantCulture)).Append([char]0).Append($file.sha256).Append([char]10)
    }
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $distDigest = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($signature.ToString())))).Replace('-','').ToLowerInvariant() }
    finally { $sha.Dispose() }
    $adapterReceiptPath = Join-Path $evidence 'adapter-receipt.json'
    if (Test-Path -LiteralPath $adapterReceiptPath -PathType Leaf) {
        $adapterReceipt = Get-Content -LiteralPath $adapterReceiptPath -Raw | ConvertFrom-Json
        $passes = @($adapterReceipt.passes); $emitted = @($adapterReceipt.after_emit_inventories)
        $generated = @($adapterReceipt.generated_modules)
        $result.adapter_observation_valid = $adapterReceipt.schema -eq 1 -and
            $adapterReceipt.status -ceq 'SECOND_PASS_OBSERVED' -and $adapterReceipt.factory_count -eq 1 -and
            $adapterReceipt.callback_count -eq 1 -and $adapterReceipt.first_callback_success -eq $true -and
            $adapterReceipt.explicit_invalidations -eq 1 -and $adapterReceipt.purged_module_count -eq 8 -and
            $passes.Count -eq 2 -and $passes[0].pass -eq 1 -and $passes[1].pass -eq 2 -and
            $passes[0].has_errors -eq $false -and $passes[1].has_errors -eq $false -and
            $passes[0].asset_count -gt 0 -and $passes[1].asset_count -gt 0 -and
            @($adapterReceipt.after_emit_passes).Count -eq 1 -and $adapterReceipt.after_emit_passes[0] -eq 2 -and
            $emitted.Count -eq 1 -and $emitted[0].pass -eq 2 -and $generated.Count -eq 8 -and
            @($generated | Where-Object { $_.bytes -le 0 -or $_.sha256 -cnotmatch '^[a-f0-9]{64}$' }).Count -eq 0 -and
            @($adapterReceipt.source_guards).Count -eq 8 -and
            $adapterReceipt.phase_counts.failed -eq 0 -and @($adapterReceipt.error_observations).Count -eq 0 -and
            @($adapterReceipt.callback_observations).Count -eq 1 -and
            $adapterReceipt.callback_observations[0].index -eq 1 -and $adapterReceipt.callback_observations[0].error_present -eq $false -and
            $adapterReceipt.failure_close_requested -eq $false -and $adapterReceipt.native_failure_exit_requested -eq $false
        $result.dist_matches_after_emit = $emitted.Count -eq 1 -and $files.Count -gt 0 -and
            $emitted[0].file_count -eq $files.Count -and $emitted[0].bytes -eq $totalBytes -and
            $emitted[0].manifest_sha256 -ceq $distDigest -and $paths -ccontains 'index.html'
        $result['adapter_receipt_sha256'] = (Get-FileHash -LiteralPath $adapterReceiptPath -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    if (-not $state.Running -and $state.ExitCode -eq 0 -and $attachExit -eq 0 -and
        $result.adapter_observation_valid -and $result.dist_matches_after_emit) { $result.status = 'BUILD_VERIFIED' }
    $result['dist_file_count'] = $files.Count; $result['dist_bytes'] = $totalBytes; $result['dist_inventory_sha256'] = $distDigest
    $result | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $evidence 'build-result.json') -Encoding utf8
    if ((Invoke-UniNative -Label 'source-head-after' -Executable 'git' -Arguments ($gitBase + @('rev-parse','HEAD'))).Trim() -cne $inputs.SourceSha) { throw 'Source base HEAD changed during build.' }
    if ((Invoke-UniNative -Label 'source-branch-after' -Executable 'git' -Arguments ($gitBase + @('rev-parse','--abbrev-ref','HEAD'))).Trim() -cne $inputs.WorktreeBinding.branch) { throw 'Source branch changed during build.' }
    if ((Invoke-UniNative -Label 'source-controlled-worktree-after' -Executable 'git' -Arguments ($gitBase + @('status','--porcelain=v1','--untracked-files=all'))) -cne $inputs.WorktreeBinding.expected_status_porcelain_v1) { throw 'Controlled source state changed during build.' }
    $trackedAfter = (Invoke-UniNative -Label 'source-paths-after' -Executable 'git' -Arguments ($gitBase + @('ls-files','-z','--','template/uni-app'))).TrimEnd([char]0).Split([char]0)
    if (($trackedAfter -join "`n") -cne (($copyManifest.entries | ForEach-Object {$_.path}) -join "`n")) { throw 'Complete tracked source namespace changed during build.' }
    $verifyId = (Invoke-UniNative -Label 'owned-id-before-remove' -Arguments @('inspect','--format','{{.Id}}',$containerId)).Trim()
    if ($LASTEXITCODE -ne 0 -or $verifyId -cne $containerId) { throw 'Owned container identity changed.' }
    $removeText = Invoke-UniNative -Label 'owned-normal-remove' -Arguments @('rm',$containerId) -AllowNativeFailure
    $removeExit = $script:LastUniNativeExit
    [IO.File]::WriteAllText((Join-Path $evidence 'container-remove.log'),$removeText,[Text.UTF8Encoding]::new($false))
    $removeExit | Set-Content -LiteralPath (Join-Path $evidence 'container-remove.exit') -Encoding ascii
    $result['native_operations'] = @($script:UniNativeRecords)
    $result['normal_owned_remove_exit'] = $removeExit
    $result['runtime_source_fullbytes_preserved'] = $false
    foreach ($entry in $copyManifest.entries) {
        $target = Join-Path $runtimeInput $entry.path.Substring('template/uni-app/'.Length)
        if ((Get-Item -LiteralPath $target).Length -ne $entry.bytes -or (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant() -cne $entry.sha256) { throw 'Actual runtime input changed.' }
    }
    if (@(Get-ChildItem -LiteralPath $runtimeInput -Force -File -Recurse).Count -ne 559) { throw 'Runtime input namespace changed.' }
    $result.runtime_source_fullbytes_preserved = $true
    $result | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $evidence 'build-result.json') -Encoding utf8
    if ($removeExit -ne 0 -or $removeText.Trim() -cne $containerId -or $result.status -cne 'BUILD_VERIFIED') { exit 1 }
} catch {
    # Preserve native diagnostics and the exact owned container ID; do not stop or remove unrelated services.
    [pscustomobject]@{status='SCRIPT_FAILED';container_id=$containerId;runtime_browser_verified=$false} |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $evidence 'script-failure.json') -Encoding utf8
    throw
}
