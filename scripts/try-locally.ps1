# Run the marketplace on a Windows PC for a quick try, with demo data.
#
#   powershell -ExecutionPolicy Bypass -File scripts\try-locally.ps1
#
# Uses SQLite (backend\database\database.sqlite), so no MySQL is needed. Safe to run again:
# an existing backend\.env, frontend\.env or database is kept, and demo data is only added once.
# Needs PHP 8.3+ and Node 22+. Composer is downloaded if it is not installed. To install them:
#   winget install -e --id PHP.PHP.8.3
#   winget install -e --id OpenJS.NodeJS.LTS
# If PHP is missing extensions this app needs (or its code cache, OPcache), a php.ini that turns
# them on is written to .try-local\ and used only by this script; your PHP installation is not changed.
# The site is built once and served as in production, which loads much faster than development mode.
# Set TRY_DEV=1 to run the development server instead (instant reload when you edit the code).
# Stop with Ctrl+C. Local use only: the demo passwords below are public.

$ErrorActionPreference = 'Stop'

$ApiPort = if ($env:API_PORT) { [int]$env:API_PORT } else { 8000 }
$WebPort = if ($env:WEB_PORT) { [int]$env:WEB_PORT } else { 5173 }
$DemoPassword = 'Demo-pass-123'
$AdminEmail = 'admin@demo.local'
$AdminPassword = 'Admin-pass-123!'

$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root 'backend'
$Frontend = Join-Path $Root 'frontend'
$Local = Join-Path $Root '.try-local'
$Helper = Join-Path $PSScriptRoot 'try-locally-helper.php'

function Fail([string]$Message) {
    Write-Host "Error: $Message" -ForegroundColor Red
    exit 1
}
function Step([string]$Message) { Write-Host "`n==> $Message" -ForegroundColor Cyan }
function Invoke-Checked([string]$Exe, [string[]]$Arguments) {
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) { Fail "'$Exe $($Arguments -join ' ')' failed (exit code $LASTEXITCODE)" }
}
# Writes UTF-8 without a byte order mark; Windows PowerShell's Set-Content would add one and break .env.
function Write-Text([string]$Path, [string]$Text) {
    [System.IO.File]::WriteAllText($Path, $Text, (New-Object System.Text.UTF8Encoding $false))
}
function Test-PortInUse([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $Port); return $true } catch { return $false } finally { $client.Dispose() }
}

Step 'Checking tools'
foreach ($tool in 'php', 'node', 'npm.cmd') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        Fail "'$tool' was not found. Install PHP and Node (see the top of this script), then open a new PowerShell window."
    }
}
$phpVersion = (& php -r 'echo PHP_VERSION;') -replace '[^0-9.].*$', ''
if ([version]$phpVersion -lt [version]'8.3.0') { Fail "PHP 8.3 or newer is needed (found $phpVersion)." }
$nodeVersion = [version](& node -p 'process.versions.node')
if (-not ($nodeVersion -ge [version]'22.12.0' -or ($nodeVersion.Major -eq 20 -and $nodeVersion.Minor -ge 19))) {
    Fail "Node 22.12 or newer is needed (found $nodeVersion)."
}
foreach ($port in $ApiPort, $WebPort) {
    if (Test-PortInUse $port) { Fail "port $port is already in use. Close whatever runs there, or set API_PORT / WEB_PORT." }
}
New-Item -ItemType Directory -Force -Path $Local | Out-Null

# PHP from winget or the windows.php.net zip has no php.ini, so pdo_sqlite, openssl and friends are off.
$needed = 'openssl', 'curl', 'mbstring', 'fileinfo', 'gd', 'exif', 'intl', 'pdo_sqlite', 'sqlite3', 'zip'
$required = 'openssl', 'mbstring', 'fileinfo', 'pdo_sqlite'
$loaded = (& php -m) | ForEach-Object { $_.Trim().ToLower() }
$missing = @($needed | Where-Object { $loaded -notcontains $_ })
# Without OPcache, PHP re-reads the whole framework on every request: pages take seconds.
if ($loaded -notcontains 'zend opcache') { $missing += 'opcache' }
if ($missing.Count -gt 0) {
    $phpBinary = (& php -r 'echo PHP_BINARY;')
    $item = Get-Item $phpBinary
    if ($item.LinkType -and $item.Target) { $phpBinary = @($item.Target)[0] }
    $extDir = Join-Path (Split-Path -Parent $phpBinary) 'ext'
    if (-not (Test-Path $extDir)) { Fail "PHP is missing $($missing -join ', ') and its ext folder was not found at $extDir." }
    $lines = @("extension_dir=`"$extDir`"", 'memory_limit=512M', 'realpath_cache_size=4096K', 'realpath_cache_ttl=600')
    foreach ($ext in $needed) {
        if (Test-Path (Join-Path $extDir "php_$ext.dll")) { $lines += "extension=$ext" }
    }
    if (Test-Path (Join-Path $extDir 'php_opcache.dll')) {
        $lines += 'zend_extension=opcache', 'opcache.enable=1', 'opcache.memory_consumption=128', 'opcache.max_accelerated_files=20000'
    }
    Write-Text (Join-Path $Local 'php.ini') (($lines -join "`r`n") + "`r`n")
    $env:PHPRC = $Local
    $env:PHP_INI_SCAN_DIR = ''
    Write-Host "Using .try-local\php.ini to enable: $($missing -join ', ')"
    $loaded = (& php -m) | ForEach-Object { $_.Trim().ToLower() }
}
$stillMissing = @($required | Where-Object { $loaded -notcontains $_ })
if ($stillMissing.Count -gt 0) { Fail "PHP is missing the extension(s): $($stillMissing -join ', ')." }

$composer = Get-Command composer -ErrorAction SilentlyContinue
if ($composer) {
    $composerExe = $composer.Source; $composerArgs = @()
} else {
    $phar = Join-Path $Local 'composer.phar'
    if (-not (Test-Path $phar)) {
        Write-Host 'Composer not found, downloading composer.phar into .try-local'
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -UseBasicParsing -Uri 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $phar
    }
    $composerExe = 'php'; $composerArgs = @($phar)
}

Step 'Backend: installing PHP packages'
Set-Location $Backend
Invoke-Checked $composerExe ($composerArgs + @('install', '--no-interaction', '--no-progress'))

if (-not (Test-Path '.env')) {
    Step 'Backend: writing .env for SQLite'
    $envText = [System.IO.File]::ReadAllText((Join-Path $Backend '.env.example'))
    $envText = $envText -replace '(?m)^DB_CONNECTION=[^\r\n]*', 'DB_CONNECTION=sqlite'
    $envText = $envText -replace '(?m)^(DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=)', '# $1'
    $envText = $envText -replace '(?m)^DEMO_USER_PASSWORD=[^\r\n]*', "DEMO_USER_PASSWORD=$DemoPassword"
    $envText = $envText -replace '(?m)^APP_URL=[^\r\n]*', "APP_URL=http://localhost:$WebPort"
    $envText = $envText -replace '(?m)^CORS_ALLOWED_ORIGINS=[^\r\n]*', "CORS_ALLOWED_ORIGINS=http://localhost:$WebPort"
    $envText = $envText -replace '(?m)^MARKETPLACE_PAYMENT_GATEWAY=[^\r\n]*', 'MARKETPLACE_PAYMENT_GATEWAY=fake'
    Write-Text (Join-Path $Backend '.env') $envText
    Invoke-Checked 'php' @('artisan', 'key:generate', '--no-interaction')
} else {
    Write-Host 'Keeping your existing backend\.env'
}

Step 'Backend: creating tables'
Invoke-Checked 'php' @('artisan', 'migrate', '--force', '--no-interaction')
Invoke-Checked 'php' @('artisan', 'db:seed', '--force', '--no-interaction')
if (-not (Test-Path 'public\storage')) {
    # storage:link needs admin rights or Developer Mode on Windows; a junction does not.
    try { & php artisan storage:link --no-interaction *> $null } catch { }
    if (-not (Test-Path 'public\storage')) {
        New-Item -ItemType Junction -Path 'public\storage' -Target (Join-Path $Backend 'storage\app\public') | Out-Null
    }
}

if ((& php $Helper user-exists customer@demo.local) -ne 'yes') {
    Step 'Backend: adding demo sellers and listings'
    $env:DEMO_USER_PASSWORD = $DemoPassword
    Invoke-Checked 'php' @('artisan', 'db:seed', '--class=DemoSeeder', '--no-interaction')
} else {
    Write-Host 'Demo sellers and listings already there'
    # Older demo data has plain colored pictures; swap them for drawn furniture (demo pictures only).
    Invoke-Checked 'php' @('artisan', 'marketplace:demo-pictures', '--no-interaction')
}
# Smaller copies of product photos for faster pages; only photos without them are processed.
Invoke-Checked 'php' @('artisan', 'marketplace:image-variants', '--no-interaction')
if ((& php $Helper user-exists $AdminEmail) -ne 'yes') {
    Step 'Backend: adding the demo admin'
    Invoke-Checked 'php' @($Helper, 'demo-admin', $AdminEmail, $AdminPassword)
}

Step 'Frontend: installing packages'
Set-Location $Frontend
if (-not (Test-Path '.env')) {
    $feText = [System.IO.File]::ReadAllText((Join-Path $Frontend '.env.example'))
    $feText = $feText -replace '(?m)^VITE_DEV_API_TARGET=[^\r\n]*', "VITE_DEV_API_TARGET=http://127.0.0.1:$ApiPort"
    Write-Text (Join-Path $Frontend '.env') $feText
}
Invoke-Checked 'npm.cmd' @('install', '--no-fund', '--no-audit')
$devMode = $env:TRY_DEV -eq '1'
if (-not $devMode) {
    Step 'Frontend: building the site'
    Invoke-Checked 'npm.cmd' @('run', 'build')
}

Step 'Starting'
# Online payment uses the test payment page locally, unless your .env picks a gateway.
if (-not (Select-String -Path (Join-Path $Backend '.env') -Pattern '^MARKETPLACE_PAYMENT_GATEWAY=' -Quiet)) {
    $env:MARKETPLACE_PAYMENT_GATEWAY = 'fake'
}
# --no-reload keeps PHPRC for the server process; plain "artisan serve" would drop it.
$apiLog = Join-Path $Backend 'storage\logs\serve.log'
$api = Start-Process -FilePath 'php' -ArgumentList @('artisan', 'serve', '--no-reload', "--port=$ApiPort") `
    -WorkingDirectory $Backend -NoNewWindow -PassThru `
    -RedirectStandardOutput $apiLog -RedirectStandardError (Join-Path $Backend 'storage\logs\serve-error.log')
# Sends queued email and SMS alerts (both only logged locally).
$queue = Start-Process -FilePath 'php' -ArgumentList @('artisan', 'queue:work', '--sleep=1', '--tries=3') `
    -WorkingDirectory $Backend -NoNewWindow -PassThru `
    -RedirectStandardOutput (Join-Path $Backend 'storage\logs\queue.log') -RedirectStandardError (Join-Path $Backend 'storage\logs\queue-error.log')
# Cancels online orders that were not paid in time and releases their stock.
$schedule = Start-Process -FilePath 'php' -ArgumentList @('artisan', 'schedule:work') `
    -WorkingDirectory $Backend -NoNewWindow -PassThru `
    -RedirectStandardOutput (Join-Path $Backend 'storage\logs\schedule.log') -RedirectStandardError (Join-Path $Backend 'storage\logs\schedule-error.log')
try {
    Write-Host ''
    Write-Host "  Open http://localhost:$WebPort" -ForegroundColor Green
    Write-Host ''
    Write-Host "  Customer   customer@demo.local     $DemoPassword"
    Write-Host "  Sellers    seller1@demo.local to seller4@demo.local   $DemoPassword"
    Write-Host "  Admin      $AdminEmail        $AdminPassword"
    Write-Host ''
    Write-Host '  Emails are written to backend\storage\logs\laravel.log; text messages show in Admin > Text messages.'
    Write-Host '  Paying online opens a test payment page: no real money moves.'
    Write-Host '  API log: backend\storage\logs\serve.log. Press Ctrl+C to stop.'
    Write-Host ''
    if ($devMode) { & npm.cmd run dev -- --port $WebPort --strictPort }
    else { & npm.cmd run preview -- --port $WebPort --strictPort }
} finally {
    if ($api -and -not $api.HasExited) {
        # artisan serve starts php -S as a child process; stop the whole tree.
        try { & taskkill.exe /PID $api.Id /T /F *> $null } catch { }
    }
    if ($queue -and -not $queue.HasExited) {
        try { & taskkill.exe /PID $queue.Id /T /F *> $null } catch { }
    }
    if ($schedule -and -not $schedule.HasExited) {
        try { & taskkill.exe /PID $schedule.Id /T /F *> $null } catch { }
    }
}
