<#
  start-all.ps1 — boots the whole demo on PHP. No Node involved.

  Three origins, because the trust boundary is the point:
    :3000  the publisher's site   (their CMS + article pages)   THEIRS
    :3001  their S3 image layer   (no CORS headers, by design)  THEIRS
    :3002  our Trace-It service   (mint, serve, composite)      OURS

  Usage:   .\php\demo\start-all.ps1              # local QR generation
           .\php\demo\start-all.ps1 -Stop        # shut the three servers down

  To use the live Trace-It API, set the key in YOUR SHELL first — never in a
  file, and never committed:
      $env:TRACEIT_API_KEY = "sk_live_..."
      $env:TRACEIT_BASE    = "https://<subdomain>.trace-it.io"

  NOTE: `php -S` is a single-threaded development server. It is fine for this
  demo but it serializes requests, so a page with nine thumbnails composites
  them one after another on a cold cache. Do not load-test with it.
#>

[CmdletBinding()]
param(
    [switch]$Stop,
    [int]$CmsPort     = 3000,
    [int]$S3Port      = 3001,
    [int]$TraceItPort = 3002
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)

function Stop-DemoPorts {
    param([int[]]$Ports)
    $stopped = @()
    foreach ($p in $Ports) {
        $conns = Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue
        # NOT $pid — that is a read-only automatic variable in PowerShell and
        # assigning to it throws. It only bites when a port is actually occupied,
        # so it hides on a clean run.
        foreach ($procId in ($conns | Select-Object -ExpandProperty OwningProcess -Unique)) {
            $proc = Get-Process -Id $procId -ErrorAction SilentlyContinue
            if ($proc) {
                Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
                $stopped += "$($proc.ProcessName) (pid $procId) on :$p"
            }
        }
    }
    return $stopped
}

if ($Stop) {
    $s = Stop-DemoPorts -Ports @($CmsPort, $S3Port, $TraceItPort)
    if ($s) { $s | ForEach-Object { Write-Host "  stopped $_" } } else { Write-Host '  nothing was listening' }
    return
}

# Locate PHP. winget installs it outside PATH for the current shell.
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter php.exe -Recurse `
        -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { throw 'php.exe not found. Install with: winget install PHP.PHP.8.4' }

# ext-gd and ext-curl are required: GD draws the composite, cURL fetches the
# source image and talks to the Trace-It API.
$missing = & $php -r "echo implode(',', array_filter(['gd','curl'], fn(`$e) => !extension_loaded(`$e)));"
if ($missing) { throw "PHP is missing required extension(s): $missing. Enable them in $(& $php -r 'echo php_ini_loaded_file();')" }

# Anything already on these ports would make php -S fail with a bind error.
$null = Stop-DemoPorts -Ports @($CmsPort, $S3Port, $TraceItPort)
Start-Sleep -Milliseconds 400

# Load .env if present, so a real API key never has to be pasted into a shell —
# or, worse, into the committed .env.example template. Shell values still win.
$envFile = Join-Path $root '.env'
if (Test-Path $envFile) {
    foreach ($line in Get-Content $envFile) {
        $t = $line.Trim()
        if ($t -eq '' -or $t.StartsWith('#')) { continue }
        $i = $t.IndexOf('=')
        if ($i -lt 1) { continue }
        $k = $t.Substring(0, $i).Trim()
        $v = $t.Substring($i + 1).Trim().Trim('"').Trim("'")
        if ($v -ne '' -and -not [Environment]::GetEnvironmentVariable($k)) {
            [Environment]::SetEnvironmentVariable($k, $v)
        }
    }
    Write-Host "  loaded .env" -ForegroundColor DarkGray
}

# Defaults that make the three origins find each other. Only set what is unset,
# so anything exported in the caller's shell (an API key, a real S3 host) wins.
$defaults = @{
    TRACEIT_ALLOWED_IMAGE_HOSTS = "localhost:$S3Port,127.0.0.1:$S3Port"
    TRACEIT_ALLOWED_ORIGINS     = "http://localhost:$CmsPort,http://127.0.0.1:$CmsPort"
    ARTICLE_URL_TEMPLATE        = "http://localhost:$CmsPort/article/{id}"
    S3_BASE                     = "http://localhost:$S3Port/media"
    TRACEIT_SERVICE             = "http://localhost:$TraceItPort"
    PUBLIC_BASE                 = "http://localhost:$CmsPort"
    TRACEIT_WEBHOOK_SECRET      = 'dev-webhook-secret'
}
foreach ($k in $defaults.Keys) {
    if (-not [Environment]::GetEnvironmentVariable($k)) {
        [Environment]::SetEnvironmentVariable($k, $defaults[$k])
    }
}

$logDir = Join-Path $root 'data/php-logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

function Start-PhpServer {
    param([string]$Name, [int]$Port, [string]$DocRoot, [string]$Router)
    $args = @('-S', "127.0.0.1:$Port", '-t', $DocRoot)
    if ($Router) { $args += $Router }
    $p = Start-Process -FilePath $php -ArgumentList $args -WorkingDirectory $root `
        -RedirectStandardOutput (Join-Path $logDir "$Name.out.log") `
        -RedirectStandardError  (Join-Path $logDir "$Name.err.log") `
        -WindowStyle Hidden -PassThru
    return $p
}

$procs = @(
    Start-PhpServer -Name 's3'      -Port $S3Port      -DocRoot 'public/assets' -Router 'php/demo/router-s3.php'
    Start-PhpServer -Name 'traceit' -Port $TraceItPort -DocRoot '.'             -Router 'php/demo/router-traceit.php'
    Start-PhpServer -Name 'cms'     -Port $CmsPort     -DocRoot 'public'        -Router 'php/demo/router-publisher.php'
)

# Wait for the service to answer rather than guessing at a sleep duration.
# Deliberately 127.0.0.1, not localhost: php -S binds IPv4 only, and on Windows
# "localhost" can resolve to ::1 first. -UseBasicParsing avoids the IE engine
# and its proxy auto-detection, which can stall the first request for seconds.
$ready = $false
for ($i = 0; $i -lt 40; $i++) {
    try {
        $h = Invoke-RestMethod "http://127.0.0.1:$TraceItPort/v1/health" `
            -TimeoutSec 5 -UseBasicParsing
        if ($h.ok) { $ready = $true; break }
    } catch { }
    Start-Sleep -Milliseconds 300
}

$mode = if ($env:TRACEIT_API_KEY) { 'live Trace-It API' } else { 'generated locally (no TRACEIT_API_KEY set)' }

Write-Host ''
Write-Host '  Island Chronicle — Trace-It QR demo, running on PHP' -ForegroundColor Cyan
Write-Host '  ───────────────────────────────────────────────────────────────'
Write-Host "    Publisher site   http://localhost:$CmsPort          (start here)"
Write-Host "    Newsroom         http://localhost:$CmsPort/cms      (publish an article)"
Write-Host "    Their S3         http://localhost:$S3Port/media/  (no CORS, by design)"
Write-Host "    Our service      http://localhost:$TraceItPort          (mint + composite)"
Write-Host ''
Write-Host "    PHP              $(& $php -r 'echo PHP_VERSION;')"
Write-Host "    QR source        $mode"
Write-Host "    Health           $(if ($ready) { 'ready' } else { 'no answer yet - see data/php-logs' })"
Write-Host '  ───────────────────────────────────────────────────────────────'
Write-Host "    logs   $logDir"
Write-Host "    stop   .\php\demo\start-all.ps1 -Stop"
Write-Host ''

# The servers are left running either way — a slow first response is not a
# failure, and killing them would just hide whatever the logs are about to say.
if (-not $ready) {
    Write-Warning 'The service did not answer /v1/health in time. It may still come up; check the logs.'
}
