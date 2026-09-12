# Copy the three live tool folders from the server into this repository.
#
# ASCII only on purpose: Windows PowerShell 5.1 reads .ps1 as ANSI unless the file has a UTF-8 BOM,
# so Cyrillic comments turn into garbage and the script fails to parse.
#
# Config files holding real passwords and API keys are never copied here - they stay on the server
# and are listed in .gitignore. Only *.example.php templates live in the repository.

$ErrorActionPreference = 'Stop'
$repo = $PSScriptRoot

$map = @(
    @{ From = 'C:\TeplouxKassa'; To = 'custom\teplouxkassa' },
    @{ From = 'C:\NodirTool';    To = 'custom\nodirtool'    },
    @{ From = 'C:\BossTool';     To = 'custom\bosstool'     }
)

# Real config files: never copied into the repository.
$secretFiles = @(
    'config.php',
    'config\config.zhomi.php',
    'config\config.turk.php',
    'config\db.local.php'
)

foreach ($m in $map) {
    if (-not (Test-Path $m.From)) {
        Write-Warning ("Not found, skipped: " + $m.From)
        continue
    }

    $dest = Join-Path $repo $m.To

    # The *.example.php templates exist ONLY in the repository, never on the server, and the copy
    # below wipes the destination folder. Without this they would silently disappear from git.
    # Found 10.09.2026 while adding the catalog_edit_price key to the kassa templates.
    $keep = @()
    if (Test-Path $dest) {
        foreach ($f in Get-ChildItem $dest -Recurse -File -Filter '*.example.php') {
            $keep += @{ Rel = $f.FullName.Substring($dest.Length).TrimStart(''); Text = [System.IO.File]::ReadAllBytes($f.FullName) }
        }
        Remove-Item $dest -Recurse -Force
    }
    Copy-Item $m.From $dest -Recurse -Force
    foreach ($k in $keep) {
        $target = Join-Path $dest $k.Rel
        $dir = Split-Path $target -Parent
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        [System.IO.File]::WriteAllBytes($target, $k.Text)
    }

    foreach ($s in $secretFiles) {
        $p = Join-Path $dest $s
        if (Test-Path $p) { Remove-Item $p -Force }
    }

    $n = (Get-ChildItem $dest -Recurse -File).Count
    Write-Host ("{0,-16} -> {1}  ({2} files)" -f $m.From, $m.To, $n)
}

# Safety net: refuse to leave anything that looks like a live API key in the working tree.
# Select-String has no -Recurse in Windows PowerShell 5.1: the previous version of this check
# threw a parameter error AFTER the copy, so the guard never actually ran (found 10.09.2026).
# Enumerate the files first, then scan them.
$phpFiles = Get-ChildItem (Join-Path $repo 'custom') -Recurse -File -Filter '*.php' -ErrorAction SilentlyContinue
$leaks = if ($phpFiles) { $phpFiles | Select-String -Pattern '[0-9a-f]{32,}' -ErrorAction SilentlyContinue } else { $null }
if ($leaks) {
    Write-Host ''
    Write-Warning 'Possible secrets found - review before committing:'
    $leaks | ForEach-Object { Write-Host ("  " + $_.Path + ":" + $_.LineNumber) }
    exit 1
}

Write-Host ''
Write-Host 'Done. Review with: git status'
