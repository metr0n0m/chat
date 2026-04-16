param(
    [Parameter(Mandatory = $true)]
    [string]$RemoteUrl,
    [string]$Branch = "main"
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

if (-not (Test-Path ".git")) {
    git init --initial-branch=$Branch | Out-Host
}

git config core.autocrlf true | Out-Host
git config pull.rebase false | Out-Host

$existingOrigin = git config --get remote.origin.url
$hasOrigin = $LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace($existingOrigin)

if ($hasOrigin) {
    git remote set-url origin $RemoteUrl | Out-Host
} else {
    git remote add origin $RemoteUrl | Out-Host
}

Write-Host "Repository is ready."
Write-Host "Origin: $RemoteUrl"
Write-Host "Branch: $Branch"
