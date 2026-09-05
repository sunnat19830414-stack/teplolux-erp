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
    if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
    Copy-Item $m.From $dest -Recurse -Force

    foreach ($s in $secretFiles) {
        $p = Join-Path $dest $s
        if (Test-Path $p) { Remove-Item $p -Force }
    }

    $n = (Get-ChildItem $dest -Recurse -File).Count
    Write-Host ("{0,-16} -> {1}  ({2} files)" -f $m.From, $m.To, $n)
}

# Safety net: refuse to leave anything that looks like a live API key in the working tree.
$leaks = Select-String -Path (Join-Path $repo 'custom\*') -Pattern '[0-9a-f]{32,}' `
            -Include '*.php' -Recurse -ErrorAction SilentlyContinue
if ($leaks) {
    Write-Host ''
    Write-Warning 'Possible secrets found - review before committing:'
    $leaks | ForEach-Object { Write-Host ("  " + $_.Path + ":" + $_.LineNumber) }
    exit 1
}

Write-Host ''
Write-Host 'Done. Review with: git status'
