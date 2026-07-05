# Pull Laravel .env from production server for local XAMPP / API smoke tests.
# Does NOT commit anything — .env stays gitignored.
#
# Usage:
#   .\scripts\pull-server-env-xboard-api.ps1              # saves .env.server + shows diff hint
#   .\scripts\pull-server-env-xboard-api.ps1 -Apply       # backup local .env, then replace with server copy
param(
    [switch]$Apply,
    [string]$Remote = "server@xdexboard.com",
    [string]$RemotePath = "/var/www/xboard-api/.env"
)

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
$LocalEnv = Join-Path $RepoRoot ".env"
$ServerCopy = Join-Path $RepoRoot ".env.server"
$Example = Join-Path $RepoRoot ".env.example"

if (-not (Get-Command scp.exe -ErrorAction SilentlyContinue)) {
    Write-Error "scp.exe not found (install Windows OpenSSH Client)."
}

Write-Host "== Pull $Remote`:$RemotePath -> $ServerCopy =="
& scp.exe -o ConnectTimeout=30 "${Remote}:${RemotePath}" $ServerCopy
if ($LASTEXITCODE -ne 0) {
    Write-Error @"
scp failed. If you see 'REMOTE HOST IDENTIFICATION HAS CHANGED' for xdexboard.com:
  ssh-keygen -R xdexboard.com
  ssh-keygen -R $(($Remote -split '@')[-1])
Then retry after confirming the new host key with your admin.
"@
}

Write-Host "Saved server .env to .env.server ($( [math]::Round((Get-Item $ServerCopy).Length / 1KB, 1) ) KB)"

if (Test-Path -LiteralPath $LocalEnv) {
    Write-Host "`nLocal .env exists. Compare:"
    Write-Host "  fc .env .env.server"
    if (-not $Apply) {
        Write-Host "`nTo replace local .env (backup first): .\scripts\pull-server-env-xboard-api.ps1 -Apply"
        exit 0
    }
    $backup = Join-Path $RepoRoot (".env.local-backup-" + (Get-Date -Format "yyyyMMdd-HHmmss"))
    Copy-Item -LiteralPath $LocalEnv -Destination $backup
    Write-Host "Backed up local .env -> $(Split-Path -Leaf $backup)"
    Copy-Item -LiteralPath $ServerCopy -Destination $LocalEnv -Force
    Write-Host "Applied .env.server -> .env"
} else {
    Copy-Item -LiteralPath $ServerCopy -Destination $LocalEnv
    Write-Host "Created .env from server copy."
}

Write-Host @"

Next steps for local web/API on this PC:
  1. Review DB_* / APP_URL / mail keys in .env — point DB to your local MySQL if needed.
  2. Keep APP_URL / SANDBOX URLs matching how Apache vhosts resolve (xdexboard.com local smoke).
  3. Web client: .\gradlew :webApp:jsBrowserDevelopmentWebpack -x kotlinStoreYarnLock
     Apache DocumentRoot -> webApp/build/kotlin-webpack/js/developmentExecutable
  4. Never commit .env or .env.server (gitignored).

Reference keys: .env.example
"@
