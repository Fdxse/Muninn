<#
.SYNOPSIS
    Copies the Muninn API from a release zip to the NAS. Run it yourself; nothing deploys automatically.

.DESCRIPTION
    1. Unpacks the release zip to a temporary folder.
    2. Copies the api\ folder to the target (for example the NAS share \\NAS\web\muninn-api).
    3. Never touches config\config.php or storage\ on the target, so server configuration,
       logs and attachments survive every deploy.
    4. Reminds you to run the database migrations on the NAS.

    Files deleted from a newer release are NOT removed from the target (no mirroring), so a
    mistake can never wipe the server. Remove obsolete files by hand when DEPLOY.md says so.

.PARAMETER ZipPath
    Path to muninn-<version>.zip downloaded from the GitHub release.

.PARAMETER TargetPath
    API folder on the NAS, e.g. \\NAS\web\muninn-api (its public\ subfolder is the web root).

.PARAMETER WhatIf
    Show what would be copied without copying anything.

.EXAMPLE
    .\Deploy-Api.ps1 -ZipPath .\muninn-v0.1.0.zip -TargetPath \\NAS\web\muninn-api
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory = $true)][string]$ZipPath,
    [Parameter(Mandatory = $true)][string]$TargetPath
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $ZipPath -PathType Leaf)) {
    throw "Release zip not found: $ZipPath"
}
if (-not (Test-Path -LiteralPath $TargetPath -PathType Container)) {
    throw "Target folder not found (is the NAS share connected?): $TargetPath"
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
    Write-Host "Deploying Muninn API $releaseVersion to $TargetPath"

    if ($PSCmdlet.ShouldProcess($TargetPath, "Copy API files (excluding config\config.php and storage\)")) {
        # /E copies subfolders; /XF and /XD protect server-side config and data; no /MIR, so nothing is deleted.
        robocopy $sourceApiFolder $TargetPath /E /XF config.php /XD storage /NFL /NDL /NJH /NP
        # Robocopy exit codes 0-7 mean success; 8 or higher means at least one copy failed.
        if ($LASTEXITCODE -ge 8) {
            throw "Robocopy reported a failure (exit code $LASTEXITCODE)."
        }

        $serverConfig = Join-Path $TargetPath 'config\config.php'
        if (-not (Test-Path -LiteralPath $serverConfig)) {
            Write-Warning "No config\config.php on the target yet. Copy config\config.example.php to config\config.php and fill it in (see DEPLOY.md)."
        }
    }
}
finally {
    Remove-Item -LiteralPath $temporaryFolder -Recurse -Force
}

Write-Host ""
Write-Host "Files copied. Now apply database migrations on the NAS over SSH:"
Write-Host "    cd <api folder on the NAS> && php bin/migrate.php"
Write-Host "Then check https://api.dx.se/api/v1/health returns {""data"":{""status"":""ok""}}."

exit 0
