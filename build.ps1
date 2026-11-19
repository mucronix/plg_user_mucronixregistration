<#
.SYNOPSIS
    Builds the installable package of plg_user_mucronixregistration.

.DESCRIPTION
    The package is the source files as they are; nothing is generated or rewritten on the way.
    Every file of the package is named in $packageFiles below. A file that lies in a package
    folder but is not on the list stops the build, the same as a file on the list that is missing.

    Results:
      build/plg_user_mucronixregistration/               the files of the package, unpacked;
                                                         the checks in tests/ can read it
      build/plg_user_mucronixregistration-<version>.zip  the archive to install

    The archive is reproducible: the same files give the same archive byte for byte. The order of
    the entries is the order of the list, and every entry has the same constant time stamp.

    The build stops, and leaves no archive, when:
      - the version differs between the manifest, media/joomla.asset.json and the update file
        (number, tag and archive name in the download address), or creationDate is not a date;
      - the manifest does not name a file of the package, names one that is not there, or its
        namespace, plugin element or update server is not the expected one;
      - a PHP file has no licence header or no defined('_JEXEC') line, or does not pass php -l;
      - the install SQL is not what tools/build-regions.php gives from tools/source/;
      - a banned word is in the package (the install SQL aside: country codes are not words)
        or in the other files that are published with the source;
      - tests/language-check.php or tests/logic-check.php fails on the built files.

.PARAMETER Php
    PHP 8.3 executable. Default: the one of OSPanel.

.PARAMETER Joomla
    Joomla 6 site whose libraries the checks in tests/ load, read only. Default: joomla6 next to
    the project folder.

.PARAMETER SkipTests
    Leaves out the checks in tests/. Not for a release.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File build.ps1
#>
param(
    [string]$Php = 'D:\OSPanel1\modules\PHP-8.3\php.exe',
    [string]$Joomla = '',
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root   = $PSScriptRoot
$utf8   = New-Object System.Text.UTF8Encoding($false)
$name   = 'plg_user_mucronixregistration'
$output = Join-Path $root 'build'
$tree   = Join-Path $output $name

if (-not $Joomla) { $Joomla = Join-Path (Split-Path $root -Parent) 'joomla6' }

# Every file of the package, in the order of the archive.
$packageFiles = @(
    'mucronixregistration.xml'
    'script.php'
    'LICENSE.txt'
    'LICENSE-data.txt'
    'services/provider.php'
    'src/Extension/MucronixRegistration.php'
    'src/Service/RegionDirectory.php'
    'src/Field/MucronixcountryField.php'
    'src/Field/MucronixregionField.php'
    'src/Field/MucronixcountriesField.php'
    'src/Rule/MucronixcountrycodesRule.php'
    'forms/mucronixregistration.xml'
    'language/en-GB/plg_user_mucronixregistration.ini'
    'language/en-GB/plg_user_mucronixregistration.sys.ini'
    'language/uk-UA/plg_user_mucronixregistration.ini'
    'language/uk-UA/plg_user_mucronixregistration.sys.ini'
    'language/ru-RU/plg_user_mucronixregistration.ini'
    'language/ru-RU/plg_user_mucronixregistration.sys.ini'
    'media/joomla.asset.json'
    'media/js/regions.js'
    'sql/install.mysql.utf8.sql'
    'sql/uninstall.mysql.utf8.sql'
    'sql/updates/mysql/1.0.0.sql'
)

# Folders that hold package files only: anything else in them stops the build.
$packageFolders = @('services', 'src', 'forms', 'language', 'media', 'sql')

# Files published with the source but not packaged. Read for banned words as strictly as the package.
$publishedFiles = @(
    'README.md'
    'build.ps1'
    '.gitignore'
    '.gitattributes'
    'update/plg_user_mucronixregistration.xml'
    'tools/build-regions.php'
    'tests/bootstrap.php'
    'tests/language-check.php'
    'tests/logic-check.php'
    'tests/registration-test.php'
    'tests/site-db.php'
    'tests/snapshot.php'
    'tests/make-broken.php'
)

$namespace    = 'Mucronix\Plugin\User\MucronixRegistration'
$updateServer = 'https://raw.githubusercontent.com/mucronix/plg_user_mucronixregistration/main/update/plg_user_mucronixregistration.xml'
$downloadUrl  = 'https://github.com/mucronix/plg_user_mucronixregistration/releases/download/v{0}/plg_user_mucronixregistration-{0}.zip'
$licenceLine  = '@license     GNU General Public License version 2 or later'

# Banned words, matched case-sensitively as regular expressions. Stored encoded so that this file,
# which is published too, does not itself contain them. Decode one to read it:
#   [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('...'))
$bannedEncoded = @(
    'Sm9vbUZhbg=='
    'am9vbWZhbg=='
    'Sk9PTUZBTg=='
    'amZyZWc='
    'SmZyZWc='
    'SkZSRUc='
    'REotQ2xhc3NpZmllZHM='
    'REpDbGFzc2lmaWVkcw=='
    'ZGpjbGFzc2lmaWVkcw=='
    'ZGpjZg=='
    'Q2xhdWRl'
    'QW50aHJvcGlj'
    'Q2hhdEdQVA=='
    'T3BlbkFJ'
    'Q29waWxvdA=='
    'XGJBSVxi'
    'XGLQmNCYXGI='
    'XGLQqNCGXGI='
)

# The install SQL holds ISO codes, among them the code of Anguilla; it is generated and checked separately.
$bannedExempt = @('sql/install.mysql.utf8.sql')

function Stop-Build([string[]]$problems) {
    [Console]::Error.WriteLine('nothing was built:')
    foreach ($problem in $problems) { [Console]::Error.WriteLine('  ' + $problem) }
    exit 1
}

function Read-Text([string]$path) {
    return ([System.IO.File]::ReadAllText($path, $utf8) -replace "`r`n", "`n")
}

# The files of a folder as package paths: relative, with forward slashes.
function Get-Listing([string]$folder) {
    if (-not (Test-Path $folder)) { return @() }
    $base = (Resolve-Path $folder).Path
    return @(Get-ChildItem $base -Recurse -File -Force | ForEach-Object { $_.FullName.Substring($base.Length + 1).Replace('\', '/') })
}

function Test-BannedWords([string]$what, $texts) {
    $problems = @()
    $patterns = @($bannedEncoded | ForEach-Object { $utf8.GetString([Convert]::FromBase64String($_)) })
    $pattern  = '(?-i)(?:' + ($patterns -join '|') + ')'

    foreach ($file in $texts.Keys) {
        if ($bannedExempt -contains $file) { continue }

        if ($file -cmatch $pattern) { $problems += "$what, the name of ${file}: holds a banned word" }

        $number = 0
        foreach ($line in $texts[$file].Split("`n")) {
            $number++
            if ($line -cmatch $pattern) { $problems += "$what, ${file}:$number holds the banned word '$($Matches[0])'" }
        }
    }

    return $problems
}

# Every entry of an archive as name => text.
function Read-Archive([string]$zipPath) {
    $texts = [ordered]@{}
    $zip   = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        foreach ($entry in $zip.Entries) {
            $reader = New-Object System.IO.StreamReader($entry.Open(), $utf8)
            try { $texts[$entry.FullName] = $reader.ReadToEnd() } finally { $reader.Dispose() }
        }
    } finally { $zip.Dispose() }

    return $texts
}

# What the manifest names in <files> and <media> against the list of package files.
function Test-ManifestFiles([xml]$manifest) {
    $problems = @()
    $covered  = @('mucronixregistration.xml', [string]$manifest.extension.scriptfile)

    foreach ($node in $manifest.extension.files.ChildNodes) {
        if ($node.NodeType -ne 'Element') { continue }
        $path = $node.InnerText.Trim()
        if ($node.LocalName -eq 'filename') {
            if ($packageFiles -notcontains $path) { $problems += "the manifest names the file $path, which is not in the package" }
            $covered += $path
        } elseif ($node.LocalName -eq 'folder') {
            $inside = @($packageFiles | Where-Object { $_.StartsWith("$path/") })
            if (-not $inside) { $problems += "the manifest names the folder $path, which has no package file" }
            $covered += $inside
        }
    }

    $media = $manifest.extension.media
    if ($media.GetAttribute('folder') -cne 'media' -or $media.GetAttribute('destination') -cne $name) { $problems += "the manifest media element is not folder=media destination=$name" }
    foreach ($node in $media.ChildNodes) {
        if ($node.NodeType -ne 'Element') { continue }
        $path = 'media/' + $node.InnerText.Trim()
        if ($node.LocalName -eq 'filename') {
            if ($packageFiles -notcontains $path) { $problems += "the manifest names the media file $path, which is not in the package" }
            $covered += $path
        } else {
            $inside = @($packageFiles | Where-Object { $_.StartsWith("$path/") })
            if (-not $inside) { $problems += "the manifest names the media folder $path, which has no package file" }
            $covered += $inside
        }
    }

    foreach ($file in $packageFiles) {
        if ($covered -notcontains $file) { $problems += "$file is in the package, but the manifest does not install it" }
    }

    $sqlFiles = @($manifest.SelectNodes('//install/sql/file | //uninstall/sql/file') | ForEach-Object { $_.InnerText.Trim() })
    foreach ($file in $sqlFiles) {
        if ($packageFiles -notcontains $file) { $problems += "the manifest runs $file, which is not in the package" }
    }

    $schema = [string]$manifest.extension.update.schemas.schemapath.InnerText
    if (-not @($packageFiles | Where-Object { $_.StartsWith("$($schema.Trim())/") })) { $problems += "the schema path $schema has no update file" }

    return $problems
}

$problems    = @()
$testResults = @()

# --- the list against the disk
foreach ($file in $packageFiles) {
    if (-not (Test-Path (Join-Path $root $file) -PathType Leaf)) { $problems += "$file is on the list but missing" }
}
foreach ($folder in $packageFolders) {
    foreach ($file in Get-Listing (Join-Path $root $folder)) {
        $path = "$folder/$file"
        if ($packageFiles -notcontains $path) { $problems += "$path lies in a package folder but is not on the list" }
    }
}
foreach ($file in $publishedFiles) {
    if (-not (Test-Path (Join-Path $root $file) -PathType Leaf)) { $problems += "$file, which is published with the source, is missing" }
}
if ($problems) { Stop-Build $problems }

# --- versions
[xml]$manifest = Read-Text (Join-Path $root 'mucronixregistration.xml')
$version = [string]$manifest.extension.version

if ($version -cnotmatch '^\d+\.\d+\.\d+$') { $problems += "the manifest version '$version' is not X.Y.Z" }
if ([string]$manifest.extension.creationDate -cnotmatch '^\d{4}-\d{2}-\d{2}$') { $problems += "creationDate '$($manifest.extension.creationDate)' is not YYYY-MM-DD" }

$asset = Read-Text (Join-Path $root 'media/joomla.asset.json') | ConvertFrom-Json
if ([string]$asset.version -cne $version) { $problems += "media/joomla.asset.json says $($asset.version), the manifest $version" }

[xml]$updates = Read-Text (Join-Path $root 'update/plg_user_mucronixregistration.xml')
$update = $updates.updates.update
if ([string]$update.version -cne $version) { $problems += "the update file says $($update.version), the manifest $version" }
if ($update.downloads.downloadurl.InnerText.Trim() -cne ($downloadUrl -f $version)) { $problems += "the download address in the update file is not $($downloadUrl -f $version)" }
if ([string]$update.element -cne 'mucronixregistration' -or [string]$update.type -cne 'plugin' -or [string]$update.folder -cne 'user') { $problems += 'the update file does not describe the plugin user/mucronixregistration' }
$sum = @($updates.SelectNodes('//update/sha256'))
if ($sum.Count -gt 0 -and $sum[0].InnerText.Trim() -cnotmatch '^[0-9a-f]{64}$') { $problems += 'the update file holds a sha256 that is not a checksum: an empty one makes Joomla refuse the update' }

# --- the manifest
if ([string]$manifest.extension.type -cne 'plugin' -or [string]$manifest.extension.group -cne 'user') { $problems += 'the manifest is not a user plugin' }
if ($manifest.extension.namespace.InnerText.Trim() -cne $namespace -or [string]$manifest.extension.namespace.path -cne 'src') { $problems += "the namespace is not $namespace in src" }
$element = @($manifest.extension.files.folder | Where-Object { $_.plugin })
if ($element.Count -ne 1 -or [string]$element[0].plugin -cne 'mucronixregistration' -or $element[0].InnerText -cne 'services') { $problems += 'the plugin element is not services with plugin="mucronixregistration"' }
$server = @($manifest.extension.updateservers.server)
if ($server.Count -ne 1 -or $server[0].InnerText.Trim() -cne $updateServer) { $problems += "the update server is not $updateServer" }
$problems += Test-ManifestFiles $manifest

# --- PHP files
$phpFiles = @($packageFiles | Where-Object { $_ -like '*.php' })
foreach ($file in $phpFiles) {
    $text = Read-Text (Join-Path $root $file)
    if (-not $text.Contains($licenceLine)) { $problems += "$file has no licence header" }
    if (-not $text.Contains("defined('_JEXEC') or die;")) { $problems += "$file has no defined('_JEXEC') or die; line" }
}

if (-not (Test-Path $Php)) {
    $problems += "PHP not found at $Php, pass -Php"
} else {
    foreach ($file in $phpFiles + @('tools/build-regions.php', 'tests/bootstrap.php', 'tests/language-check.php', 'tests/logic-check.php')) {
        $lint = & $Php -l (Join-Path $root $file) 2>&1
        if ($LASTEXITCODE -ne 0) { $problems += "php -l $file`: $($lint -join ' ')" }
    }

    # --- the install SQL is the generated one
    $generated = Join-Path ([System.IO.Path]::GetTempPath()) "$name-install-check.sql"
    $log = & $Php (Join-Path $root 'tools/build-regions.php') "--output=$generated" 2>&1
    if ($LASTEXITCODE -ne 0) {
        $problems += "tools/build-regions.php failed: $($log -join ' ')"
    } else {
        $want = (Get-FileHash $generated -Algorithm SHA256).Hash
        $have = (Get-FileHash (Join-Path $root 'sql/install.mysql.utf8.sql') -Algorithm SHA256).Hash
        if ($want -cne $have) { $problems += 'sql/install.mysql.utf8.sql is not what tools/build-regions.php gives: run it, do not edit the SQL by hand' }
    }
    if (Test-Path $generated) { Remove-Item $generated -Force -Confirm:$false }
}

# --- banned words in what is published beside the package
$published = [ordered]@{}
foreach ($file in $publishedFiles) { $published[$file] = Read-Text (Join-Path $root $file) }
$problems += Test-BannedWords 'the published source' $published

if ($problems) { Stop-Build $problems }

# --- the tree
if (Test-Path $tree) { Remove-Item $tree -Recurse -Force -Confirm:$false }
foreach ($file in $packageFiles) {
    $target = Join-Path $tree $file
    $folder = Split-Path $target
    if (-not (Test-Path $folder)) { New-Item -ItemType Directory -Force $folder | Out-Null }
    [System.IO.File]::Copy((Join-Path $root $file), $target, $true)
}

# --- the archive. Entry names are written by hand, with forward slashes: Compress-Archive of
# Windows PowerShell 5.1 writes backslashes, and such an archive unpacks badly on a Linux server.
# The time is set before the entry is opened: the header goes out with the first byte.
$zipPath = Join-Path $output "$name-$version.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force -Confirm:$false }

$stamp = New-Object System.DateTimeOffset(2026, 1, 1, 0, 0, 0, [System.TimeSpan]::Zero)
$zip   = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $packageFiles) {
        $entry               = $zip.CreateEntry($file, [System.IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = $stamp

        $bytes  = [System.IO.File]::ReadAllBytes((Join-Path $tree $file))
        $stream = $entry.Open()
        try { $stream.Write($bytes, 0, $bytes.Length) } finally { $stream.Dispose() }
    }
} finally { $zip.Dispose() }

# --- read the finished archive back: what is in it is what ships
$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try { $entries = @($zip.Entries | ForEach-Object { [pscustomobject]@{ Name = $_.FullName; Size = $_.Length } }) } finally { $zip.Dispose() }

$packed = Read-Archive $zipPath
$names  = @($entries | ForEach-Object Name)
if (($names -join "`n") -cne ($packageFiles -join "`n")) { $problems += 'the archive does not hold exactly the listed files in the listed order' }
if ([string]([xml]$packed['mucronixregistration.xml']).extension.version -cne $version) { $problems += "the manifest in the archive does not say $version" }
if ([string]($packed['media/joomla.asset.json'] | ConvertFrom-Json).version -cne $version) { $problems += "media/joomla.asset.json in the archive does not say $version" }
$problems += Test-BannedWords 'the archive' $packed

# --- the checks in tests/, on the built files
if (-not $SkipTests -and -not $problems) {
    foreach ($check in @('tests/language-check.php', 'tests/logic-check.php')) {
        $result = & $Php (Join-Path $root $check) "--joomla=$Joomla" "--plugin=$tree" 2>&1
        if ($LASTEXITCODE -ne 0) {
            $problems += "$check failed:"
            $problems += @($result | Where-Object { "$_" -match 'FAIL|got:|want:|not found|Error' } | ForEach-Object { "    $_" })
        } else {
            $summary = @($result | Where-Object { "$_" -match '^passed ' })
            $testResults += @("$check - $($summary[-1])")
        }
    }
}

# A refused archive does not stay behind: nothing named like a release may be a failed build
if ($problems) { Remove-Item $zipPath -Force -Confirm:$false; Stop-Build $problems }

Write-Host ''
if ($SkipTests) { Write-Host 'TESTS SKIPPED - not for release' }
Write-Host "version: $version - read back from the manifest and joomla.asset.json in the archive"
Write-Host "built:   $zipPath"
Write-Host "size:    $((Get-Item $zipPath).Length) bytes"
Write-Host "files:   $($entries.Count)"
foreach ($entry in $entries) { Write-Host ('  {0,-56} {1,7} bytes' -f $entry.Name, $entry.Size) }
foreach ($line in $testResults) { Write-Host "checked: $line" }
Write-Host "sha256:  $((Get-FileHash $zipPath -Algorithm SHA256).Hash.ToLower())"
