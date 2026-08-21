# Build a WordPress-friendly plugin zip from a staging folder.
# Compress-Archive uses backslash paths; Linux unzip leaves wrong paths — use / in entry names.
param(
    [Parameter(Mandatory)]
    [string]$SourceDir,
    [Parameter(Mandatory)]
    [string]$DestinationZip
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$srcFull = (Resolve-Path -LiteralPath $SourceDir).Path.TrimEnd('\')
# Use the source folder's name as the top-level entry inside the zip so the
# archive looks like "<folder>/<...files>" — WordPress's plugin installer
# requires this top-level folder to update/replace cleanly. Without it, WP
# falls back to inventing a folder from the zip filename and appends "-2",
# "-3", ... on each re-upload.
$folderName = Split-Path -Leaf $srcFull

if (Test-Path -LiteralPath $DestinationZip) {
    Remove-Item -LiteralPath $DestinationZip -Force
}

$zip = [System.IO.Compression.ZipFile]::Open($DestinationZip, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $srcFull -Recurse -File | ForEach-Object {
        $full = $_.FullName
        $rel = $full.Substring($srcFull.Length).TrimStart('\').Replace('\', '/')
        if ($rel -match '(^|/)\.git(/|$)' -or $rel -like '*.DS_Store' -or $rel -like '*/.DS_Store') {
            return
        }
        $entryPath = "$folderName/$rel"
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip,
            $full,
            $entryPath,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
} finally {
    $zip.Dispose()
}
