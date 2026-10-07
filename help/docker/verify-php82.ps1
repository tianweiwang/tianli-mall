<#
.SYNOPSIS
Verifies the CRMEB PHP 8.2 compatibility baseline without changing the checkout.

.DESCRIPTION
Baseline mode records the PHP and Composer versions, Composer blockers, vendor
integrity, PHP parse failures, and runtime diagnostics. PHP 8.2 checks run in
the crmeb-p82-probe image with the source tree mounted read-only.

.EXAMPLE
./help/docker/verify-php82.ps1 -Mode SelfTest

.EXAMPLE
./help/docker/verify-php82.ps1 -Mode Integrity

.EXAMPLE
./help/docker/verify-php82.ps1 -Mode Integrity -VendorRoot C:\temp\clean-vendor

.EXAMPLE
./help/docker/verify-php82.ps1 -Mode Lint -LintPath app,crmeb
#>
[CmdletBinding()]
param(
    [ValidateSet("Baseline", "Integrity", "Lint", "Deprecations", "Candidate", "SelfTest")]
    [string]$Mode = "Baseline",
    [string]$SourceRoot,
    [string]$VendorRoot,
    [string[]]$LintPath = @(),
    [string]$Image = "crmeb-p82-probe:latest",
    [string]$RollbackImage = "tianli/crmeb-php:php74-rollback"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "../..")).Path
if (-not $SourceRoot) {
    $SourceRoot = Join-Path $repoRoot "crmeb"
}
$SourceRoot = (Resolve-Path -LiteralPath $SourceRoot).Path

if (-not $VendorRoot) {
    $VendorRoot = Join-Path $SourceRoot "vendor"
}

$LintPath = @($LintPath | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })

function Get-NormalizedPath {
    param([Parameter(Mandatory)][string]$Path)

    [IO.Path]::GetFullPath($Path).TrimEnd([char[]]@('\', '/'))
}

function Test-IsPathWithin {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Root
    )

    $normalizedPath = Get-NormalizedPath -Path $Path
    $normalizedRoot = Get-NormalizedPath -Path $Root
    if ($normalizedPath.Equals($normalizedRoot, [StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    $prefix = $normalizedRoot + [IO.Path]::DirectorySeparatorChar
    $normalizedPath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}

function New-TemporaryDirectory {
    param([Parameter(Mandatory)][string]$Prefix)

    $tempRoot = Get-NormalizedPath -Path ([IO.Path]::GetTempPath())
    $path = Join-Path $tempRoot ($Prefix + [guid]::NewGuid().ToString("N"))
    $null = New-Item -ItemType Directory -Path $path
    (Resolve-Path -LiteralPath $path).Path
}

function Remove-TemporaryDirectory {
    param([Parameter(Mandatory)][string]$Path)

    $tempRoot = Get-NormalizedPath -Path ([IO.Path]::GetTempPath())
    $resolved = Get-NormalizedPath -Path $Path
    if (-not (Test-IsPathWithin -Path $resolved -Root $tempRoot) -or $resolved -eq $tempRoot) {
        throw "Refusing to remove a directory outside the system temp root: $resolved"
    }

    if (Test-Path -LiteralPath $resolved) {
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}

function Invoke-Docker {
    param([Parameter(Mandatory)][string[]]$Arguments)

    $normalizedArguments = @($Arguments | ForEach-Object { $_.Replace("`r`n", "`n") })
    $output = & docker @normalizedArguments 2>&1
    $exitCode = $LASTEXITCODE
    [pscustomobject]@{
        ExitCode = $exitCode
        Lines = @($output | ForEach-Object { [string]$_ })
        Output = (@($output | ForEach-Object { [string]$_ }) -join "`n").Trim()
    }
}

function Get-DockerRunArguments {
    param(
        [Parameter(Mandatory)][string]$MountedSourceRoot,
        [AllowNull()][string]$MountedVendorRoot
    )

    $arguments = @(
        "run", "--rm",
        "--mount", "type=bind,source=$MountedSourceRoot,target=/workspace,readonly"
    )

    if ($MountedVendorRoot) {
        $defaultVendor = Get-NormalizedPath -Path (Join-Path $MountedSourceRoot "vendor")
        $selectedVendor = Get-NormalizedPath -Path $MountedVendorRoot
        if (-not $selectedVendor.Equals($defaultVendor, [StringComparison]::OrdinalIgnoreCase)) {
            $arguments += @(
                "--mount", "type=bind,source=$selectedVendor,target=/workspace/vendor,readonly"
            )
        }
    }

    $arguments
}

function Invoke-ProbeImage {
    param(
        [Parameter(Mandatory)][string]$MountedSourceRoot,
        [AllowNull()][string]$MountedVendorRoot,
        [Parameter(Mandatory)][string]$Entrypoint,
        [Parameter(Mandatory)][string[]]$CommandArguments,
        [string[]]$AdditionalMounts = @()
    )

    $arguments = @(Get-DockerRunArguments -MountedSourceRoot $MountedSourceRoot -MountedVendorRoot $MountedVendorRoot)
    foreach ($mount in $AdditionalMounts) {
        $arguments += @("--mount", $mount)
    }
    $arguments += @("-w", "/workspace", "--entrypoint", $Entrypoint, $Image)
    $arguments += $CommandArguments
    Invoke-Docker -Arguments $arguments
}

function Invoke-CandidateImage {
    param(
        [Parameter(Mandatory)][string]$Entrypoint,
        [Parameter(Mandatory)][string[]]$CommandArguments,
        [string[]]$AdditionalMounts = @(),
        [string]$CandidateImage = $Image,
        [switch]$ReadOnly,
        [string[]]$Tmpfs = @()
    )

    $arguments = @("run", "--rm", "--network", "none")
    if ($ReadOnly) {
        $arguments += "--read-only"
    }
    foreach ($tmpfsMount in $Tmpfs) {
        $arguments += @("--tmpfs", $tmpfsMount)
    }
    foreach ($mount in $AdditionalMounts) {
        $arguments += @("--mount", $mount)
    }
    $arguments += @("-w", "/var/www", "--entrypoint", $Entrypoint, $CandidateImage)
    $arguments += $CommandArguments
    Invoke-Docker -Arguments $arguments
}

function Resolve-LintTarget {
    param(
        [Parameter(Mandatory)][string]$RelativePath,
        [Parameter(Mandatory)][string]$MountedSourceRoot,
        [AllowNull()][string]$MountedVendorRoot
    )

    $normalized = $RelativePath.Replace('\', '/').Trim('/')
    if (-not $normalized -or $normalized -eq ".") {
        return [pscustomobject]@{ HostPath = $MountedSourceRoot; ContainerPath = "/workspace" }
    }
    if ([IO.Path]::IsPathRooted($RelativePath) -or $normalized -eq ".." -or $normalized.StartsWith("../")) {
        throw "Lint paths must be relative to the source root: $RelativePath"
    }

    if ($normalized -eq "vendor" -or $normalized.StartsWith("vendor/")) {
        if (-not $MountedVendorRoot) {
            throw "A vendor root is required to lint $RelativePath"
        }
        $suffix = if ($normalized.Length -gt 6) { $normalized.Substring(7) } else { "" }
        $hostPath = if ($suffix) { Join-Path $MountedVendorRoot $suffix } else { $MountedVendorRoot }
    } else {
        $hostPath = Join-Path $MountedSourceRoot $normalized
    }

    if (-not (Test-Path -LiteralPath $hostPath -PathType Container)) {
        throw "Lint directory not found: $hostPath"
    }

    [pscustomobject]@{
        HostPath = (Resolve-Path -LiteralPath $hostPath).Path
        ContainerPath = "/workspace/$normalized"
    }
}

function Invoke-LintHarness {
    param(
        [Parameter(Mandatory)][string]$MountedSourceRoot,
        [AllowNull()][string]$MountedVendorRoot,
        [Parameter(Mandatory)][string[]]$RelativePaths
    )

    $targets = @($RelativePaths | ForEach-Object {
        Resolve-LintTarget -RelativePath $_ -MountedSourceRoot $MountedSourceRoot -MountedVendorRoot $MountedVendorRoot
    })
    $containerPaths = @($targets.ContainerPath | Sort-Object -Unique)
    $encodedPaths = @($containerPaths | ForEach-Object {
        [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($_))
    })

    $command = "encoded_paths=($($encodedPaths -join ' '))`n" + @'
paths=()
for encoded in "${encoded_paths[@]}"; do
    paths+=("$(printf '%s' "$encoded" | base64 -d)")
done

failed=0
file_count=0
failure_count=0
while IFS= read -r -d '' file; do
    file_count=$((file_count + 1))
    php_output="$(php -d display_errors=1 -d log_errors=0 -l "$file" 2>&1)"
    php_status=$?
    if [ "$php_status" -ne 0 ]; then
        printf '__PHP82_LINT_FAILURE__|%s\n' "$file"
        printf '%s\n' "$php_output"
        failed=1
        failure_count=$((failure_count + 1))
    fi
done < <(find "${paths[@]}" -type f -name '*.php' -print0 | sort -z)

printf '__PHP82_LINT_SUMMARY__|files=%s|failures=%s\n' "$file_count" "$failure_count"
exit "$failed"
'@

    $run = Invoke-ProbeImage `
        -MountedSourceRoot $MountedSourceRoot `
        -MountedVendorRoot $MountedVendorRoot `
        -Entrypoint "bash" `
        -CommandArguments @("-lc", $command)

    $failurePrefix = "__PHP82_LINT_FAILURE__|"
    $failures = @($run.Lines | Where-Object { $_.StartsWith($failurePrefix) } | ForEach-Object {
        $_.Substring($failurePrefix.Length)
    })
    $summary = @($run.Lines | Where-Object { $_.StartsWith("__PHP82_LINT_SUMMARY__|") } | Select-Object -Last 1)

    [pscustomobject]@{
        ExitCode = $run.ExitCode
        Output = $run.Output
        Lines = $run.Lines
        Failures = $failures
        Summary = if ($summary.Count) { $summary[0] } else { "missing" }
    }
}

function Invoke-LintSelfTest {
    $fixtureRoot = New-TemporaryDirectory -Prefix "crmeb-php82-lint-selftest-"
    try {
        [IO.File]::WriteAllText(
            (Join-Path $fixtureRoot "OrderParser.php"),
            "<?php`nfunction parseOrder( {`n"
        )
        [IO.File]::WriteAllText(
            (Join-Path $fixtureRoot "CatalogReader.php"),
            "<?php`nfunction readCatalog( {`n"
        )

        $result = Invoke-LintHarness `
            -MountedSourceRoot $fixtureRoot `
            -MountedVendorRoot $null `
            -RelativePaths @(".")

        $reportedNames = @($result.Failures | ForEach-Object { [IO.Path]::GetFileName($_) })
        $missing = @(@("OrderParser.php", "CatalogReader.php") | Where-Object { $_ -notin $reportedNames })
        $passed = $result.ExitCode -ne 0 -and $result.Failures.Count -eq 2 -and $missing.Count -eq 0

        if (-not $passed) {
            return [pscustomobject]@{
                Passed = $false
                LintOutput = $result.Output
                Detail = "[FAIL] lint self-test: exit=$($result.ExitCode) failures=$($result.Failures.Count) missing=$($missing -join ',')"
            }
        }

        return [pscustomobject]@{
            Passed = $true
            LintOutput = ""
            Detail = "[PASS] lint self-test: both invalid files were reported and the lint result was nonzero."
        }
    } finally {
        Remove-TemporaryDirectory -Path $fixtureRoot
    }
}

function Get-PackageSourceReference {
    param([Parameter(Mandatory)][Collections.IDictionary]$Package)

    if ($Package.Contains("source") -and $Package["source"] -is [Collections.IDictionary]) {
        $source = $Package["source"]
        if ($source.Contains("reference") -and $null -ne $source["reference"]) {
            return [string]$source["reference"]
        }
    }
    ""
}

function Add-IntegrityIssue {
    param(
        [Parameter(Mandatory)][AllowEmptyCollection()][Collections.Generic.List[object]]$Issues,
        [Parameter(Mandatory)][string]$Category,
        [Parameter(Mandatory)][string]$Package,
        [Parameter(Mandatory)][string]$Detail
    )

    $Issues.Add([pscustomobject]@{
        Category = $Category
        Package = $Package
        Detail = $Detail
    })
}

function Test-VendorIntegrity {
    param(
        [Parameter(Mandatory)][string]$MountedSourceRoot,
        [Parameter(Mandatory)][string]$MountedVendorRoot
    )

    $issues = [Collections.Generic.List[object]]::new()
    $lockPath = Join-Path $MountedSourceRoot "composer.lock"
    $installedPath = Join-Path $MountedVendorRoot "composer/installed.json"

    if (-not (Test-Path -LiteralPath $lockPath -PathType Leaf)) {
        Add-IntegrityIssue -Issues $issues -Category "metadata" -Package "composer.lock" -Detail "file not found"
    }
    if (-not (Test-Path -LiteralPath $installedPath -PathType Leaf)) {
        Add-IntegrityIssue -Issues $issues -Category "metadata" -Package "installed.json" -Detail "file not found"
    }
    if ($issues.Count) {
        return [pscustomobject]@{ Passed = $false; Issues = @($issues); Locked = 0; Installed = 0 }
    }

    $lock = Get-Content -Raw -LiteralPath $lockPath | ConvertFrom-Json -AsHashtable
    $installedDocument = Get-Content -Raw -LiteralPath $installedPath | ConvertFrom-Json -AsHashtable
    $lockPackages = @()
    if ($lock.Contains("packages")) {
        $lockPackages += @($lock["packages"])
    }
    if ($lock.Contains("packages-dev")) {
        $lockPackages += @($lock["packages-dev"])
    }

    $installedPackages = if (
        $installedDocument -is [Collections.IDictionary] -and $installedDocument.Contains("packages")
    ) {
        @($installedDocument["packages"])
    } else {
        @($installedDocument)
    }

    $lockByName = @{}
    foreach ($package in $lockPackages) {
        $name = [string]$package["name"]
        if ($lockByName.ContainsKey($name)) {
            Add-IntegrityIssue -Issues $issues -Category "duplicate-lock-package" -Package $name -Detail "duplicate package name"
        } else {
            $lockByName[$name] = $package
        }
    }

    $installedByName = @{}
    foreach ($package in $installedPackages) {
        $name = [string]$package["name"]
        if ($installedByName.ContainsKey($name)) {
            Add-IntegrityIssue -Issues $issues -Category "duplicate-installed-package" -Package $name -Detail "duplicate package name"
        } else {
            $installedByName[$name] = $package
        }
    }

    foreach ($name in @($lockByName.Keys | Sort-Object)) {
        if (-not $installedByName.ContainsKey($name)) {
            Add-IntegrityIssue -Issues $issues -Category "missing-installed-package" -Package $name -Detail "recorded in composer.lock only"
            continue
        }

        $lockedPackage = $lockByName[$name]
        $installedPackage = $installedByName[$name]
        $lockedVersion = [string]$lockedPackage["version"]
        $installedVersion = [string]$installedPackage["version"]
        if ($lockedVersion -ne $installedVersion) {
            Add-IntegrityIssue `
                -Issues $issues `
                -Category "version-mismatch" `
                -Package $name `
                -Detail "lock=$lockedVersion installed=$installedVersion"
        }

        $lockedReference = Get-PackageSourceReference -Package $lockedPackage
        $installedReference = Get-PackageSourceReference -Package $installedPackage
        if ($lockedReference -ne $installedReference) {
            Add-IntegrityIssue `
                -Issues $issues `
                -Category "source-reference-mismatch" `
                -Package $name `
                -Detail "lock=$lockedReference installed=$installedReference"
        }
    }

    foreach ($name in @($installedByName.Keys | Sort-Object)) {
        if (-not $lockByName.ContainsKey($name)) {
            Add-IntegrityIssue -Issues $issues -Category "unexpected-installed-package" -Package $name -Detail "recorded in installed.json only"
        }
    }

    $metadataRoot = Join-Path $MountedVendorRoot "composer"
    $recordedInstallPaths = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    foreach ($package in $installedPackages) {
        $name = [string]$package["name"]
        $installPath = if ($package.Contains("install-path")) { [string]$package["install-path"] } else { "" }
        $type = if ($package.Contains("type")) { [string]$package["type"] } else { "" }
        if (-not $installPath) {
            if ($type -ne "metapackage") {
                Add-IntegrityIssue -Issues $issues -Category "missing-install-path" -Package $name -Detail "installed.json has no install-path"
            }
            continue
        }

        $resolvedInstallPath = Get-NormalizedPath -Path (Join-Path $metadataRoot $installPath)
        if (-not (Test-IsPathWithin -Path $resolvedInstallPath -Root $MountedVendorRoot)) {
            Add-IntegrityIssue -Issues $issues -Category "escaped-install-path" -Package $name -Detail $resolvedInstallPath
            continue
        }
        $null = $recordedInstallPaths.Add($resolvedInstallPath)
        if (-not (Test-Path -LiteralPath $resolvedInstallPath -PathType Container)) {
            Add-IntegrityIssue -Issues $issues -Category "install-path-not-found" -Package $name -Detail $installPath
        }
    }

    foreach ($namespaceDirectory in Get-ChildItem -LiteralPath $MountedVendorRoot -Directory -Force) {
        if ($namespaceDirectory.Name -in @("bin", "composer")) {
            continue
        }
        $namespacePath = Get-NormalizedPath -Path $namespaceDirectory.FullName
        if ($recordedInstallPaths.Contains($namespacePath)) {
            continue
        }
        foreach ($packageDirectory in Get-ChildItem -LiteralPath $namespaceDirectory.FullName -Directory -Force) {
            $packagePath = Get-NormalizedPath -Path $packageDirectory.FullName
            if (-not $recordedInstallPaths.Contains($packagePath)) {
                $relativePath = [IO.Path]::GetRelativePath($MountedVendorRoot, $packagePath).Replace('\', '/')
                Add-IntegrityIssue `
                    -Issues $issues `
                    -Category "unexpected-package-directory" `
                    -Package $relativePath `
                    -Detail "directory is not recorded in installed.json"
            }
        }
    }

    [pscustomobject]@{
        Passed = $issues.Count -eq 0
        Issues = @($issues)
        Locked = $lockPackages.Count
        Installed = $installedPackages.Count
    }
}

function Write-IntegrityResult {
    param([Parameter(Mandatory)]$Result)

    if ($Result.Passed) {
        "[PASS] vendor integrity: lock=$($Result.Locked) installed=$($Result.Installed)"
        return
    }

    foreach ($issue in $Result.Issues) {
        "[FAIL] vendor integrity [$($issue.Category)] $($issue.Package): $($issue.Detail)"
    }
    "VENDOR_INTEGRITY_FAILURES=$($Result.Issues.Count)"
}

function New-DeprecationProbeFiles {
    param([Parameter(Mandatory)][string]$Directory)

    $discoveryScript = @'
<?php
declare(strict_types=1);

$roots = array_slice($argv, 1);
$symbols = [];
$sourceFiles = [];
$declarationTokens = [T_CLASS, T_INTERFACE, T_TRAIT];
if (defined('T_ENUM')) {
    $declarationTokens[] = T_ENUM;
}
$namespaceNameTokens = [T_STRING, T_NS_SEPARATOR];
foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED'] as $tokenName) {
    if (defined($tokenName)) {
        $namespaceNameTokens[] = constant($tokenName);
    }
}

foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $namespace = '';
        $tokenCount = count($tokens);
        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
                    $candidate = $tokens[$cursor];
                    if (is_array($candidate) && in_array($candidate[0], $namespaceNameTokens, true)) {
                        $namespace .= $candidate[1];
                        continue;
                    }
                    if ($candidate === ';' || $candidate === '{') {
                        break;
                    }
                }
                $namespace = trim($namespace, '\\');
                continue;
            }

            if (!in_array($token[0], $declarationTokens, true)) {
                continue;
            }

            $previousToken = null;
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $previousToken = is_array($candidate) ? $candidate[0] : $candidate;
                break;
            }
            if ($token[0] === T_CLASS && ($previousToken === T_NEW || $previousToken === T_DOUBLE_COLON)) {
                continue;
            }

            for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate) && $candidate[0] === T_STRING) {
                    $symbol = $namespace === '' ? $candidate[1] : $namespace . '\\' . $candidate[1];
                    $symbols[] = $symbol;
                    $sourceFiles[$symbol] = $file->getPathname();
                    break;
                }
            }
        }
    }
}

$strictSymbols = [
    'think\\Paginator',
    'think\\Model',
    'think\\model\\concern\\Conversion',
    'think\\db\\Where',
    'think\\Collection',
    'Pimple\\Container',
    'think\\image\\gif\\Decoder',
    'think\\image\\gif\\Encoder',
    'think\\Image',
    'EasyWeChat\\Support\\Collection',
];
$symbols = array_merge($symbols, $strictSymbols);

$symbols = array_values(array_unique($symbols));
sort($symbols, SORT_STRING);
foreach ($symbols as $symbol) {
    $sourcePath = $sourceFiles[$symbol] ?? '';
    echo base64_encode($symbol), ' ', $sourcePath === '' ? '-' : base64_encode($sourcePath), PHP_EOL;
}
'@

    $classProbeScript = @'
<?php
declare(strict_types=1);

function php82ProbeEmit(array $record): void
{
    echo '__PHP82_PROBE__|', json_encode(
        $record,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    ), PHP_EOL;
}

function php82ProbeSeverityName(int $severity): string
{
    $names = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];
    return $names[$severity] ?? (string) $severity;
}

$class = $argv[1] ?? '';
$autoloadPath = $argv[2] ?? '/workspace/vendor/autoload.php';
$sourcePath = $argv[3] ?? '';
$diagnosticCount = 0;
$finished = false;
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

set_error_handler(
    static function (int $severity, string $message, string $file, int $line) use (&$diagnosticCount): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        $diagnosticCount++;
        php82ProbeEmit([
            'kind' => 'diagnostic',
            'severity' => $severity,
            'severity_name' => php82ProbeSeverityName($severity),
            'message' => $message,
            'origin' => $file,
            'line' => $line,
        ]);
        return true;
    }
);

register_shutdown_function(
    static function () use (&$finished, $class): void {
        if ($finished) {
            return;
        }
        $error = error_get_last();
        php82ProbeEmit([
            'kind' => 'load_failure',
            'class' => $class,
            'message' => $error['message'] ?? 'PHP process stopped before the class load completed',
            'origin' => $error['file'] ?? '',
            'line' => $error['line'] ?? 0,
        ]);
    }
);

try {
    require_once $autoloadPath;
    $loaded = class_exists($class, true)
        || interface_exists($class, true)
        || trait_exists($class, true)
        || (function_exists('enum_exists') && enum_exists($class, true));
    if (!$loaded && $sourcePath !== '') {
        require_once $sourcePath;
        $loaded = class_exists($class, false)
            || interface_exists($class, false)
            || trait_exists($class, false)
            || (function_exists('enum_exists') && enum_exists($class, false));
    }
    if (!$loaded) {
        $finished = true;
        php82ProbeEmit([
            'kind' => 'load_failure',
            'class' => $class,
            'message' => 'Autoload did not define the discovered symbol',
            'origin' => '',
            'line' => 0,
        ]);
        exit(1);
    }
    $reflection = new ReflectionClass($class);
    $reflection->getMethods();
    $reflection->getProperties();
    $finished = true;
    exit($diagnosticCount === 0 ? 0 : 2);
} catch (Throwable $throwable) {
    $finished = true;
    php82ProbeEmit([
        'kind' => 'load_failure',
        'class' => $class,
        'message' => get_class($throwable) . ': ' . $throwable->getMessage(),
        'origin' => $throwable->getFile(),
        'line' => $throwable->getLine(),
    ]);
    exit(1);
}
'@

    [IO.File]::WriteAllText((Join-Path $Directory "discover.php"), $discoveryScript)
    [IO.File]::WriteAllText((Join-Path $Directory "class-probe.php"), $classProbeScript)
}

function Invoke-DeprecationScopeSelfTest {
    $fixtureRoot = New-TemporaryDirectory -Prefix "crmeb-php82-scope-selftest-"
    $probeRoot = New-TemporaryDirectory -Prefix "crmeb-php82-scope-probe-"
    try {
        foreach ($relativePath in @("app", "crmeb", "vendor/composer")) {
            $null = New-Item -ItemType Directory -Path (Join-Path $fixtureRoot $relativePath) -Force
        }

        [IO.File]::WriteAllText(
            (Join-Path $fixtureRoot "app/OrderReader.php"),
            "<?php`nnamespace Retail;`nclass OrderReader {}`n"
        )
        [IO.File]::WriteAllText(
            (Join-Path $fixtureRoot "crmeb/CatalogSupport.php"),
            "<?php`nnamespace Commerce;`ntrait CatalogSupport {}`n"
        )
        [IO.File]::WriteAllText(
            (Join-Path $fixtureRoot "vendor/composer/autoload_classmap.php"),
            "<?php`nreturn ['Vendor\\Production\\Client' => __FILE__, 'Vendor\\Tests\\ClientTest' => __FILE__];`n"
        )

        New-DeprecationProbeFiles -Directory $probeRoot
        $result = Invoke-ProbeImage `
            -MountedSourceRoot $fixtureRoot `
            -MountedVendorRoot $null `
            -Entrypoint "php" `
            -CommandArguments @("/probe/discover.php", "/workspace/app", "/workspace/crmeb") `
            -AdditionalMounts @("type=bind,source=$probeRoot,target=/probe,readonly")

        $discoveredSymbols = @($result.Lines | Where-Object { $_ } | ForEach-Object {
            $parts = $_ -split ' ', 2
            [pscustomobject]@{
                Symbol = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($parts[0]))
                SourcePath = if ($parts[1] -eq "-") {
                    ""
                } else {
                    [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($parts[1]))
                }
            }
        })
        $symbols = @($discoveredSymbols.Symbol)
        $expected = @(
            "Retail\OrderReader",
            "Commerce\CatalogSupport",
            "think\Paginator",
            "think\Model",
            "think\model\concern\Conversion",
            "think\db\Where",
            "think\Collection",
            "Pimple\Container",
            "think\image\gif\Decoder",
            "think\image\gif\Encoder",
            "think\Image",
            "EasyWeChat\Support\Collection"
        )
        $missing = @($expected | Where-Object { $_ -notin $symbols })
        $unexpected = @($symbols | Where-Object { $_ -like "Vendor\*" })
        $passed = $result.ExitCode -eq 0 -and $missing.Count -eq 0 -and $unexpected.Count -eq 0

        [pscustomobject]@{
            Passed = $passed
            Detail = if ($passed) {
                "[PASS] deprecation scope self-test: project and strict symbols included; vendor classmap excluded."
            } else {
                "[FAIL] deprecation scope self-test: missing=$($missing -join ',') unexpected=$($unexpected -join ',')"
            }
        }
    } finally {
        Remove-TemporaryDirectory -Path $probeRoot
        Remove-TemporaryDirectory -Path $fixtureRoot
    }
}

function Get-DiagnosticSource {
    param(
        [AllowNull()][string]$Origin,
        [string]$ContainerSourceRoot = "/workspace"
    )

    if (-not $Origin) {
        return "other"
    }
    $normalizedOrigin = $Origin.Replace('\', '/')
    $normalizedSourceRoot = $ContainerSourceRoot.Replace('\', '/').TrimEnd('/')
    if ($normalizedOrigin.StartsWith("$normalizedSourceRoot/vendor/", [StringComparison]::Ordinal)) {
        return "vendor"
    }
    if ($normalizedOrigin.StartsWith("$normalizedSourceRoot/", [StringComparison]::Ordinal)) {
        return "application"
    }
    "other"
}

function Invoke-DeprecationProbe {
    param(
        [AllowNull()][string]$MountedSourceRoot,
        [AllowNull()][string]$MountedVendorRoot,
        [string]$ContainerSourceRoot = "/workspace",
        [switch]$UseCandidateImage
    )

    if (-not $UseCandidateImage) {
        if (-not $MountedSourceRoot -or -not $MountedVendorRoot) {
            throw "Mounted source and vendor roots are required outside candidate mode"
        }
        foreach ($relativePath in @("app", "crmeb")) {
            $path = Join-Path $MountedSourceRoot $relativePath
            if (-not (Test-Path -LiteralPath $path -PathType Container)) {
                throw "Probe directory not found: $path"
            }
        }
    }

    $probeRoot = New-TemporaryDirectory -Prefix "crmeb-php82-deprecation-probe-"
    try {
        New-DeprecationProbeFiles -Directory $probeRoot
        $encodedContainerSourceRoot = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($ContainerSourceRoot.TrimEnd('/')))
        $command = "encoded_source_root=$encodedContainerSourceRoot`n" + @'
source_root="$(printf '%s' "$encoded_source_root" | base64 -d)"
class_list=/tmp/php82-class-list.txt
probe_records=/tmp/php82-probe-records.txt
: > "$probe_records"
if ! php /probe/discover.php "$source_root/app" "$source_root/crmeb" > "$class_list"; then
    printf '__PHP82_PROBE_DISCOVERY_FAILURE__\n'
    exit 1
fi

failed=0
record_directory=/tmp/php82-probe-records
mkdir -p "$record_directory"
class_count="$(grep -cve '^$' "$class_list")"
worker_count="$(nproc)"
if [ "$worker_count" -gt 8 ]; then
    worker_count=8
fi

probe_one_class() {
    encoded="$1"
    encoded_source_path="$2"
    class_name="$(printf '%s' "$encoded" | base64 -d)"
    source_path=""
    if [ "$encoded_source_path" != "-" ]; then
        source_path="$(printf '%s' "$encoded_source_path" | base64 -d)"
    fi
    probe_output="$(timeout --signal=TERM --kill-after=2s 20s php -d display_errors=0 -d log_errors=0 /probe/class-probe.php "$class_name" "$source_root/vendor/autoload.php" "$source_path" 2>&1)"
    probe_status=$?
    record_name="${encoded//\//_}"
    record_path="$record_directory/$record_name"
    if [ -n "$probe_output" ]; then
        printf '%s\n' "$probe_output" >> "$record_path"
    fi
    if [ "$probe_status" -ne 0 ]; then
        printf '__PHP82_PROBE_CLASS_STATUS__|%s|%s\n' "$encoded" "$probe_status" >> "$record_path"
        if [ "$probe_status" -eq 124 ] || ! printf '%s\n' "$probe_output" | grep -q '^__PHP82_PROBE__|'; then
            printf '__PHP82_PROBE_PROCESS_FAILURE__|%s|%s\n' "$encoded" "$probe_status" >> "$record_path"
        fi
    fi
    return 0
}
export -f probe_one_class
export record_directory
export source_root

xargs -r -n 2 -P "$worker_count" bash -c 'probe_one_class "$1" "$2"' _ < "$class_list"
find "$record_directory" -type f -exec cat {} + > "$probe_records"
nonzero_class_count="$(grep -c '^__PHP82_PROBE_CLASS_STATUS__|' "$probe_records" || true)"
if [ "$nonzero_class_count" -ne 0 ]; then
    failed=1
fi

grep -v '^__PHP82_PROBE_CLASS_STATUS__|' "$probe_records" | sort -u
printf '__PHP82_PROBE_SUMMARY__|classes=%s|nonzero_classes=%s\n' "$class_count" "$nonzero_class_count"
exit "$failed"
'@

        $additionalMounts = @("type=bind,source=$probeRoot,target=/probe,readonly")
        if ($UseCandidateImage) {
            $run = Invoke-CandidateImage `
                -Entrypoint "bash" `
                -CommandArguments @("-lc", $command) `
                -AdditionalMounts $additionalMounts
        } else {
            $run = Invoke-ProbeImage `
                -MountedSourceRoot $MountedSourceRoot `
                -MountedVendorRoot $MountedVendorRoot `
                -Entrypoint "bash" `
                -CommandArguments @("-lc", $command) `
                -AdditionalMounts $additionalMounts
        }

        $recordPrefix = "__PHP82_PROBE__|"
        $records = [Collections.Generic.List[object]]::new()
        foreach ($line in $run.Lines) {
            if ($line.StartsWith($recordPrefix)) {
                try {
                    $record = $line.Substring($recordPrefix.Length) | ConvertFrom-Json
                    $record | Add-Member -NotePropertyName Source -NotePropertyValue (Get-DiagnosticSource -Origin $record.origin -ContainerSourceRoot $ContainerSourceRoot)
                    $records.Add($record)
                } catch {
                    $records.Add([pscustomobject]@{
                        kind = "load_failure"
                        class = "probe-output"
                        message = "Invalid structured probe output: $line"
                        origin = ""
                        line = 0
                        Source = "other"
                    })
                }
            } elseif ($line.StartsWith("__PHP82_PROBE_PROCESS_FAILURE__|")) {
                $parts = $line -split '\|'
                $className = if ($parts.Count -ge 2) {
                    [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($parts[1]))
                } else {
                    "unknown"
                }
                $processStatus = if ($parts.Count -ge 3) { $parts[2] } else { "unknown" }
                $message = if ($processStatus -eq "124") {
                    "Class load exceeded the 20 second timeout"
                } else {
                    "Probe process exited without a structured diagnostic (status=$processStatus)"
                }
                $records.Add([pscustomobject]@{
                    kind = "load_failure"
                    class = $className
                    message = $message
                    origin = ""
                    line = 0
                    Source = "other"
                })
            }
        }

        $summary = @($run.Lines | Where-Object { $_.StartsWith("__PHP82_PROBE_SUMMARY__|") } | Select-Object -Last 1)
        $diagnostics = @($records | Where-Object { $_.kind -eq "diagnostic" })
        $loadFailures = @($records | Where-Object { $_.kind -eq "load_failure" })
        [pscustomobject]@{
            ExitCode = $run.ExitCode
            Diagnostics = $diagnostics
            LoadFailures = $loadFailures
            Summary = if ($summary.Count) { $summary[0] } else { "missing" }
            Output = $run.Output
        }
    } finally {
        Remove-TemporaryDirectory -Path $probeRoot
    }
}

function Write-DeprecationResult {
    param([Parameter(Mandatory)]$Result)

    $uniqueDiagnostics = @($Result.Diagnostics | Group-Object {
        "$($_.severity)|$($_.origin)|$($_.line)|$($_.message)"
    } | ForEach-Object { $_.Group[0] })
    $deprecations = @($uniqueDiagnostics | Where-Object {
        $_.severity_name -in @("E_DEPRECATED", "E_USER_DEPRECATED")
    })

    foreach ($diagnostic in $uniqueDiagnostics | Sort-Object Source,origin,line,severity_name,message) {
        "RUNTIME_DIAGNOSTIC [$($diagnostic.Source)] $($diagnostic.severity_name) $($diagnostic.origin):$($diagnostic.line): $($diagnostic.message)"
    }
    foreach ($failure in $Result.LoadFailures | Sort-Object class,message) {
        "RUNTIME_LOAD_FAILURE $($failure.class): $($failure.message) at $($failure.origin):$($failure.line)"
    }

    $applicationDeprecations = @($deprecations | Where-Object { $_.Source -eq "application" }).Count
    $vendorDeprecations = @($deprecations | Where-Object { $_.Source -eq "vendor" }).Count
    $otherDeprecations = @($deprecations | Where-Object { $_.Source -eq "other" }).Count
    $probeSummary = $Result.Summary -replace '^__PHP82_PROBE_SUMMARY__\|', '' -replace '\|', ' '
    "RUNTIME_PROBE $probeSummary"
    "RUNTIME_DEPRECATIONS application=$applicationDeprecations vendor=$vendorDeprecations other=$otherDeprecations"
    "RUNTIME_LOAD_FAILURES=$($Result.LoadFailures.Count)"
    "RUNTIME_DIAGNOSTICS=$($uniqueDiagnostics.Count)"
}

function Invoke-RuntimeVersionCheck {
    Invoke-ProbeImage `
        -MountedSourceRoot $SourceRoot `
        -MountedVendorRoot $VendorRoot `
        -Entrypoint "php" `
        -CommandArguments @("-r", 'echo "PHP_VERSION=", PHP_VERSION, PHP_EOL;')
}

function Invoke-ComposerVersionCheck {
    Invoke-ProbeImage `
        -MountedSourceRoot $SourceRoot `
        -MountedVendorRoot $VendorRoot `
        -Entrypoint "composer" `
        -CommandArguments @("--version", "--no-ansi")
}

function Invoke-ComposerBlockerCheck {
    Invoke-ProbeImage `
        -MountedSourceRoot $SourceRoot `
        -MountedVendorRoot $VendorRoot `
        -Entrypoint "composer" `
        -CommandArguments @("prohibits", "php", "8.2", "--locked", "--no-ansi", "--no-interaction")
}

function Invoke-CandidateRuntimeCheck {
    $script = @'
$requiredExtensions = [
    'bcmath', 'curl', 'fileinfo', 'gd', 'iconv', 'mbstring', 'mysqli',
    'pdo_mysql', 'pcntl', 'redis', 'simplexml', 'sockets', 'sodium', 'xml', 'zip',
];
$missingExtensions = array_values(array_filter(
    $requiredExtensions,
    static fn (string $extension): bool => !extension_loaded($extension)
));
$jit = strtolower((string) ini_get('opcache.jit'));
$jitBufferSize = (int) ini_get('opcache.jit_buffer_size');
$facts = [
    'php_version' => PHP_VERSION,
    'php_version_id' => PHP_VERSION_ID,
    'missing_extensions' => $missingExtensions,
    'opcache_loaded' => extension_loaded('Zend OPcache'),
    'opcache_enabled' => filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL),
    'opcache_jit' => $jit,
    'opcache_jit_buffer_size' => $jitBufferSize,
];
$passed = PHP_VERSION_ID >= 80200
    && PHP_VERSION_ID < 80300
    && $missingExtensions === []
    && $facts['opcache_loaded']
    && $facts['opcache_enabled']
    && in_array($jit, ['', '0', 'off', 'disable'], true)
    && $jitBufferSize === 0;
echo '__PHP82_CANDIDATE_RUNTIME__|', json_encode($facts, JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($passed ? 0 : 1);
'@

    Invoke-CandidateImage `
        -Entrypoint "php" `
        -CommandArguments @("-d", "display_errors=0", "-d", "log_errors=0", "-r", $script)
}

function Invoke-CandidateLint {
    $command = @'
paths=(/var/www/app /var/www/crmeb /var/www/config /var/www/route /var/www/public)
failed=0
file_count=0
failure_count=0
while IFS= read -r -d '' file; do
    file_count=$((file_count + 1))
    php_output="$(php -d display_errors=1 -d log_errors=0 -l "$file" 2>&1)"
    php_status=$?
    if [ "$php_status" -ne 0 ]; then
        printf '__PHP82_CANDIDATE_LINT_FAILURE__|%s\n' "$file"
        printf '%s\n' "$php_output"
        failed=1
        failure_count=$((failure_count + 1))
    fi
done < <(find "${paths[@]}" -type f -name '*.php' -print0 | sort -z)
printf '__PHP82_CANDIDATE_LINT_SUMMARY__|files=%s|failures=%s\n' "$file_count" "$failure_count"
exit "$failed"
'@

    Invoke-CandidateImage -Entrypoint "bash" -CommandArguments @("-lc", $command)
}

function Invoke-CandidateImageCompatibility {
    $probePath = Join-Path $repoRoot "help/docker/probes/php82-image-compatibility.php"
    if (-not (Test-Path -LiteralPath $probePath -PathType Leaf)) {
        throw "Image compatibility probe not found: $probePath"
    }
    $probePath = (Resolve-Path -LiteralPath $probePath).Path
    $workRoot = New-TemporaryDirectory -Prefix "crmeb-php82-image-candidate-"
    try {
        foreach ($directory in @("fixtures", "baseline", "candidate")) {
            $null = New-Item -ItemType Directory -Path (Join-Path $workRoot $directory)
        }
        $mounts = @(
            "type=bind,source=$workRoot,target=/compat",
            "type=bind,source=$probePath,target=/probe.php,readonly"
        )
        $phpArguments = @("-d", "open_basedir=", "-d", "display_errors=0", "-d", "log_errors=0", "/probe.php")

        $generate = Invoke-CandidateImage `
            -CandidateImage $RollbackImage `
            -Entrypoint "php" `
            -CommandArguments ($phpArguments + @("generate", "/compat/fixtures")) `
            -AdditionalMounts $mounts
        if ($generate.ExitCode -ne 0) {
            return [pscustomobject]@{ ExitCode = 1; Output = "PHP 7.4 fixture generation failed:`n$($generate.Output)" }
        }

        $baseline = Invoke-CandidateImage `
            -CandidateImage $RollbackImage `
            -Entrypoint "php" `
            -CommandArguments ($phpArguments + @("run", "/compat/fixtures", "/compat/baseline")) `
            -AdditionalMounts $mounts
        if ($baseline.ExitCode -ne 0) {
            return [pscustomobject]@{ ExitCode = 1; Output = "PHP 7.4 image baseline failed:`n$($baseline.Output)" }
        }

        $candidate = Invoke-CandidateImage `
            -Entrypoint "php" `
            -CommandArguments ($phpArguments + @("run", "/compat/fixtures", "/compat/candidate")) `
            -AdditionalMounts $mounts
        if ($candidate.ExitCode -ne 0) {
            return [pscustomobject]@{ ExitCode = 1; Output = "PHP 8.2 image cases failed:`n$($candidate.Output)" }
        }

        Invoke-CandidateImage `
            -Entrypoint "php" `
            -CommandArguments ($phpArguments + @("compare", "/compat/baseline", "/compat/candidate")) `
            -AdditionalMounts $mounts
    } finally {
        Remove-TemporaryDirectory -Path $workRoot
    }
}

function Invoke-CandidateIntegrationCompatibility {
    $probePath = Join-Path $repoRoot "help/docker/probes/php82-integration-compatibility.php"
    if (-not (Test-Path -LiteralPath $probePath -PathType Leaf)) {
        throw "Integration compatibility probe not found: $probePath"
    }
    $probePath = (Resolve-Path -LiteralPath $probePath).Path
    $probeName = "php82-integration-compatibility"
    $sentinelPrefix = "__PHP82_INTEGRATION__|"
    $cases = @(
        "jwt",
        "spreadsheet",
        "order-export",
        "product-export",
        "easywechat",
        "payment",
        "sms",
        "uploads",
        "service-discovery",
        "orm-sql",
        "form-builder",
        "remote-upgrade-disabled",
        "empty-filter-rules",
        "workerman-timer",
        "dao-join-aliases"
    )
    $details = [Collections.Generic.List[string]]::new()
    $failedCases = [Collections.Generic.List[string]]::new()

    foreach ($case in $cases) {
        $run = Invoke-CandidateImage `
            -Entrypoint "php" `
            -CommandArguments @("/probe/php82-integration-compatibility.php", $case) `
            -AdditionalMounts @("type=bind,source=$probePath,target=/probe/php82-integration-compatibility.php,readonly") `
            -ReadOnly `
            -Tmpfs @("/tmp:rw,nosuid,nodev,noexec,size=128m")

        $issues = [Collections.Generic.List[string]]::new()
        if ($run.ExitCode -ne 0) {
            $issues.Add("container-exit=$($run.ExitCode)")
        }

        $sentinelLines = @($run.Lines | Where-Object { $_.StartsWith($sentinelPrefix) })
        $extraLines = @($run.Lines | Where-Object { -not $_.StartsWith($sentinelPrefix) })
        if ($extraLines.Count) {
            $issues.Add("extra-output=" + ($extraLines -join "\n"))
        }
        if ($sentinelLines.Count -ne 1) {
            $issues.Add("sentinel-count=$($sentinelLines.Count)")
        }

        $record = $null
        if ($sentinelLines.Count -eq 1) {
            try {
                $record = $sentinelLines[0].Substring($sentinelPrefix.Length) | ConvertFrom-Json
            } catch {
                $issues.Add("invalid-json=$($_.Exception.Message)")
            }
        }

        if ($null -ne $record) {
            if ($record -isnot [pscustomobject]) {
                $issues.Add("record-not-object")
            }
            $requiredProperties = @(
                "probe", "case", "status", "checks", "diagnostics", "unexpectedOutput", "throwable"
            )
            $recordProperties = @($record.PSObject.Properties.Name)
            foreach ($requiredProperty in $requiredProperties) {
                if ($requiredProperty -notin $recordProperties) {
                    $issues.Add("missing-property=$requiredProperty")
                }
            }
            if ($record.probe -ne $probeName) {
                $issues.Add("probe-mismatch=$($record.probe)")
            }
            if ($record.case -ne $case) {
                $issues.Add("case-mismatch=$($record.case)")
            }
            if ($record.status -ne "pass") {
                $issues.Add("status=$($record.status)")
            }

            $checkProperties = @(
                if ($record.checks -is [pscustomobject]) {
                    $record.checks.PSObject.Properties
                }
            )
            if ($record.checks -isnot [pscustomobject]) {
                $issues.Add("checks-not-object")
            }
            if (-not $checkProperties.Count) {
                $issues.Add("checks=missing")
            }
            foreach ($check in $checkProperties) {
                if ($check.Value -ne $true) {
                    $issues.Add("check-false=$($check.Name)")
                }
            }

            if ($record.diagnostics -isnot [array]) {
                $issues.Add("diagnostics-not-array")
            }
            $diagnostics = @($record.diagnostics)
            if ($diagnostics.Count) {
                $diagnosticSummary = @($diagnostics | ForEach-Object {
                    "$($_.severityName):$($_.file):$($_.line):$($_.message)"
                }) -join "; "
                $issues.Add("diagnostics=$diagnosticSummary")
            }
            if ($null -ne $record.throwable) {
                $issues.Add("throwable=$($record.throwable.class):$($record.throwable.message)")
            }
            if ($record.unexpectedOutput -isnot [string]) {
                $issues.Add("unexpected-output-not-string")
            } elseif ($record.unexpectedOutput -ne "") {
                $issues.Add("unexpected-output=$($record.unexpectedOutput)")
            }
        }

        if ($issues.Count) {
            $failedCases.Add($case)
            $details.Add("[FAIL] integration case=$case " + ($issues -join " | "))
        } else {
            $details.Add("[PASS] integration case=$case")
        }
    }

    $details.Add(
        "INTEGRATION_SUMMARY cases=$($cases.Count) passed=$($cases.Count - $failedCases.Count) failed=$($failedCases.Count)"
    )
    if ($failedCases.Count) {
        $details.Add("INTEGRATION_STATUS=RED failed_cases=$($failedCases -join ',')")
    } else {
        $details.Add("INTEGRATION_STATUS=GREEN")
    }

    [pscustomobject]@{
        ExitCode = if ($failedCases.Count) { 1 } else { 0 }
        Output = $details -join "`n"
        FailedCases = @($failedCases)
    }
}

function Invoke-CandidateVerification {
    $failed = $false

    "=== Candidate runtime ==="
    $runtime = Invoke-CandidateRuntimeCheck
    $runtime.Output
    if ($runtime.ExitCode -ne 0) { $failed = $true }

    "=== Candidate Supervisor ==="
    $supervisor = Invoke-CandidateImage `
        -Entrypoint "sh" `
        -CommandArguments @("-lc", "command -v supervisord >/dev/null && supervisord --version")
    $supervisor.Output
    if ($supervisor.ExitCode -ne 0) { $failed = $true }

    "=== Candidate platform requirements ==="
    $platform = Invoke-CandidateImage `
        -Entrypoint "composer" `
        -CommandArguments @("check-platform-reqs", "--no-dev", "--no-ansi")
    $platform.Output
    if ($platform.ExitCode -ne 0) { $failed = $true }

    $blockers = Invoke-CandidateImage `
        -Entrypoint "sh" `
        -CommandArguments @(
            "-lc",
            'export COMPOSER_HOME=/tmp/php82-composer-home && mkdir -p "$COMPOSER_HOME" && composer config --global repo.packagist false && COMPOSER_DISABLE_NETWORK=1 composer prohibits php 8.2 --locked --no-ansi --no-interaction'
        )
    $blockers.Output
    if ($blockers.ExitCode -ne 0 -or @($blockers.Lines | Where-Object { $_ -match '^\S+\s+\S+\s+requires php ' }).Count) {
        $failed = $true
    }

    "=== Candidate application lint ==="
    $lint = Invoke-CandidateLint
    $lint.Output
    if ($lint.ExitCode -ne 0) { $failed = $true }

    "=== Candidate runtime diagnostics ==="
    $deprecations = Invoke-DeprecationProbe `
        -MountedSourceRoot $null `
        -MountedVendorRoot $null `
        -ContainerSourceRoot "/var/www" `
        -UseCandidateImage
    Write-DeprecationResult -Result $deprecations
    if ($deprecations.ExitCode -ne 0) { $failed = $true }

    "=== Candidate offline integration compatibility ==="
    $integrationCompatibility = Invoke-CandidateIntegrationCompatibility
    $integrationCompatibility.Output
    if ($integrationCompatibility.ExitCode -ne 0) { $failed = $true }

    "=== Candidate static service discovery ==="
    foreach ($command in @("service:discover", "vendor:publish")) {
        $discovery = Invoke-CandidateImage `
            -Entrypoint "php" `
            -CommandArguments @("think", $command)
        $discovery.Output
        if ($discovery.ExitCode -ne 0) { $failed = $true }
    }

    "=== Candidate image compatibility ==="
    $imageCompatibility = Invoke-CandidateImageCompatibility
    $imageCompatibility.Output
    if ($imageCompatibility.ExitCode -ne 0) { $failed = $true }

    if ($failed) {
        "CANDIDATE_STATUS=RED"
        return 1
    }
    "CANDIDATE_STATUS=GREEN"
    return 0
}

if ($Mode -eq "SelfTest") {
    $lintSelfTest = Invoke-LintSelfTest
    if ($lintSelfTest.LintOutput) {
        $lintSelfTest.LintOutput
    }
    $lintSelfTest.Detail
    $scopeSelfTest = Invoke-DeprecationScopeSelfTest
    $scopeSelfTest.Detail
    if ($lintSelfTest.Passed -and $scopeSelfTest.Passed) {
        exit 0
    }
    exit 1
}

if ($Mode -eq "Candidate") {
    $candidateResult = @(Invoke-CandidateVerification)
    $candidateExitCode = [int]$candidateResult[-1]
    if ($candidateResult.Count -gt 1) {
        $candidateResult[0..($candidateResult.Count - 2)]
    }
    exit $candidateExitCode
}

if (-not (Test-Path -LiteralPath $VendorRoot -PathType Container)) {
    throw "Vendor root not found: $VendorRoot"
}
$VendorRoot = (Resolve-Path -LiteralPath $VendorRoot).Path

if ($Mode -eq "Integrity") {
    $integrity = Test-VendorIntegrity -MountedSourceRoot $SourceRoot -MountedVendorRoot $VendorRoot
    Write-IntegrityResult -Result $integrity
    exit $(if ($integrity.Passed) { 0 } else { 1 })
}

if ($Mode -eq "Lint") {
    if (-not $LintPath.Count) {
        $LintPath = @("app", "crmeb", "config", "route", "public")
    }
    $lint = Invoke-LintHarness -MountedSourceRoot $SourceRoot -MountedVendorRoot $VendorRoot -RelativePaths $LintPath
    $lint.Output
    exit $(if ($lint.ExitCode -eq 0) { 0 } else { 1 })
}

if ($Mode -eq "Deprecations") {
    $deprecations = Invoke-DeprecationProbe -MountedSourceRoot $SourceRoot -MountedVendorRoot $VendorRoot
    Write-DeprecationResult -Result $deprecations
    exit $(if ($deprecations.ExitCode -eq 0) { 0 } else { 1 })
}

$baselineFailed = $false
"=== PHP 8.2 runtime ==="
$runtimeVersion = Invoke-RuntimeVersionCheck
$runtimeVersion.Output
if ($runtimeVersion.ExitCode -ne 0) {
    $baselineFailed = $true
}
$composerVersion = Invoke-ComposerVersionCheck
$composerVersion.Output
if ($composerVersion.ExitCode -ne 0) {
    $baselineFailed = $true
}

"=== Composer PHP 8.2 blockers ==="
$composerBlockers = Invoke-ComposerBlockerCheck
$composerBlockers.Output
$blockerLines = @($composerBlockers.Lines | Where-Object { $_ -match '^\S+\s+\S+\s+requires php ' })
"COMPOSER_BLOCKERS=$($blockerLines.Count)"
if ($composerBlockers.ExitCode -ne 0 -or $blockerLines.Count) {
    $baselineFailed = $true
}

"=== Vendor integrity ==="
$integrity = Test-VendorIntegrity -MountedSourceRoot $SourceRoot -MountedVendorRoot $VendorRoot
Write-IntegrityResult -Result $integrity
if (-not $integrity.Passed) {
    $baselineFailed = $true
}

"=== Application PHP 8.2 lint ==="
$applicationLint = Invoke-LintHarness `
    -MountedSourceRoot $SourceRoot `
    -MountedVendorRoot $VendorRoot `
    -RelativePaths @("app", "crmeb", "config", "route", "public")
$applicationLint.Output
"APPLICATION_PARSE_FAILURES=$($applicationLint.Failures.Count)"
if ($applicationLint.ExitCode -ne 0) {
    $baselineFailed = $true
}

"=== Vendor PHP 8.2 lint ==="
$vendorLint = Invoke-LintHarness `
    -MountedSourceRoot $SourceRoot `
    -MountedVendorRoot $VendorRoot `
    -RelativePaths @("vendor")
$vendorLint.Output
$vendorParsePackages = @{}
foreach ($failure in $vendorLint.Failures) {
    if ($failure -match '^/workspace/vendor/([^/]+/[^/]+)/') {
        $packageName = $Matches[1]
    } else {
        $packageName = "unclassified"
    }
    if (-not $vendorParsePackages.ContainsKey($packageName)) {
        $vendorParsePackages[$packageName] = 0
    }
    $vendorParsePackages[$packageName]++
}
foreach ($packageName in @($vendorParsePackages.Keys | Sort-Object)) {
    "VENDOR_PARSE_FAILURES package=$packageName count=$($vendorParsePackages[$packageName])"
}
"VENDOR_PARSE_FAILURES_TOTAL=$($vendorLint.Failures.Count)"
if ($vendorLint.ExitCode -ne 0) {
    $baselineFailed = $true
}

"=== PHP 8.2 runtime diagnostics ==="
$deprecationProbe = Invoke-DeprecationProbe -MountedSourceRoot $SourceRoot -MountedVendorRoot $VendorRoot
Write-DeprecationResult -Result $deprecationProbe
if ($deprecationProbe.ExitCode -ne 0) {
    $baselineFailed = $true
}

if ($baselineFailed) {
    "BASELINE_STATUS=RED"
    exit 1
}

"BASELINE_STATUS=GREEN"
exit 0
