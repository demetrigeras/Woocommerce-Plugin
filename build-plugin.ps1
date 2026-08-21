param(
    [switch]$NoPause
)

$ErrorActionPreference = 'Stop'

Set-Location -Path $PSScriptRoot
Write-Host "Building plugin from: $PSScriptRoot"

$gitBashCandidates = @(
    "C:\Program Files\Git\bin\bash.exe",
    "C:\Program Files\Git\usr\bin\bash.exe"
)

$gitBash = $gitBashCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($gitBash) {
    Write-Host "Using Git Bash: $gitBash"
    & $gitBash -lc "cd '$($PSScriptRoot -replace '\\','/')' && chmod +x create-plugin-package.sh && ./create-plugin-package.sh"
    if ($LASTEXITCODE -ne 0) {
        throw "Build failed in Git Bash (exit code $LASTEXITCODE)."
    }
} else {
    throw "Git Bash not found. Install Git for Windows, then rerun this script."
}

Write-Host "Build complete."

if (-not $NoPause) {
    Write-Host ""
    Read-Host "Press Enter to close"
}
