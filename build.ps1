<#
.SYNOPSIS
    Builds the installable Joomla package pnkrindexnow-<version>.zip.

.DESCRIPTION
    Reads the version from pnkrindexnow.xml, zips the manifest, script.php,
    language/, services/ and src/ with forward-slash entry paths (Compress-Archive
    in Windows PowerShell 5.1 writes backslashes, which break installs on Linux
    hosts), then writes the zip's SHA-256 into the matching <update> in updates.xml.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\build.ps1
#>
param(
    # Skip writing the SHA-256 into updates.xml
    [switch]$NoUpdateXml
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

[xml]$manifest = Get-Content -Path (Join-Path $root 'pnkrindexnow.xml') -Raw -Encoding UTF8
$version = $manifest.extension.version.Trim()
if (-not $version) { throw 'No <version> found in pnkrindexnow.xml' }

$zipName = "pnkrindexnow-$version.zip"
$zipPath = Join-Path $root $zipName

$items = @('pnkrindexnow.xml', 'script.php', 'language', 'services', 'src')

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($item in $items) {
        $full = Join-Path $root $item
        if (-not (Test-Path $full)) { throw "Missing required path: $item" }

        if (Test-Path $full -PathType Container) {
            $files = Get-ChildItem -Path $full -Recurse -File
        } else {
            $files = @(Get-Item $full)
        }

        foreach ($file in $files) {
            $entryName = $file.FullName.Substring($root.Length + 1) -replace '\\', '/'
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal)
            Write-Host "  + $entryName"
        }
    }
} finally {
    $zip.Dispose()
}

$sha256 = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash.ToLower()

Write-Host ''
Write-Host "Built:   $zipName"
Write-Host "SHA-256: $sha256"

if (-not $NoUpdateXml) {
    $updatesPath = Join-Path $root 'updates.xml'
    $content = [System.IO.File]::ReadAllText($updatesPath)

    # Replace the <sha256> inside the <update> block whose <version> matches
    $pattern = "(?s)(<update>(?:(?!</update>).)*?<version>$([regex]::Escape($version))</version>(?:(?!</update>).)*?<sha256>)[^<]*(</sha256>)"
    if ($content -match $pattern) {
        $content = [regex]::Replace($content, $pattern, "`${1}$sha256`${2}")
        [System.IO.File]::WriteAllText($updatesPath, $content, (New-Object System.Text.UTF8Encoding($false)))
        Write-Host "Updated updates.xml sha256 for version $version"
    } else {
        Write-Warning "No <update> entry for version $version found in updates.xml - add one before releasing."
    }

    if ($content -notmatch [regex]::Escape("/$zipName<")) {
        Write-Warning "updates.xml has no downloadurl ending in $zipName"
    }
}
