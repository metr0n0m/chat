param(
    [string]$Message = "",
    [switch]$SkipPush
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

if (-not (Test-Path ".git")) {
    throw "Git repository is not initialized. Run tools/git-bootstrap.ps1 first."
}

git status --short --branch | Out-Host

if (-not $Message) {
    $Message = "chore: sync " + (Get-Date -Format "yyyy-MM-dd HH:mm")
}

git add -A | Out-Host

$hasChanges = $LASTEXITCODE -eq 0
$diff = git diff --cached --name-only
if (-not $diff) {
    Write-Host "No staged changes to commit."
    exit 0
}

git commit -m $Message | Out-Host

if (-not $SkipPush) {
    git push -u origin HEAD | Out-Host
}
