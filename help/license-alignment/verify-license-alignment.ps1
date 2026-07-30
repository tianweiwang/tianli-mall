[CmdletBinding()]
param(
    [ValidateSet('All', 'License', 'Authorization', 'Branding', 'RemoteUpgrade', 'InstallData', 'Published')]
    [string]$Area = 'All'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$modulePath = Join-Path $PSScriptRoot 'LicenseAlignment.psm1'
Import-Module -Name $modulePath -Force -DisableNameChecking

$failures = [Collections.Generic.List[string]]::new()
$checks = [Collections.Generic.List[string]]::new()

function Add-Failure {
    param([Parameter(Mandatory)][string]$Message)
    $failures.Add($Message)
}

function Add-Check {
    param([Parameter(Mandatory)][string]$Message)
    $checks.Add($Message)
}

function Resolve-RepositoryPath {
    param([Parameter(Mandatory)][string]$RelativePath)
    Join-Path $repositoryRoot ($RelativePath.Replace('/', [IO.Path]::DirectorySeparatorChar))
}

function Read-RepositoryText {
    param([Parameter(Mandatory)][string]$RelativePath)

    $path = Resolve-RepositoryPath -RelativePath $RelativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        Add-Failure "missing required file: $RelativePath"
        return $null
    }
    [IO.File]::ReadAllText($path)
}

function Assert-TextContains {
    param(
        [Parameter(Mandatory)][string]$RelativePath,
        [Parameter(Mandatory)][string[]]$RequiredText
    )

    $text = Read-RepositoryText -RelativePath $RelativePath
    if ($null -eq $text) {
        return
    }
    foreach ($value in $RequiredText) {
        if (-not $text.Contains($value, [StringComparison]::Ordinal)) {
            Add-Failure "$RelativePath is missing required text: $value"
        }
    }
}

function Assert-TextExcludes {
    param(
        [Parameter(Mandatory)][string]$RelativePath,
        [Parameter(Mandatory)][string[]]$ForbiddenText
    )

    $text = Read-RepositoryText -RelativePath $RelativePath
    if ($null -eq $text) {
        return
    }
    foreach ($value in $ForbiddenText) {
        if ($text.Contains($value, [StringComparison]::Ordinal)) {
            Add-Failure "$RelativePath contains forbidden text: $value"
        }
    }
}

function Assert-RegexExcludes {
    param(
        [Parameter(Mandatory)][string]$RelativePath,
        [Parameter(Mandatory)][string[]]$Patterns
    )

    $text = Read-RepositoryText -RelativePath $RelativePath
    if ($null -eq $text) {
        return
    }
    foreach ($pattern in $Patterns) {
        if ($text -match $pattern) {
            Add-Failure "$RelativePath matches forbidden pattern: $pattern"
        }
    }
}

function Assert-PathAbsent {
    param([Parameter(Mandatory)][string]$RelativePath)
    if (Test-Path -LiteralPath (Resolve-RepositoryPath -RelativePath $RelativePath)) {
        Add-Failure "path must be absent: $RelativePath"
    }
}

function Assert-PathPresent {
    param([Parameter(Mandatory)][string]$RelativePath)
    if (-not (Test-Path -LiteralPath (Resolve-RepositoryPath -RelativePath $RelativePath))) {
        Add-Failure "path must exist: $RelativePath"
    }
}

function Test-LicenseArea {
    $oldText = 'Licensed CRMEB' + '并不是自由软件，未经许可不能去掉CRMEB相关版权'
    $newText = 'Licensed under the Apache License' + ', Version 2.0'
    $copyrightText = 'Copyright ' + '(c)'
    $authorText = 'Author: CRMEB' + ' Team'
    $oldMatches = @(Get-RestrictiveLicenseHeaderMatches -RepositoryRoot $repositoryRoot)
    $newHeaderFiles = [Collections.Generic.List[string]]::new()
    $tracked = @(Get-ProjectOwnedTrackedFiles -RepositoryRoot $repositoryRoot)

    foreach ($relativePath in $tracked) {
        $path = Resolve-RepositoryPath -RelativePath $relativePath
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            continue
        }
        try {
            $text = [IO.File]::ReadAllText($path)
        } catch {
            continue
        }
        if ($text.Contains($newText, [StringComparison]::Ordinal) -and
            $text.Contains($copyrightText, [StringComparison]::OrdinalIgnoreCase) -and
            $text.Contains($authorText, [StringComparison]::Ordinal)) {
            $newHeaderFiles.Add($relativePath)
        }
    }

    $oldHeaderFiles = @($oldMatches | ForEach-Object { $_.RelativePath })
    $headerFiles = $oldHeaderFiles + @($newHeaderFiles)
    if ($headerFiles.Count -ne 1242) {
        Add-Failure "project-owned license header inventory changed: expected=1242 actual=$($headerFiles.Count)"
    }
    if ($oldMatches.Count -gt 0) {
        Add-Failure "restrictive project-owned license headers remain: files=$($oldMatches.Count) matches=$(($oldMatches | Measure-Object -Property MatchCount -Sum).Sum)"
    }
    foreach ($match in $oldMatches) {
        if ($match.MatchCount -ne 1) {
            Add-Failure "restrictive header count is ambiguous: $($match.RelativePath) count=$($match.MatchCount)"
        }
    }

    foreach ($relativePath in @($headerFiles | Sort-Object -Unique)) {
        $text = [IO.File]::ReadAllText((Resolve-RepositoryPath -RelativePath $relativePath))
        if (-not $text.Contains($copyrightText, [StringComparison]::OrdinalIgnoreCase)) {
            Add-Failure "copyright attribution missing beside project header: $relativePath"
        }
        if (-not $text.Contains($authorText, [StringComparison]::Ordinal)) {
            Add-Failure "CRMEB author attribution missing beside project header: $relativePath"
        }
    }

    $rootLicense = Resolve-RepositoryPath -RelativePath 'LICENSE'
    foreach ($relativePath in @('crmeb/LICENSE.txt', 'help/resource/LICENSE.txt')) {
        $path = Resolve-RepositoryPath -RelativePath $relativePath
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            Add-Failure "missing project license copy: $relativePath"
            continue
        }
        if ((Get-FileHash -LiteralPath $rootLicense -Algorithm SHA256).Hash -cne (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash) {
            Add-Failure "$relativePath is not a byte-identical Apache-2.0 license copy"
        }
    }

    Assert-TextExcludes -RelativePath 'crmeb/public/install/templates/step1.php' -ForbiddenText @(
        '购买商业许可',
        '唯一域名',
        '不得修改',
        '不得分发',
        '终止使用许可'
    )
    Assert-TextContains -RelativePath 'crmeb/public/install/templates/step1.php' -RequiredText @(
        'Apache License',
        '商用',
        '修改',
        '分发',
        '担保',
        '商标',
        'LICENSE'
    )
    Assert-TextExcludes -RelativePath 'crmeb/public/install/templates/step5.php' -ForbiddenText @(
        'shop.crmeb.net',
        '$.ajax',
        'ip.php'
    )

    Add-Check "License inventory checked: old=$($oldMatches.Count) new=$($newHeaderFiles.Count) total=$($headerFiles.Count)"
}

function Test-AuthorizationArea {
    Assert-RegexExcludes -RelativePath 'crmeb/app/adminapi/controller/Common.php' -Patterns @(
        'function\s+auth\s*\(',
        'function\s+auth_apply\s*\('
    )
    Assert-RegexExcludes -RelativePath 'crmeb/app/adminapi/route/common.php' -Patterns @(
        'Route::(?:get|post)\(\s*[''"](?:check_auth|auth_apply|auth)[''"]'
    )
    foreach ($relativePath in @(
        'crmeb/app/services/system/SystemAuthServices.php',
        'crmeb/app/adminapi/controller/v1/system/SystemUpgradeClient.php',
        'crmeb/crmeb/services/UpgradeService.php',
        'crmeb/crmeb/services/UpgradeOpenApiService.php'
    )) {
        Assert-PathAbsent -RelativePath $relativePath
    }
    Assert-RegexExcludes -RelativePath 'template/admin/src/pages/index/index.vue' -Patterns @(
        'import\s*\{[^}]*\bauth\b',
        'this\.getAuth\s*\(',
        '\bgetAuth\s*\([^)]*\)\s*\{'
    )
    Assert-RegexExcludes -RelativePath 'template/admin/src/api/system.js' -Patterns @(
        'export\s+function\s+auth\s*\(',
        'export\s+function\s+authApply\s*\(',
        'export\s+function\s+crmebProduct\s*\('
    )
    Add-Check 'Commercial authorization backend, route, and dashboard source checked'
}

function Test-BrandingArea {
    $sourceFiles = @(
        'template/admin/src/components/copyright/index.vue',
        'template/admin/src/pages/account/login/index.vue',
        'template/admin/src/pages/kefu/index.vue',
        'template/admin/src/pages/kefu/appChat/index.vue',
        'template/admin/src/pages/index/index.vue',
        'template/admin/src/api/system.js',
        'template/admin/src/router/modules/system.js',
        'template/uni-app/pages/index/index.vue',
        'template/uni-app/pages/annex/special/index.vue',
        'template/uni-app/pages/user/index.vue',
        'template/uni-app/pages/users/login/index.vue'
    )
    $forbidden = @(
        '商业授权',
        'authorize.crmeb.net',
        'shop.crmeb.net',
        'CRMEB提供技术支持',
        'static/images/support.png',
        'Copyright ©2024 CRMEB',
        'Copyright © 2014-2025'
    )
    foreach ($relativePath in $sourceFiles) {
        Assert-TextExcludes -RelativePath $relativePath -ForbiddenText $forbidden
    }

    Assert-PathAbsent -RelativePath 'template/admin/src/pages/system/auth/index.vue'
    Assert-PathPresent -RelativePath 'template/admin/src/pages/system/info/index.vue'
    Assert-PathAbsent -RelativePath 'template/uni-app/static/images/support.png'
    Assert-TextContains -RelativePath 'template/admin/src/router/modules/system.js' -RequiredText @(
        "path: 'maintain/info'",
        "auth: ['system-maintain-info']",
        "title: '系统信息'"
    )
    Assert-TextExcludes -RelativePath 'template/admin/src/pages/system/info/index.vue' -ForbiddenText @(
        'authorize.crmeb.net',
        'shop.crmeb.net',
        'authApply',
        'crmebProduct',
        'captchs',
        '<iframe'
    )
    Add-Check 'System information and source-level branding fallbacks checked'
}

function Test-RemoteUpgradeArea {
    Assert-TextContains -RelativePath 'crmeb/config/upgrade.php' -RequiredText @(
        "Env::get('upgrade.remote_enabled', false)",
        "'remote'"
    )
    Assert-TextContains -RelativePath 'crmeb/app/services/system/UpgradeServices.php' -RequiredText @(
        'function isRemoteEnabled()',
        'function assertRemoteEnabled()',
        'function getRemoteUrl(string $name)',
        '官方在线升级服务未启用',
        '官方在线升级服务地址必须使用 HTTPS'
    )
    Assert-TextExcludes -RelativePath 'crmeb/app/services/system/UpgradeServices.php' -ForbiddenText @(
        'http://upgrade.crmeb.net'
    )

    $serviceText = Read-RepositoryText -RelativePath 'crmeb/app/services/system/UpgradeServices.php'
    if ($null -ne $serviceText -and $serviceText -match '(?s)function\s+__construct\s*\([^)]*\)\s*\{(?<body>.*?)\n\s*\}\s*\n\s*/\*\*') {
        $constructorBody = $Matches.body
        foreach ($forbidden in @('getVersion(', 'upgrade_auth_token', 'getAuth(', 'HttpService::')) {
            if ($constructorBody.Contains($forbidden, [StringComparison]::Ordinal)) {
                Add-Failure "UpgradeServices constructor contains remote state access: $forbidden"
            }
        }
    } else {
        Add-Failure 'Unable to inspect UpgradeServices constructor'
    }

    Assert-TextContains -RelativePath 'crmeb/app/adminapi/controller/UpgradeController.php' -RequiredText @(
        'function capability()',
        "'remote_enabled'"
    )
    Assert-TextContains -RelativePath 'crmeb/app/adminapi/route/system.php' -RequiredText @(
        "Route::get('upgrade/capability'"
    )
    Assert-TextContains -RelativePath 'template/admin/src/api/system.js' -RequiredText @(
        'function upgradeCapabilityApi()'
    )
    Assert-TextContains -RelativePath 'template/admin/src/pages/system/onlineUpgrade/index.vue' -RequiredText @(
        'remoteEnabled',
        'upgradeCapabilityApi'
    )
    foreach ($relativePath in @('help/docker/docker-compose.yml', 'help/docker/docker-compose.optimized.yml')) {
        Assert-TextContains -RelativePath $relativePath -RequiredText @('UPGRADE_REMOTE_ENABLED')
    }
    Assert-TextContains -RelativePath 'help/docker/.env.application.example' -RequiredText @(
        '[UPGRADE]',
        'REMOTE_ENABLED = false'
    )
    Add-Check 'Default-off remote-upgrade source contract checked'
}

function Test-InstallDataArea {
    Assert-TextExcludes -RelativePath 'crmeb/public/install/crmeb.sql' -ForbiddenText @(
        'auth_cert_query',
        'auth_apply',
        'cert_crmeb',
        "'400330'",
        "'400331'",
        "'400332'",
        "'400333'",
        "'400334'",
        "'400335'",
        '/public/static/images/support.png',
        '/public/statics/mp_view/static/images/support.png',
        'SystemAuthServices.php',
        'SystemUpgradeClient.php',
        'UpgradeOpenApiService.php'
    )
    Assert-TextContains -RelativePath 'crmeb/public/install/crmeb.sql' -RequiredText @(
        "(605, 104, 0, '系统信息'",
        "'/system/maintain/info'",
        "'system-maintain-info'"
    )
    Assert-TextExcludes -RelativePath 'crmeb/app/adminapi/controller/UpgradeController.php' -ForbiddenText @(
        'SystemAuthServices.php',
        'SystemUpgradeClient.php',
        'UpgradeOpenApiService.php'
    )
    Assert-PathPresent -RelativePath 'crmeb/crmeb/command/LicenseAlignmentCleanup.php'
    Assert-TextContains -RelativePath 'crmeb/config/console.php' -RequiredText @('LicenseAlignmentCleanup')
    Add-Check 'Fresh-install and existing-environment cleanup metadata checked'
}

function Test-PublishedArea {
    $roots = @(
        'crmeb/public/admin',
        'crmeb/public/static',
        'crmeb/public/statics/mp_view'
    )
    $extensions = @('.js', '.html', '.htm', '.json', '.wxml', '.wxss', '.css')
    $forbidden = @(
        'authorize.crmeb.net',
        'shop.crmeb.net',
        'auth_cert_query',
        'auth_apply',
        'cert_crmeb',
        'CRMEB提供技术支持',
        'static/images/support.png',
        'Copyright ©2024 CRMEB',
        'Copyright © 2014-2025'
    )

    foreach ($root in $roots) {
        $rootPath = Resolve-RepositoryPath -RelativePath $root
        if (-not (Test-Path -LiteralPath $rootPath -PathType Container)) {
            Add-Failure "published root is missing: $root"
            continue
        }
        foreach ($file in Get-ChildItem -LiteralPath $rootPath -Recurse -File) {
            if ($extensions -notcontains $file.Extension.ToLowerInvariant()) {
                continue
            }
            $text = [IO.File]::ReadAllText($file.FullName)
            foreach ($value in $forbidden) {
                if ($text.Contains($value, [StringComparison]::Ordinal)) {
                    $relativePath = [IO.Path]::GetRelativePath($repositoryRoot, $file.FullName).Replace('\', '/')
                    Add-Failure "$relativePath contains published forbidden text: $value"
                }
            }
        }
    }

    foreach ($relativePath in @(
        'crmeb/public/static/images/support.png',
        'crmeb/public/statics/mp_view/static/images/support.png'
    )) {
        Assert-PathAbsent -RelativePath $relativePath
    }
    foreach ($staleName in @('app.dc1bf06a.js', 'chunk-55fbd112.733791df.js')) {
        $matches = @(Get-ChildItem -LiteralPath (Resolve-RepositoryPath -RelativePath 'crmeb/public/admin') -Recurse -File -Filter $staleName)
        if ($matches.Count -gt 0) {
            Add-Failure "stale management asset remains: $staleName"
        }
    }
    Add-Check 'Active management, H5, and mini-program assets checked'
}

$areas = if ($Area -eq 'All') {
    @('License', 'Authorization', 'Branding', 'RemoteUpgrade', 'InstallData', 'Published')
} else {
    @($Area)
}

foreach ($selectedArea in $areas) {
    switch ($selectedArea) {
        'License' { Test-LicenseArea }
        'Authorization' { Test-AuthorizationArea }
        'Branding' { Test-BrandingArea }
        'RemoteUpgrade' { Test-RemoteUpgradeArea }
        'InstallData' { Test-InstallDataArea }
        'Published' { Test-PublishedArea }
    }
}

foreach ($check in $checks) {
    "[CHECK] $check"
}
if ($failures.Count -gt 0) {
    foreach ($failure in $failures) {
        "[FAIL] $failure"
    }
    "Compliance: $($failures.Count) failure(s)"
    exit 1
}

"Compliance: pass ($($checks.Count) area check(s))"
