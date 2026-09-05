# Create IIS site for BossTool (management tool) on port 8012.
# Same pattern as TeplouxKassa (8010) and NodirTool (8011).
#
# NOTE: this file is intentionally ASCII-only. Windows PowerShell 5.1 reads .ps1 files as ANSI
# (cp1251 here) unless they start with a UTF-8 BOM, so Cyrillic text in a UTF-8 script gets mangled
# and breaks parsing. Keeping it English avoids the problem entirely.
#
# Run as Administrator:
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\BossTool\setup_iis.ps1

$ErrorActionPreference = 'Continue'
$out = 'C:\PHPTMP\iis_boss_setup.txt'
if (-not (Test-Path 'C:\PHPTMP')) { New-Item -ItemType Directory 'C:\PHPTMP' | Out-Null }

$appcmd   = "$env:windir\system32\inetsrv\appcmd.exe"
$siteName = 'BossTool'
$path     = 'C:\BossTool'
$port     = 8012

function Log($text) {
    Write-Host $text
    $text | Out-File $out -Append -Encoding utf8
}

"=== BossTool IIS setup ===" | Out-File $out -Encoding utf8
Log "Existing sites before:"
(& $appcmd list site) | ForEach-Object { Log "  $_" }

$found = @(& $appcmd list site /name:"$siteName") | Where-Object { $_ }
if ($found.Count -gt 0) {
    Log ""
    Log "Site '$siteName' already exists - skipping creation."
} else {
    Log ""
    Log "Creating app pool '$siteName'..."
    (& $appcmd add apppool /name:"$siteName") | ForEach-Object { Log "  $_" }
    # No managed code: PHP runs through the FastCGI handler, same as the other two sites.
    (& $appcmd set apppool /apppool.name:"$siteName" /managedRuntimeVersion:) | ForEach-Object { Log "  $_" }

    Log "Creating site '$siteName' on port $port..."
    (& $appcmd add site /name:"$siteName" "/bindings:http/*:${port}:" "/physicalPath:$path") | ForEach-Object { Log "  $_" }
    (& $appcmd set app "$siteName/" /applicationPool:"$siteName") | ForEach-Object { Log "  $_" }
}

Log ""
Log "Granting read/execute on $path to IIS_IUSRS..."
(& icacls $path /grant "IIS_IUSRS:(OI)(CI)(RX)" /T /Q) | ForEach-Object { Log "  $_" }

Log ""
$ruleName = "BossTool $port"
$rule = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
if ($rule) {
    Log "Firewall rule '$ruleName' already exists."
} else {
    Log "Adding firewall rule '$ruleName'..."
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP `
        -LocalPort $port -Action Allow -Profile Any | Out-Null
    Log "  done"
}

Log ""
Log "Starting site..."
(& $appcmd start site /site.name:"$siteName") | ForEach-Object { Log "  $_" }

Log ""
Log "Sites after:"
(& $appcmd list site) | ForEach-Object { Log "  $_" }

Log ""
Log "Checking http://localhost:$port/login.php ..."
try {
    $r = Invoke-WebRequest -Uri "http://localhost:$port/login.php" -UseBasicParsing -TimeoutSec 10
    Log "  HTTP $($r.StatusCode) - OK"
} catch {
    Log "  FAILED: $($_.Exception.Message)"
}

Log ""
Log "DONE. Report saved to $out"
