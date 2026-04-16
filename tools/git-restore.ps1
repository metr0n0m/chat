param(
    [Parameter(Mandatory = $true)]
    [string]$RemoteUrl,
    [string]$TargetPath = ".\\restored-chat",
    [string]$Branch = "main"
)

$ErrorActionPreference = "Stop"

git clone --branch $Branch $RemoteUrl $TargetPath | Out-Host
Write-Host "Cloned to $TargetPath"
