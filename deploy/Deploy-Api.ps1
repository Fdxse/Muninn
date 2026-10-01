<#
.SYNOPSIS
    Copies the Muninn API from a release zip to the NAS. Run it yourself; nothing deploys automatically.

.DESCRIPTION
    The API is split in two so that only the front controller is reachable from the web:

      Web folder (PublicTarget)        e.g. \\NAS\web\muninn        -> /volume1/web/muninn
          index.php, .htaccess, app-location.php
      Application folder (AppTarget)   e.g. \\NAS\secrets\muninn    -> /volume1/secrets/muninn
          src, bin, migrations, vendor, config, storage

    1. Unpacks the release zip to a temporary folder.
    2. Copies api\public\* to the web folder.
    3. Copies everything else in api\ to the application folder.
    4. Writes app-location.php in the web folder so index.php can find the application folder
       (ServerAppPath is the path as the NAS itself sees it, e.g. /volume1/secrets/muninn).
    5. Never touches config\config.php or storage\ in the application folder, so server
       configuration, logs and attachments survive every deploy.

    Files deleted from a newer release are NOT removed from the targets (no mirroring), so a
    mistake can never wipe the server. Remove obsolete files by hand when DEPLOY.md says so.

.PARAMETER ZipPath
    Path to muninn-<version>.zip downloaded from GitHub.

.PARAMETER PublicTarget
    Web folder on the NAS as seen from Windows, e.g. \\NAS\web\muninn.

.PARAMETER AppTarget
    Application folder on the NAS as seen from Windows, e.g. \\NAS\secrets\muninn.
    Must NOT be inside any web root.

.PARAMETER ServerAppPath
    The same application folder as the NAS sees it, e.g. /volume1/secrets/muninn.

.PARAMETER WhatIf
    Show what would be copied without copying anything.

.EXAMPLE
    .\Deploy-Api.ps1 -ZipPath .\muninn-v0.1.0.zip `
        -PublicTarget \\NAS\web\muninn -AppTarget \\NAS\secrets\muninn `
        -ServerAppPath /volume1/secrets/muninn
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory = $true)][string]$ZipPath,
    [Parameter(Mandatory = $true)][string]$PublicTarget,
    [Parameter(Mandatory = $true)][string]$AppTarget,
    [Parameter(Mandatory = $true)][string]$ServerAppPath
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $ZipPath -PathType Leaf)) {
    throw "Release zip not found: $ZipPath"
}
foreach ($targetFolder in @($PublicTarget, $AppTarget)) {
    if (-not (Test-Path -LiteralPath $targetFolder -PathType Container)) {
        throw "Target folder not found (is the NAS share connected?): $targetFolder"
    }
}
# The server path is written into a PHP file, so allow only plain absolute Unix paths.
if ($ServerAppPath -notmatch '^/[A-Za-z0-9._/-]+$') {
    throw "ServerAppPath must be an absolute NAS path such as /volume1/secrets/muninn."
}

# Unpack into a fresh temporary folder.
$temporaryFolder = Join-Path ([System.IO.Path]::GetTempPath()) ("muninn-deploy-" + [guid]::NewGuid())
Expand-Archive -LiteralPath $ZipPath -DestinationPath $temporaryFolder

try {
    $releaseFolder = Get-ChildItem -LiteralPath $temporaryFolder -Directory | Select-Object -First 1
    $sourceApiFolder = Join-Path $releaseFolder.FullName 'api'
    if (-not (Test-Path -LiteralPath $sourceApiFolder)) {
        throw "The zip does not contain an api folder. Is this a Muninn release zip?"
    }

    $releaseVersion = Get-Content -LiteralPath (Join-Path $releaseFolder.FullName 'VERSION') -ErrorAction SilentlyContinue
    Write-Host "Deploying Muninn API $releaseVersion"
    Write-Host "  web folder:         $PublicTarget"
    Write-Host "  application folder: $AppTarget ($ServerAppPath on the NAS)"

    if ($PSCmdlet.ShouldProcess($PublicTarget, "Copy the API web files")) {
        $sourcePublicFolder = Join-Path $sourceApiFolder 'public'
        robocopy $sourcePublicFolder $PublicTarget /E /XF app-location.php /NFL /NDL /NJH /NP
        # Robocopy exit codes 0-7 mean success; 8 or higher means at least one copy failed.
        if ($LASTEXITCODE -ge 8) {
            throw "Robocopy reported a failure copying the web files (exit code $LASTEXITCODE)."
        }

        # Tell index.php where the application folder is. UTF-8 without BOM so PHP sends no stray bytes.
        $locationFileContent = "<?php`n`n// Written by Deploy-Api.ps1: where index.php finds the Muninn application folder.`nreturn '$ServerAppPath';`n"
        $locationFilePath = Join-Path $PublicTarget 'app-location.php'
        [System.IO.File]::WriteAllText($locationFilePath, $locationFileContent, (New-Object System.Text.UTF8Encoding $false))
    }

    if ($PSCmdlet.ShouldProcess($AppTarget, "Copy the API application files (excluding public\, config\config.php and storage\)")) {
        # /E copies subfolders; /XF and /XD protect server-side config and data; no /MIR, so nothing is deleted.
        $excludedPublicFolder = Join-Path $sourceApiFolder 'public'
        robocopy $sourceApiFolder $AppTarget /E /XF config.php /XD storage $excludedPublicFolder /NFL /NDL /NJH /NP
        if ($LASTEXITCODE -ge 8) {
            throw "Robocopy reported a failure copying the application files (exit code $LASTEXITCODE)."
        }

        # storage\ is excluded above so existing logs are never touched; create it on the first deploy.
        $storageFolder = Join-Path $AppTarget 'storage'
        if (-not (Test-Path -LiteralPath $storageFolder)) {
            New-Item -ItemType Directory -Path $storageFolder | Out-Null
        }

        $serverConfig = Join-Path $AppTarget 'config\config.php'
        if (-not (Test-Path -LiteralPath $serverConfig)) {
            Write-Warning "No config\config.php in the application folder yet. Copy config\config.example.php to config\config.php and fill it in (see DEPLOY.md)."
        }
    }
}
finally {
    Remove-Item -LiteralPath $temporaryFolder -Recurse -Force
}

Write-Host ""
Write-Host "Files copied. Now apply database migrations on the NAS over SSH:"
Write-Host "    cd $ServerAppPath && php bin/migrate.php"
Write-Host "Then check https://api.dx.se/api/v1/health (or https://fehre.synology.me/muninn/api/v1/health while staging)"
Write-Host "returns {""data"":{""status"":""ok""}}."

exit 0
